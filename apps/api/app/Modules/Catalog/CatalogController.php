<?php

namespace App\Modules\Catalog;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogController
{
    public function __construct(private Catalog $catalog, private OrganizationAccess $access, private AuditLog $audit) {}

    public function index(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.read');
        $data = $request->validate(['status' => ['sometimes', Rule::enum(ProductStatus::class)]]);
        $query = $this->catalog->products($org);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return PaginatedJson::from($request, $query, ['id', 'name', 'status']);
    }

    public function show(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.read');

        return response()->json($this->catalog->product($org, $id));
    }

    public function store(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.write');
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $product = ['id' => (string) Str::uuid(), 'name' => $data['name'], 'status' => ProductStatus::Draft->value];

        DB::transaction(function () use ($request, $org, $product): void {
            DB::table('products')->insert(['organization_id' => $org, ...$product]);
            $this->audit->record($request, $org, $request->user()->id, 'product.created', 'product', $product['id']);
        });

        return response()->json($product, 201);
    }

    public function update(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.write');
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $product = DB::transaction(function () use ($request, $org, $id, $data) {
            $product = $this->catalog->product($org, $id, lock: true);
            $this->catalog->assertEditable($product);
            $this->catalog->products($org)->where('id', $id)->update($data);
            $this->audit->record($request, $org, $request->user()->id, 'product.updated', 'product', $id);
            $product->name = $data['name'];

            return $product;
        });

        return response()->json($product);
    }

    public function changeStatus(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.publish');
        $data = $request->validate(['status' => ['required', Rule::enum(ProductStatus::class)]]);
        $next = ProductStatus::from($data['status']);

        $product = DB::transaction(function () use ($request, $org, $id, $next) {
            $product = $this->catalog->product($org, $id, lock: true);
            $current = ProductStatus::from($product->status);
            if ($current === $next) {
                return $product;
            }
            abort_unless($current->canMoveTo($next), 409, 'INVALID_PRODUCT_TRANSITION');

            if ($next === ProductStatus::Published) {
                $this->assertReady($org, $id);
            }

            $this->catalog->products($org)->where('id', $id)->update(['status' => $next->value]);
            $this->audit->record($request, $org, $request->user()->id, 'product.'.$next->value, 'product', $id);
            $product->status = $next->value;

            return $product;
        });

        return response()->json($product);
    }

    public function variants(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.read');
        $data = $request->validate(['product_id' => ['sometimes', 'uuid']]);
        $query = $this->catalog->variants($org);
        if (isset($data['product_id'])) {
            $this->catalog->product($org, $data['product_id']);
            $query->where('product_id', $data['product_id']);
        }

        return PaginatedJson::from($request, $query, ['id', 'product_id', 'name', 'modality']);
    }

    public function createVariant(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'catalog.write');
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'modality' => ['required', Rule::in(['session', 'exclusive_resource'])],
        ]);
        $variant = ['id' => (string) Str::uuid(), ...$data];

        DB::transaction(function () use ($request, $org, $variant): void {
            $product = $this->catalog->product($org, $variant['product_id'], lock: true);
            $this->catalog->assertEditable($product);
            abort_if($product->status === ProductStatus::Published->value, 409, 'INVALID_PRODUCT_TRANSITION');
            DB::table('product_variants')->insert(['organization_id' => $org, ...$variant]);
            $this->audit->record($request, $org, $request->user()->id, 'variant.created', 'variant', $variant['id']);
        });

        return response()->json($variant, 201);
    }

    private function assertReady(string $org, string $id): void
    {
        $variants = $this->catalog->variants($org)->where('product_id', $id)->get(['id', 'modality']);
        $hasPolicy = DB::table('product_policy_versions')->where(['organization_id' => $org, 'product_id' => $id])->exists();
        abort_if($variants->isEmpty() || ! $hasPolicy, 409, 'PRODUCT_NOT_READY');

        $priced = DB::table('base_rate_versions')->where('organization_id', $org)->whereIn('variant_id', $variants->pluck('id'))
            ->distinct()->pluck('variant_id');
        $now = now()->utc()->toDateTimeString();
        foreach (['session' => 'inventory_sessions', 'exclusive_resource' => 'resource_windows'] as $modality => $table) {
            $required = $variants->where('modality', $modality)->pluck('id');
            $configured = DB::table($table)->where('organization_id', $org)->whereIn('variant_id', $required)
                ->where($modality === 'session' ? 'start_at' : 'end_at', $modality === 'session' ? '>=' : '>', $now)->distinct()->pluck('variant_id');
            abort_if($required->diff($configured)->isNotEmpty(), 409, 'PRODUCT_NOT_READY');
        }

        abort_if($variants->pluck('id')->diff($priced)->isNotEmpty(), 409, 'PRODUCT_NOT_READY');
    }
}
