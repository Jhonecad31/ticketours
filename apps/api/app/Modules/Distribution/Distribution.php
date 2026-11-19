<?php

namespace App\Modules\Distribution;

use App\Modules\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class Distribution
{
    public function agreement(string $org, string $id, bool $lock = false): stdClass
    {
        $query = DB::table('agreements')->where('id', $id)
            ->where(fn ($q) => $q->where('provider_id', $org)->orWhere('agency_id', $org));
        $agreement = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($agreement === null, 404);

        return $agreement;
    }

    public function context(string $agency, string $id, bool $lock = false): array
    {
        $offer = DB::table('distribution_offers')->where('id', $id)->first();
        abort_if($offer === null, 404);
        $agreement = $this->agreement($agency, $offer->agreement_id, $lock);
        abort_unless($agreement->agency_id === $agency && $agreement->status === 'active', 404);
        if ($lock) {
            $offer = DB::table('distribution_offers')->where('id', $id)->first();
        }
        abort_unless($offer->enabled, 404);

        return [
            'id' => $offer->id, 'agency_id' => $agency, 'provider_id' => $agreement->provider_id,
            'agreement_id' => $agreement->id, 'variant_id' => $offer->variant_id,
            'rate_version_id' => $offer->rate_version_id, 'commission_percent' => $agreement->commission_percent,
        ];
    }

    public function lock(array $context): void
    {
        $this->context($context['agency_id'], $context['id'], lock: true);
    }

    public function operationalCustomer(array $context, string $id): string
    {
        $customer = DB::table('customers')->where(['id' => $id, 'organization_id' => $context['agency_id'], 'is_agency_guest' => false])->first(['name']);
        abort_if($customer === null, 404);
        $projection = (string) Str::uuid();
        DB::table('customers')->insert([
            'organization_id' => $context['provider_id'], 'id' => $projection, 'name' => $customer->name, 'is_agency_guest' => true,
        ]);

        return $projection;
    }

    public function attribute(Request $request, array $context, string $reservation, string $customer, AuditLog $audit): void
    {
        $id = (string) Str::uuid();
        DB::table('agency_sales')->insert([
            'id' => $id, 'provider_id' => $context['provider_id'], 'agency_id' => $context['agency_id'],
            'agreement_id' => $context['agreement_id'], 'offer_id' => $context['id'],
            'reservation_id' => $reservation, 'agency_customer_id' => $customer, 'commission_percent' => $context['commission_percent'],
        ]);
        foreach ([$context['provider_id'], $context['agency_id']] as $org) {
            $audit->record($request, $org, $request->user()->id, 'distribution.sale_created', 'agency_sale', $id);
        }
    }
}
