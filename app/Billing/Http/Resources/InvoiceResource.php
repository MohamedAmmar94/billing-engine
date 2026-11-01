<?php

declare(strict_types=1);

namespace App\Billing\Http\Resources;

use App\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceResource extends JsonResource
{
    /**
     * المبالغ أعداد صحيحة بالوحدة الصغرى (سنت). ومفيش أي id داخلي بيخرج.
     */
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /**
         * المبالغ أعداد صحيحة بالوحدة الصغرى (سنت). ومفيش أي id داخلي بيخرج.
         *
         * @return array<string, mixed>
         */
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'id' => $invoice->uuid,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'tax_rate_bp' => $invoice->tax_rate_bp,
            'tax_amount' => $invoice->tax_amount,
            'total' => $invoice->total,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'customer' => $this->whenLoaded('customer', fn () => CustomerResource::make($invoice->customer)),
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $invoice->creator->uuid,
                'name' => $invoice->creator->name,
            ]),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
