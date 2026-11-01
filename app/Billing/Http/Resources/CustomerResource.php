<?php

declare(strict_types=1);

namespace App\Billing\Http\Resources;

use App\Billing\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $customer->uuid,
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}
