<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Models\Customer;
use App\Billing\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => 'password'],
        );

        $customers = [
            'billing@acme.test' => 'Acme Corp',
            'ap@globex.test' => 'Globex',
            'pay@initech.test' => 'Initech',
        ];

        foreach ($customers as $email => $name) {
            Customer::query()->firstOrCreate(['email' => $email], ['name' => $name]);
        }

        $products = [
            ['SKU-STARTER', 'Starter Plan', 2900],
            ['SKU-PRO', 'Pro Plan', 9900],
            ['SKU-SETUP', 'Setup Fee', 5000],
            ['SKU-SEAT', 'Extra Seat', 1500],
            ['SKU-SUPPORT', 'Support Pack', 19900],
        ];

        foreach ($products as [$sku, $name, $price]) {
            Product::query()->firstOrCreate(['sku' => $sku], ['name' => $name, 'unit_price' => $price]);
        }
    }
}
