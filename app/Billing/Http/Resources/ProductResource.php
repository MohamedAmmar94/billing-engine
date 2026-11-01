<?php

declare(strict_types=1);

namespace App\Billing\Http\Resources;

use App\Billing\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->uuid,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit_price' => $product->unit_price,
            'currency' => $product->currency,
            'is_active' => $product->is_active,
        ];
    }
}
