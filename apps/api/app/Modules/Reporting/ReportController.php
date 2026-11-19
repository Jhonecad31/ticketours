<?php

namespace App\Modules\Reporting;

use App\Modules\Inventory\TimeRange;
use App\Modules\Organizations\OrganizationAccess;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController
{
    public function __invoke(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'reports.read');
        $range = TimeRange::read($request, 'from', 'to');
        $history = $this->window(DB::table('reservation_history')->where('organization_id', $org), 'occurred_at', $range)
            ->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status');
        $checkins = $this->window(DB::table('ticket_checkins')->where('organization_id', $org), 'recorded_at', $range)->count();
        $payments = $this->window(DB::table('payments')->where('organization_id', $org), 'recorded_at', $range);
        $refunds = $this->window(DB::table('refunds')->where('organization_id', $org), 'recorded_at', $range);
        $sales = $this->window(DB::table('agency_sales as a')->where('a.agency_id', $org)->join('reservation_history as h', function ($join): void {
            $join->on('a.reservation_id', '=', 'h.reservation_id')->on('a.provider_id', '=', 'h.organization_id');
        })->where('h.status', 'held'), 'h.occurred_at', $range)->count();

        $commission = DB::table('commission_entries')->where(fn ($q) => $q->where('provider_id', $org)->orWhere('agency_id', $org));
        $commission = $this->window($commission, 'recorded_at', $range);
        $paid = DB::table('settlements')->where('status', 'paid');
        $paid = $this->window($paid, 'paid_at', $range);

        return response()->json([
            'from' => TimeRange::utc($range->start), 'to' => TimeRange::utc($range->end),
            'time_zone' => DB::table('organizations')->where('id', $org)->value('time_zone'),
            'reservations' => [
                'created' => (int) $history->get('held', 0), 'confirmed' => (int) $history->get('confirmed', 0),
                'cancelled' => (int) $history->get('cancelled', 0), 'expired' => (int) $history->get('expired', 0),
            ],
            'checkins' => $checkins, 'agency_sales_created' => $sales,
            'payments' => $this->totals($payments), 'refunds' => $this->totals($refunds),
            'commissions_receivable' => $this->totals((clone $commission)->where('agency_id', $org)),
            'commissions_payable' => $this->totals((clone $commission)->where('provider_id', $org)),
            'settlements_received' => $this->totals((clone $paid)->where('agency_id', $org), 'total'),
            'settlements_paid' => $this->totals((clone $paid)->where('provider_id', $org), 'total'),
        ]);
    }

    private function window(Builder $query, string $column, TimeRange $range): Builder
    {
        return $query->where($column, '>=', $range->start)->where($column, '<', $range->end);
    }

    private function totals(Builder $query, string $amount = 'amount'): array
    {
        $totals = [];
        foreach ($query->select('currency', $amount)->cursor() as $row) {
            $totals[$row->currency] = ($totals[$row->currency] ?? BigDecimal::zero())->plus($row->$amount);
        }
        ksort($totals);
        $items = [];
        foreach ($totals as $currency => $value) {
            $items[] = ['currency' => $currency, 'amount' => (string) $value->strippedOfTrailingZeros()];
        }

        return $items;
    }
}
