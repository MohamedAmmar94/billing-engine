<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
});

it('requires a token on every business endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/customers'],
    ['POST', '/api/v1/customers'],
    ['GET', '/api/v1/products'],
    ['GET', '/api/v1/invoices'],
    ['POST', '/api/v1/invoices'],
    ['POST', '/api/v1/invoices/00000000-0000-4000-8000-000000000000/open'],
]);

it('issues a token for valid staff credentials and revokes it on logout', function () {
    $staff = User::factory()->create(['email' => 'staff@example.com']);

    $token = $this->postJson('/api/v1/auth/token', [
        'email' => 'staff@example.com',
        'password' => 'password',
        'device_name' => 'tests',
    ])->assertCreated()->json('token');

    $this->withToken($token)->getJson('/api/v1/customers')->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($staff->tokens()->count())->toBe(0);
});

it('rejects wrong credentials', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => 'staff@example.com',
        'password' => 'wrong-password',
        'device_name' => 'tests',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('walks the staff journey and never leaks database ids', function () {
    $staff = makeStaff();
    Sanctum::actingAs($staff);

    $customer = $this->postJson('/api/v1/customers', [
        'name' => 'Acme Corp',
        'email' => 'billing@acme.test',
    ])->assertCreated()->json('data');

    $products = collect([['SKU-PRO', 'Pro Plan', 9900], ['SKU-SEAT', 'Extra Seat', 1500], ['SKU-SETUP', 'Setup Fee', 5000]])
        ->map(fn (array $p) => $this->postJson('/api/v1/products', [
            'sku' => $p[0], 'name' => $p[1], 'unit_price' => $p[2],
        ])->assertCreated()->json('data'))
        ->values();

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer' => $customer['id'],
        'items' => [
            ['product' => $products[0]['id'], 'quantity' => 1],
            ['product' => $products[1]['id'], 'quantity' => 2],
            ['product' => $products[2]['id'], 'quantity' => 1],
        ],
    ])->assertCreated()->json('data');

    expect($invoice['status'])->toBe('draft')
        ->and($invoice['number'])->toBeNull()
        ->and($invoice['total'])->toBe(20406)
        ->and($invoice['items'])->toHaveCount(3)
        ->and($invoice['customer']['id'])->toBe($customer['id'])
        ->and($invoice['created_by']['id'])->toBe($staff->uuid)
        ->and(Str::isUuid($invoice['id']))->toBeTrue();

    // مفيش أي رقم داخلي (bigint) بيخرج من الـ API
    $exposed = collect(array_keys($invoice))
        ->merge(array_keys($invoice['items'][0]))
        ->filter(fn (string $key): bool => str_ends_with($key, '_id'));
    expect($exposed)->toBeEmpty();

    $opened = $this->postJson("/api/v1/invoices/{$invoice['id']}/open")->assertOk()->json('data');

    expect($opened['status'])->toBe('open')
        ->and($opened['number'])->toBe('INV-000001')
        ->and($opened['due_at'])->not->toBeNull();

    // تغيير سعر المنتج بعد كده ما بيأثرش على الفاتورة
    $this->patchJson("/api/v1/products/{$products[0]['id']}", ['unit_price' => 20000])->assertOk();
    $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertOk()->assertJsonPath('data.total', 20406);

    $this->getJson('/api/v1/invoices?status=open')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/invoices?status=nonsense')->assertUnprocessable();

    $this->postJson("/api/v1/invoices/{$invoice['id']}/void")
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.number', 'INV-000001');
});

it('rejects invalid input with 422', function () {
    Sanctum::actingAs(makeStaff());
    $customer = makeCustomer();
    $inactive = makeProduct(active: false);
    $active = makeProduct();

    $this->postJson('/api/v1/customers', ['name' => 'Dup', 'email' => $customer->email])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->postJson('/api/v1/invoices', ['customer' => $customer->uuid, 'items' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('items');

    $this->postJson('/api/v1/invoices', [
        'customer' => $customer->uuid,
        'items' => [['product' => $active->uuid, 'quantity' => 0]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

    $this->postJson('/api/v1/invoices', [
        'customer' => (string) Str::uuid(),
        'items' => [['product' => $active->uuid, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('customer');

    // قاعدة بيزنس مش validation: المنتج موجود بس مش نشط
    $this->postJson('/api/v1/invoices', [
        'customer' => $customer->uuid,
        'items' => [['product' => $inactive->uuid, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonPath('message', "Product {$inactive->sku} is not active.");
});

it('answers 409 when the invoice is in the wrong state', function () {
    Sanctum::actingAs(makeStaff());
    $draft = draftInvoice();

    $this->postJson("/api/v1/invoices/{$draft->uuid}/void")->assertConflict();

    $this->postJson("/api/v1/invoices/{$draft->uuid}/open")->assertOk();
    $this->postJson("/api/v1/invoices/{$draft->uuid}/open")->assertConflict();
    $this->deleteJson("/api/v1/invoices/{$draft->uuid}")->assertConflict();
});

it('answers 404 for unknown ids, malformed ids and database ids', function (string $id) {
    Sanctum::actingAs(makeStaff());

    $this->getJson("/api/v1/invoices/{$id}")->assertNotFound();
})->with([
    'unknown uuid' => '00000000-0000-4000-8000-000000000000',
    'not a uuid' => 'abc',
    'a database id' => '1',
]);
