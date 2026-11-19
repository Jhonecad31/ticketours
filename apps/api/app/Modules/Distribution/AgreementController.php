<?php

namespace App\Modules\Distribution;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Catalog\Catalog;
use App\Modules\Organizations\OrganizationAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgreementController
{
    public function __construct(private OrganizationAccess $access, private Distribution $distribution, private AuditLog $audit) {}

    public function index(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');
        $query = DB::table('agreements')->where(fn ($q) => $q->where('provider_id', $org)->orWhere('agency_id', $org));

        return PaginatedJson::from($request, $query, ['id', 'agency_id', 'provider_id', 'status', 'commission_percent']);
    }

    public function store(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');
        $data = $request->validate(['agency_id' => ['required', 'uuid'], 'commission_percent' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D']]);
        $agency = strtolower($data['agency_id']);
        $percent = BigDecimal::of($data['commission_percent']);
        abort_if($agency === $org || $percent->isGreaterThan(100), 422);
        abort_unless(DB::table('organizations')->where('id', $agency)->exists(), 404);
        $row = ['id' => (string) Str::uuid(), 'provider_id' => $org, 'agency_id' => $agency, 'commission_percent' => (string) $percent, 'status' => 'proposed'];

        DB::transaction(function () use ($request, $row): void {
            DB::table('agreements')->insert($row);
            $this->auditParties($request, (object) $row, 'agreement.proposed', 'agreement', $row['id']);
        });

        return response()->json($row, 201);
    }

    public function transition(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['proposed', 'active', 'suspended', 'terminated'])]]);
        $next = $data['status'];

        return response()->json(DB::transaction(function () use ($request, $org, $id, $next): object {
            $agreement = $this->distribution->agreement($org, $id, lock: true);
            $current = $agreement->status;
            if ($next === $current) {
                return $agreement;
            }
            $allowed = match ($next) {
                'active' => $current === 'proposed' && $agreement->agency_id === $org,
                'suspended' => $current === 'active',
                'proposed' => $current === 'suspended' && $agreement->provider_id === $org,
                'terminated' => $current !== 'terminated',
            };
            abort_unless($allowed, 409, 'INVALID_STATE_TRANSITION');
            DB::table('agreements')->where('id', $id)->update(['status' => $next]);
            $this->auditParties($request, $agreement, "agreement.$next", 'agreement', $id);
            $agreement->status = $next;

            return $agreement;
        }));
    }

    public function publish(Request $request, string $org, string $id, Catalog $catalog): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');
        $data = $request->validate(['variant_id' => ['required', 'uuid'], 'rate_version_id' => ['required', 'uuid']]);
        $offer = DB::transaction(function () use ($request, $org, $id, $data, $catalog): array {
            $agreement = $this->distribution->agreement($org, $id, lock: true);
            abort_unless($agreement->provider_id === $org, 404);
            abort_unless($agreement->status === 'active', 409, 'INVALID_STATE_TRANSITION');
            $variant = $catalog->variant($org, $data['variant_id']);
            abort_unless($catalog->product($org, $variant->product_id, lock: true)->status === 'published', 404);
            $rate = DB::table('base_rate_versions')->where(['organization_id' => $org, 'variant_id' => $variant->id, 'id' => $data['rate_version_id']])->first();
            abort_if($rate === null, 404);

            $offer = ['id' => (string) Str::uuid(), 'provider_id' => $org, 'agreement_id' => $id, 'variant_id' => $variant->id, 'rate_version_id' => $rate->id, 'enabled' => true];
            DB::table('distribution_offers')->insert($offer);
            $this->auditParties($request, $agreement, 'distribution.offer_published', 'distribution_offer', $offer['id']);

            return $offer;
        });

        return response()->json($offer, 201);
    }

    public function changeOffer(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'distribution.manage');
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(DB::transaction(function () use ($request, $org, $id, $data): object {
            $query = DB::table('distribution_offers')->where(['provider_id' => $org, 'id' => $id]);
            $offer = $query->first();
            abort_if($offer === null, 404);
            $agreement = $this->distribution->agreement($org, $offer->agreement_id, lock: true);
            $enabled = (bool) $data['enabled'];
            abort_if($enabled && $agreement->status !== 'active', 409, 'INVALID_STATE_TRANSITION');
            $query->update(['enabled' => $enabled]);
            $this->auditParties($request, $agreement, $enabled ? 'distribution.offer_enabled' : 'distribution.offer_disabled', 'distribution_offer', $id);
            $offer->enabled = $enabled;

            return $offer;
        }));
    }

    private function auditParties(Request $request, object $agreement, string $action, string $entity, string $id): void
    {
        foreach ([$agreement->provider_id, $agreement->agency_id] as $org) {
            $this->audit->record($request, $org, $request->user()->id, $action, $entity, $id);
        }
    }
}
