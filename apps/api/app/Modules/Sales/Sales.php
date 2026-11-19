<?php

namespace App\Modules\Sales;

use App\Modules\Audit\AuditLog;
use App\Modules\Commissions\CommissionLedger;
use App\Modules\Operations\TicketIssuer;
use App\Modules\Payments\SimulatedPayments;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class Sales
{
    public function __construct(private AuditLog $audit, private SimulatedPayments $simulator, private CommissionLedger $commission, private TicketIssuer $tickets) {}

    public function open(string $org, string $reservation, object $quote, bool $partial, int $actor, string $correlation): void
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'organization_id' => $org, 'id' => $id, 'reservation_id' => $reservation,
            'total' => $quote->total, 'currency' => $quote->currency, 'allows_partial_payment' => $partial, 'status' => 'open',
        ]);
        $this->audit->write($org, $actor, 'order.created', 'order', $id, $correlation);
    }

    public function order(string $org, string $reservation): stdClass
    {
        $order = DB::table('orders')->where(['organization_id' => $org, 'reservation_id' => $reservation])->first();
        abort_if($order === null, 404);

        return $order;
    }

    public function summary(string $org, stdClass $order): array
    {
        $payments = $this->payments($org, $order->id);
        $captured = $this->sum($payments->pluck('amount'));
        $refunded = $this->sum(DB::table('refunds')->where('organization_id', $org)->whereIn('payment_id', $payments->select('id'))->pluck('amount'));
        $paid = $captured->minus($refunded);
        $total = BigDecimal::of($order->total);
        $closed = in_array($order->status, ['cancelled', 'expired'], true);
        $state = match (true) {
            $refunded->isPositive() => 'refunded',
            $paid->isEqualTo($total) => 'paid',
            $paid->isPositive() => 'partial',
            default => 'pending',
        };

        return [
            'id' => $order->id, 'reservation_id' => $order->reservation_id, 'status' => $order->status,
            'total' => $order->total, 'currency' => $order->currency, 'allows_partial_payment' => (bool) $order->allows_partial_payment,
            'payment_status' => $state, 'paid' => (string) $paid, 'refunded' => (string) $refunded,
            'balance' => $closed ? '0' : (string) $total->minus($paid),
            'voucher_available' => $order->status === 'completed' && DB::table('vouchers')->where(['organization_id' => $org, 'order_id' => $order->id])->exists(),
        ];
    }

    public function capture(string $org, stdClass $order, string $amount, int $actor, string $correlation): array
    {
        $decimal = BigDecimal::of($amount);
        abort_if(! $decimal->isPositive() || $decimal->isGreaterThan($this->summary($org, $order)['balance']), 409, 'PAYMENT_AMOUNT_INVALID');
        $id = (string) Str::uuid();
        DB::table('payments')->insert([
            'organization_id' => $org, 'order_id' => $order->id, 'id' => $id, 'amount' => $amount,
            'currency' => $order->currency, 'simulator_reference' => $this->simulator->approve(), 'recorded_at' => now(),
        ]);
        $this->audit->write($org, $actor, 'payment.simulated', 'payment', $id, $correlation);
        if ($order->status === 'completed' && $this->eligible($org, $order)) {
            $this->commission->accrue($org, $order->reservation_id, [(object) ['id' => $id, 'amount' => $amount, 'currency' => $order->currency]], $actor, $correlation);
            $this->issue($org, $order, $actor, $correlation);
        }

        return ['payment_id' => $id, 'order' => $this->summary($org, $order)];
    }

    public function complete(string $org, string $reservation, int $actor, string $correlation): void
    {
        $order = $this->order($org, $reservation);
        abort_unless($this->eligible($org, $order), 409, 'PAYMENT_REQUIRED');
        $this->status($org, $order, 'completed', $actor, $correlation);
        $this->commission->accrue($org, $reservation, $this->payments($org, $order->id)->get(), $actor, $correlation);
        $this->issue($org, $order, $actor, $correlation);
    }

    public function close(string $org, string $reservation, string $status, ?int $actor, string $correlation): void
    {
        $order = $this->order($org, $reservation);
        foreach ($this->payments($org, $order->id)->get() as $payment) {
            $id = (string) Str::uuid();
            DB::table('refunds')->insert([
                'organization_id' => $org, 'id' => $id, 'payment_id' => $payment->id, 'amount' => $payment->amount,
                'currency' => $payment->currency, 'simulator_reference' => $this->simulator->approve(), 'recorded_at' => now(),
            ]);
            $this->audit->write($org, $actor, 'refund.simulated', 'refund', $id, $correlation);
            $this->commission->reverse($org, $payment, $id, $actor, $correlation);
        }

        $this->status($org, $order, $status, $actor, $correlation);
    }

    private function eligible(string $org, stdClass $order): bool
    {
        $paid = $this->sum($this->payments($org, $order->id)->pluck('amount'));

        return ($order->allows_partial_payment ? $paid->multipliedBy(2) : $paid)->isGreaterThanOrEqualTo($order->total);
    }

    private function issue(string $org, stdClass $order, int $actor, string $correlation): void
    {
        $key = ['organization_id' => $org, 'order_id' => $order->id];
        if (DB::table('vouchers')->where($key)->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('vouchers')->insert([...$key, 'id' => $id, 'issued_at' => now()]);
        $this->audit->write($org, $actor, 'voucher.issued', 'voucher', $id, $correlation);
        $this->tickets->issue($org, $order->reservation_id, $actor, $correlation);
    }

    private function status(string $org, stdClass $order, string $status, ?int $actor, string $correlation): void
    {
        DB::table('orders')->where(['organization_id' => $org, 'id' => $order->id])->update(['status' => $status]);
        $this->audit->write($org, $actor, "order.$status", 'order', $order->id, $correlation);
    }

    private function payments(string $org, string $order): Builder
    {
        return DB::table('payments')->where(['organization_id' => $org, 'order_id' => $order]);
    }

    private function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }
}
