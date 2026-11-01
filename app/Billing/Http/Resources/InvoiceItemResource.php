<?php

declare(strict_types=1);

namespace App\Billing\Http\Resources;

use App\Billing\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var InvoiceItem $item */
        $item = $this->resource;

        return [
            'id' => $item->uuid,
            'product' => $this->whenLoaded('product', fn () => ProductResource::make($item->product)),
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
        ];
    }
}
