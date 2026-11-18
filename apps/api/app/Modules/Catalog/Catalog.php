<?php

namespace App\Modules\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class Catalog
{
    public function products(string $org): Builder
    {
        return DB::table('products')->where('organization_id', $org);
    }

    public function variants(string $org): Builder
    {
        return DB::table('product_variants')->where('organization_id', $org);
    }

    public function product(string $org, string $id, bool $lock = false): stdClass
    {
        $query = $this->products($org)->where('id', $id);
        $product = ($lock ? $query->lockForUpdate() : $query)->first(['id', 'name', 'status']);
        abort_if($product === null, 404);

        return $product;
    }

    public function variant(string $org, string $id): stdClass
    {
        $variant = $this->variants($org)->where('id', $id)->first(['id', 'product_id', 'name', 'modality']);
        abort_if($variant === null, 404);

        return $variant;
    }

    public function assertEditable(stdClass $product): void
    {
        abort_if($product->status === ProductStatus::Archived->value, 409, 'INVALID_PRODUCT_TRANSITION');
    }
}
