<?php

declare(strict_types=1);

namespace App\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'customer' => ['required', 'uuid', 'exists:customers,uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product' => ['required', 'uuid', 'exists:products,uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
