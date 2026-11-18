<?php

namespace App\Modules\Inventory;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Catalog\Catalog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController
{
    public function __construct(private Catalog $catalog, private OrganizationAccess $access, private AuditLog $audit) {}

    public function sessions(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.read');
        $data = $request->validate([
            'variant_id' => ['sometimes', 'uuid'],
            'from' => ['required_with:to', 'sometimes', TimeRange::TIMESTAMP],
            'to' => ['required_with:from', 'sometimes', TimeRange::TIMESTAMP],
        ]);
        $query = DB::table('inventory_sessions')->where('organization_id', $org);
        if (isset($data['variant_id'])) {
            $this->catalog->variant($org, $data['variant_id']);
            $query->where('variant_id', $data['variant_id']);
        }
        if (isset($data['from']) || isset($data['to'])) {
            $range = TimeRange::read($request, 'from', 'to');
            $query->where('start_at', '>=', $range->start)->where('end_at', '<=', $range->end);
        }

        return PaginatedJson::from($request, $query, ['id', 'variant_id', 'start_at', 'end_at', 'capacity'], self::presentInterval(...));
    }

    public function createSession(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.write');
        $data = $request->validate(['variant_id' => ['required', 'uuid'], 'capacity' => ['required', 'integer', 'between:1,2147483647']]);
        $data['capacity'] = (int) $data['capacity'];
        $range = TimeRange::read($request);
        $variant = $this->catalog->variant($org, $data['variant_id']);
        abort_unless($variant->modality === 'session', 409, 'INVALID_INVENTORY_MODALITY');

        return $this->store($request, $org, 'inventory_sessions', 'session', [...$data, 'start_at' => $range->start, 'end_at' => $range->end], $variant->product_id);
    }

    public function resources(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.read');

        return PaginatedJson::from($request, DB::table('resources')->where('organization_id', $org), ['id', 'name']);
    }

    public function createResource(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.write');
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        return $this->store($request, $org, 'resources', 'resource', $data);
    }

    public function createWindow(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.write');
        $data = $request->validate(['variant_id' => ['required', 'uuid']]);
        $variant = $this->catalog->variant($org, $data['variant_id']);
        abort_unless($variant->modality === 'exclusive_resource', 409, 'INVALID_INVENTORY_MODALITY');
        $this->assertResource($org, $id);
        $range = TimeRange::read($request);

        return $this->store($request, $org, 'resource_windows', 'resource_window', [
            'resource_id' => $id, 'variant_id' => $variant->id, 'start_at' => $range->start, 'end_at' => $range->end,
        ], $variant->product_id);
    }

    public function createBlock(Request $request, string $org, string $id, InventoryClaims $claims): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'inventory.write');
        $this->assertResource($org, $id);
        $range = TimeRange::read($request);

        return DB::transaction(function () use ($request, $org, $id, $range, $claims): JsonResponse {
            $claims->target($org, null, $id, lock: true);
            $claims->assertResourceFree($org, $id, $range->start, $range->end);

            return $this->store($request, $org, 'resource_blocks', 'resource_block', [
                'resource_id' => $id, 'start_at' => $range->start, 'end_at' => $range->end,
            ]);
        });
    }

    private function assertResource(string $org, string $id): void
    {
        abort_unless(DB::table('resources')->where(['organization_id' => $org, 'id' => $id])->exists(), 404);
    }

    private function store(Request $request, string $org, string $table, string $entity, array $data, ?string $productId = null): JsonResponse
    {
        $record = ['id' => (string) Str::uuid(), ...$data];
        DB::transaction(function () use ($request, $org, $table, $entity, $record, $productId): void {
            if ($productId !== null) {
                $this->catalog->assertEditable($this->catalog->product($org, $productId, lock: true));
            }
            DB::table($table)->insert(['organization_id' => $org, ...$record]);
            $this->audit->record($request, $org, $request->user()->id, "$entity.created", $entity, $record['id']);
        });

        return response()->json(isset($record['start_at']) ? self::presentInterval((object) $record) : $record, 201);
    }

    private static function presentInterval(object $record): array
    {
        return [...(array) $record, 'start_at' => TimeRange::utc($record->start_at), 'end_at' => TimeRange::utc($record->end_at)];
    }
}
