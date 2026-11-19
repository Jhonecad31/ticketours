<?php

namespace App\Modules\Commissions;

use App\Modules\Audit\AuditLog;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class CommissionLedger
{
    public function __construct(private AuditLog $audit) {}

    public function accrue(string $org, string $reservation, iterable $payments, int $actor, string $correlation): void
    {
        $sale = DB::table('agency_sales')->where(['provider_id' => $org, 'reservation_id' => $reservation])->first();
        if ($sale === null) {
            return;
        }

        foreach ($payments as $payment) {
            $amount = BigDecimal::of($payment->amount)->multipliedBy($sale->commission_percent)->withPointMovedLeft(2);
            if ($amount->isPositive()) {
                $this->write($sale, $payment, $amount, 'accrual', null, $actor, $correlation);
            }
        }
    }

    public function reverse(string $org, stdClass $payment, string $refund, ?int $actor, string $correlation): void
    {
        $entry = DB::table('commission_entries')->where(['provider_id' => $org, 'payment_id' => $payment->id, 'type' => 'accrual'])->first();
        if ($entry === null) {
            return;
        }
        $sale = DB::table('agency_sales')->where(['provider_id' => $org, 'id' => $entry->sale_id])->first();
        $this->write($sale, $payment, BigDecimal::of($entry->amount)->negated(), 'reversal', $refund, $actor, $correlation);
    }

    public function lockProvider(string $org): void
    {
        DB::table('organizations')->where('id', $org)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->first();
    }

    public function visible(string $org): Builder
    {
        return DB::table('commission_entries')->where(fn (Builder $query) => $query->where('provider_id', $org)->orWhere('agency_id', $org));
    }

    public function balances(string $org): array
    {
        $amounts = [];
        $paid = DB::table('settlement_items as si')->join('settlements as s', 'si.settlement_id', '=', 's.id')
            ->where('s.status', 'paid')->select('si.commission_entry_id');
        foreach ($this->visible($org)->whereNotIn('id', $paid)->select(['provider_id', 'agency_id', 'currency', 'amount'])->cursor() as $row) {
            $direction = $row->agency_id === $org ? 'receivable' : 'payable';
            $key = $direction.':'.$row->currency;
            $amounts[$key] = ($amounts[$key] ?? BigDecimal::zero())->plus($row->amount);
        }
        ksort($amounts);
        $result = [];
        foreach ($amounts as $key => $amount) {
            [$direction, $currency] = explode(':', $key);
            $result[] = ['direction' => $direction, 'currency' => $currency, 'amount' => (string) $amount->strippedOfTrailingZeros()];
        }

        return $result;
    }

    private function write(stdClass $sale, stdClass $payment, BigDecimal $amount, string $type, ?string $refund, ?int $actor, string $correlation): void
    {
        $id = (string) Str::uuid();
        $this->lockProvider($sale->provider_id);
        $inserted = DB::table('commission_entries')->insertOrIgnore([
            'id' => $id, 'provider_id' => $sale->provider_id, 'agency_id' => $sale->agency_id,
            'sale_id' => $sale->id, 'payment_id' => $payment->id, 'refund_id' => $refund,
            'type' => $type, 'currency' => $payment->currency, 'amount' => (string) $amount->strippedOfTrailingZeros(), 'recorded_at' => now(),
        ]);
        if ($inserted) {
            foreach ([$sale->provider_id, $sale->agency_id] as $org) {
                $this->audit->write($org, $actor, "commission.$type", 'commission', $id, $correlation);
            }
        }
    }
}
