<?php

namespace App\Modules\Inventory;

use App\Modules\Catalog\Catalog;
use App\Modules\Catalog\ProductStatus;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityController
{
    public function __construct(private InventoryClaims $claims) {}

    public function __invoke(Request $request, string $org, Catalog $catalog, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'inventory.read');
        $data = $request->validate(['variant_id' => ['required', 'uuid'], 'resource_id' => ['sometimes', 'uuid']]);
        $range = TimeRange::read($request, 'from', 'to');
        $variant = $catalog->variant($org, $data['variant_id']);
        $product = $catalog->product($org, $variant->product_id);
        abort_unless($product->status === ProductStatus::Published->value, 404);
        $resource = $data['resource_id'] ?? null;

        if ($resource !== null && $variant->modality === 'session') {
            throw ValidationException::withMessages(['resource_id' => 'El filtro se aplica a recursos exclusivos.']);
        }

        $range = new TimeRange(max($range->start, now()->utc()->toDateTimeString()), $range->end);
        $items = $variant->modality === 'session'
            ? $this->sessionItems($org, $variant->id, $range)
            : $this->resourceItems($org, $variant->id, $range, $resource);

        return response()->json([
            'variant_id' => $variant->id,
            'time_zone' => DB::table('organizations')->where('id', $org)->value('time_zone'),
            'items' => $items,
        ]);
    }

    private function sessionItems(string $org, string $variant, TimeRange $range): array
    {
        $sessions = DB::table('inventory_sessions')->where(['organization_id' => $org, 'variant_id' => $variant])
            ->where('start_at', '>=', $range->start)->where('end_at', '<=', $range->end)
            ->orderBy('start_at')->orderBy('id')->get();
        $used = $this->claims->active($org)->whereIn('session_id', $sessions->pluck('id'))
            ->selectRaw('session_id, SUM(quantity) AS reserved')->groupBy('session_id')->pluck('reserved', 'session_id');

        return $sessions->map(fn ($row) => [
            'session_id' => $row->id,
            'start_at' => TimeRange::utc($row->start_at),
            'end_at' => TimeRange::utc($row->end_at),
            'available_quantity' => max(0, (int) $row->capacity - (int) $used->get($row->id, 0)),
        ])->all();
    }

    private function resourceItems(string $org, string $variant, TimeRange $range, ?string $resource): array
    {
        if ($resource !== null) {
            abort_unless(DB::table('resources')->where(['organization_id' => $org, 'id' => $resource])->exists(), 404);
        }

        if ($range->start >= $range->end) {
            return [];
        }

        $windows = DB::table('resource_windows')->where(['organization_id' => $org, 'variant_id' => $variant])
            ->when($resource !== null, fn ($query) => $query->where('resource_id', $resource))
            ->where('start_at', '<', $range->end)->where('end_at', '>', $range->start)
            ->orderBy('resource_id')->orderBy('start_at')->get()->groupBy('resource_id');
        $allocated = $this->claims->active($org)->whereIn('resource_id', $windows->keys())
            ->where('start_at', '<', $range->end)->where('end_at', '>', $range->start)->select(['resource_id', 'start_at', 'end_at']);
        $blocks = DB::table('resource_blocks')->where('organization_id', $org)->whereIn('resource_id', $windows->keys())
            ->where('start_at', '<', $range->end)->where('end_at', '>', $range->start)->select(['resource_id', 'start_at', 'end_at'])
            ->unionAll($allocated)->orderBy('start_at')->get()->groupBy('resource_id');

        $items = [];
        foreach ($windows as $id => $rows) {
            $intervals = $this->mergeWindows($rows, $range);
            $busy = $blocks->get($id, collect())->map(fn ($row) => [TimeRange::utc($row->start_at), TimeRange::utc($row->end_at)]);
            foreach ($intervals as [$start, $end]) {
                foreach ($busy as [$blockStart, $blockEnd]) {
                    if ($blockEnd <= $start) {
                        continue;
                    }
                    if ($blockStart >= $end) {
                        break;
                    }
                    if ($blockStart > $start) {
                        $items[] = ['resource_id' => $id, 'start_at' => $start, 'end_at' => $blockStart];
                    }
                    $start = max($start, $blockEnd);
                    if ($start >= $end) {
                        break;
                    }
                }
                if ($start < $end) {
                    $items[] = ['resource_id' => $id, 'start_at' => $start, 'end_at' => $end];
                }
            }
        }

        return $items;
    }

    private function mergeWindows(iterable $rows, TimeRange $range): array
    {
        $intervals = [];
        $from = TimeRange::utc($range->start);
        $to = TimeRange::utc($range->end);
        foreach ($rows as $row) {
            $start = max($from, TimeRange::utc($row->start_at));
            $end = min($to, TimeRange::utc($row->end_at));
            $last = array_key_last($intervals);
            if ($last !== null && $intervals[$last][1] >= $start) {
                $intervals[$last][1] = max($intervals[$last][1], $end);
            } else {
                $intervals[] = [$start, $end];
            }
        }

        return $intervals;
    }
}
