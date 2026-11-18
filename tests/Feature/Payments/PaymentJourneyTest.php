<?php

declare(strict_types=1);

use App\Payments\Models\Payment;
use App\Payments\Models\PaymentLink;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Models\WebhookEvent;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->seed(SequenceSeeder::class);
    Sanctum::actingAs(makeStaff());
});

it('takes an invoice from creation to paid through the whole system', function () {
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Acme Corp', 'email' => 'billing@acme.test'])
        ->assertCreated()->json('data');
    $product = $this->postJson('/api/v1/products', ['sku' => 'SKU-PRO', 'name' => 'Pro Plan', 'unit_price' => 9900])
        ->assertCreated()->json('data');

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer' => $customer['id'],
        'items' => [['product' => $product['id'], 'quantity' => 1]],
    ], idem())->assertCreated()->json('data');

    $opened = $this->postJson("/api/v1/invoices/{$invoice['id']}/open", [], idem())->assertOk()->json('data');

    expect($opened['pay_url'])->not->toBeNull();

    // العميل بيفتح الرابط الثابت ويتحول لـ Stripe (هنا الـ fake)
    $this->get($opened['pay_url'])->assertRedirect();
    $this->get(route('payment.success', ['invoice' => $invoice['id']]))->assertSee('Confirming your payment');

    // Stripe بيأكد الدفع بـ webhook موقّع
    $this->artisan('stripe:simulate-webhook', ['link' => PaymentLink::query()->firstOrFail()->uuid])->assertSuccessful();

    $this->getJson("/api/v1/invoices/{$invoice['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.pay_url', null);

    $this->get(route('payment.success', ['invoice' => $invoice['id']]))->assertSee('Payment received');
    $this->get($opened['pay_url'])->assertOk()->assertJsonPath('status', 'already_paid');

    expect(Payment::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatusEnum::Processed);
});

it('refuses to simulate a webhook for an unknown link', function () {
    $this->artisan('stripe:simulate-webhook', ['link' => (string) Str::uuid()])->assertFailed();
});

it('refuses to simulate a webhook without a signing secret', function () {
    config(['payments.stripe.webhook_secrets' => []]);

    $this->artisan('stripe:simulate-webhook', ['link' => (string) Str::uuid()])->assertFailed();
});
