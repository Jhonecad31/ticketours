<?php

namespace App\Modules\Pricing;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Catalog\Catalog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PricingController
{
    public function __construct(private Catalog $catalog, private OrganizationAccess $access, private AuditLog $audit) {}

    public function rates(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'pricing.read');
        $data = $request->validate(['variant_id' => ['required', 'uuid']]);
        $this->catalog->variant($org, $data['variant_id']);

        return PaginatedJson::from($request, DB::table('base_rate_versions')->where(['organization_id' => $org, ...$data])->orderByDesc('version'), ['id', 'variant_id', 'version', 'amount', 'currency']);
    }

    public function createRate(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'pricing.write');
        $data = $request->validate([
            'variant_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
        ]);
        $variant = $this->catalog->variant($org, $data['variant_id']);
        $record = $this->version($request, $org, $variant->product_id, 'base_rate_versions', ['variant_id' => $variant->id], $data, 'rate');

        return response()->json($record, 201);
    }

    public function policies(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'pricing.read');
        $this->catalog->product($org, $id);

        return PaginatedJson::from($request, DB::table('product_policy_versions')->where(['organization_id' => $org, 'product_id' => $id])->orderByDesc('version'), ['id', 'product_id', 'version', 'terms']);
    }

    public function createPolicy(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'pricing.write');
        $data = $request->validate(['terms' => ['required', 'string', 'max:5000']]);
        $key = ['product_id' => $id];
        $record = $this->version($request, $org, $id, 'product_policy_versions', $key, [...$key, ...$data], 'policy');

        return response()->json($record, 201);
    }

    private function version(Request $request, string $org, string $product, string $table, array $key, array $data, string $type): array
    {
        return DB::transaction(function () use ($request, $org, $product, $table, $key, $data, $type): array {
            $this->catalog->assertEditable($this->catalog->product($org, $product, lock: true));
            $version = DB::table($table)->where(['organization_id' => $org, ...$key])->max('version') + 1;
            $record = ['id' => (string) Str::uuid(), ...$data, 'version' => $version];
            DB::table($table)->insert(['organization_id' => $org, ...$record]);
            $this->audit->record($request, $org, $request->user()->id, "$type.version_created", $type, $record['id']);

            return $record;
        });
    }
}
