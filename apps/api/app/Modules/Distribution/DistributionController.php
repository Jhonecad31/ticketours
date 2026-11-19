<?php

namespace App\Modules\Distribution;

use App\Http\PaginatedJson;
use App\Modules\Catalog\Catalog;
use App\Modules\Commissions\CommissionLedger;
use App\Modules\Inventory\AvailabilityController;
use App\Modules\Inventory\TimeRange;
use App\Modules\Organizations\OrganizationAccess;
use App\Modules\Reservations\HoldController;
use App\Modules\Reservations\ReservationController;
use App\Modules\Reservations\ReservationRecords;
use App\Modules\Sales\Sales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistributionController
{
    public function __construct(private OrganizationAccess $access, private Distribution $distribution) {}

    public function offers(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.book');
        $query = DB::table('distribution_offers as o')
            ->join('agreements as a', 'o.agreement_id', '=', 'a.id')
            ->join('product_variants as v', 'o.variant_id', '=', 'v.id')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->join('base_rate_versions as r', 'o.rate_version_id', '=', 'r.id')
            ->where(fn ($q) => $q->where('a.provider_id', $org)->orWhere(fn ($q) => $q
                ->where('a.agency_id', $org)->where('a.status', 'active')->where('o.enabled', true)->where('p.status', 'published')))
            ->select('o.id', 'o.provider_id', 'a.agency_id', 'o.agreement_id', 'o.variant_id', 'o.rate_version_id', 'o.enabled', 'p.name as product_name', 'v.name as variant_name', 'v.modality', 'r.amount', 'r.currency');

        return PaginatedJson::from($request, DB::query()->fromSub($query, 'visible_offers'), ['*'], fn ($row) => [...(array) $row, 'enabled' => (bool) $row->enabled]);
    }

    public function availability(Request $request, string $org, string $id, AvailabilityController $inventory, Catalog $catalog): JsonResponse
    {
        $context = $this->authorizeOffer($request, $org, $id);

        return $inventory($request, $context['provider_id'], $catalog, $this->access, $context);
    }

    public function quote(Request $request, string $org, string $id, HoldController $holds): JsonResponse
    {
        $context = $this->authorizeOffer($request, $org, $id);

        return $holds->quote($request, $context['provider_id'], $context);
    }

    public function hold(Request $request, string $org, string $id, HoldController $holds): JsonResponse
    {
        $context = $this->authorizeOffer($request, $org, $id);

        return $holds->store($request, $context['provider_id'], $context);
    }

    public function release(Request $request, string $org, string $id, string $hold, HoldController $holds): JsonResponse
    {
        $context = $this->authorizeOffer($request, $org, $id);

        return $holds->release($request, $context['provider_id'], $hold, $context);
    }

    public function reserve(Request $request, string $org, string $id, ReservationController $reservations): JsonResponse
    {
        $context = $this->authorizeOffer($request, $org, $id);

        return $reservations->store($request, $context['provider_id'], $context);
    }

    public function sales(Request $request, string $org, Sales $sales, ReservationRecords $records): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.book');
        $query = DB::table('agency_sales')->where(fn ($q) => $q->where('agency_id', $org)->orWhere('provider_id', $org));

        return PaginatedJson::from($request, $query, ['id', 'agency_id', 'provider_id', 'offer_id', 'reservation_id', 'commission_percent'], function (object $entry) use ($sales, $records, $request): array {
            return DB::transaction(function () use ($entry, $sales, $records, $request): array {
                $reservation = $records->get('reservations', $entry->provider_id, $entry->reservation_id);
                $hold = $records->lockHold($entry->provider_id, $reservation->hold_id);
                $records->expire($entry->provider_id, $hold, $request);
                $reservation = $records->get('reservations', $entry->provider_id, $entry->reservation_id);

                $summary = $sales->summary($entry->provider_id, $sales->order($entry->provider_id, $entry->reservation_id));
                $allowed = array_flip(['total', 'currency', 'paid', 'refunded', 'balance', 'status', 'payment_status']);

                return [...(array) $entry, 'reservation_status' => $reservation->status, 'order' => array_intersect_key($summary, $allowed)];
            });
        });
    }

    public function commissions(Request $request, string $org, CommissionLedger $ledger): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');

        return PaginatedJson::from($request, $ledger->visible($org), ['id', 'provider_id', 'agency_id', 'sale_id', 'type', 'amount', 'currency', 'recorded_at'], fn ($row) => [...(array) $row, 'recorded_at' => TimeRange::utc($row->recorded_at)]);
    }

    public function balances(Request $request, string $org, CommissionLedger $ledger): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');

        return response()->json(['items' => $ledger->balances($org)]);
    }

    private function authorizeOffer(Request $request, string $org, string $id): array
    {
        $this->access->authorize($request->user(), $org, 'distribution.book');

        return $this->distribution->context($org, $id);
    }
}
