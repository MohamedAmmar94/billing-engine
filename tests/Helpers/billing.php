<?php

declare(strict_types=1);

use App\Billing\Actions\CreateInvoice;
use App\Billing\Models\Customer;
use App\Billing\Models\Invoice;
use App\Billing\Models\Product;
use App\Models\User;

function makeStaff(): User
{
    return User::factory()->create();
}

function makeCustomer(): Customer
{
    return Customer::query()->create([
        'name' => fake()->company(),
        'email' => fake()->unique()->safeEmail(),
    ]);
}

function makeProduct(int $unitPrice = 10000, bool $active = true): Product
{
    return Product::query()->create([
        'sku' => 'SKU-'.fake()->unique()->numerify('######'),
        'name' => fake()->words(2, true),
        'unit_price' => $unitPrice,
        'currency' => 'USD',
        'is_active' => $active,
    ]);
}

function draftInvoice(int $unitPrice = 10000): Invoice
{
    return app(CreateInvoice::class)->handle(
        makeCustomer(),
        makeStaff(),
        [['product' => makeProduct($unitPrice), 'quantity' => 1]],
    );
}
