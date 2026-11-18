<?php

declare(strict_types=1);

use App\Payments\Gateway\CheckoutSessionRequestDTO;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Gateway\Stripe\StripeGateway;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('talks to real Stripe test mode: one session per idempotency key, then retrieve and expire', function () {
    config([
        'payments.stripe.secret' => (string) env('STRIPE_LIVE_SECRET'),
        'payments.stripe.base_url' => 'https://api.stripe.com',
        'payments.stripe.api_version' => (string) env('STRIPE_API_VERSION', ''),
    ]);

    $gateway = new StripeGateway;
    $request = new CheckoutSessionRequestDTO(
        amount: Money::of(5000, CurrencyEnum::USD),
        description: 'Billing Engine live test',
        clientReferenceId: (string) Str::uuid(),
        idempotencyKey: 'live-test-'.Str::uuid(),
        expiresAt: CarbonImmutable::now()->addHour(),
        successUrl: 'https://example.com/success',
        cancelUrl: 'https://example.com/cancel',
    );

    $first = $gateway->createCheckoutSession($request);
    $again = $gateway->createCheckoutSession($request);

    expect($first->id)->toStartWith('cs_test_')
        ->and($again->id)->toBe($first->id) // نفس المفتاح = نفس الـ session، وده اللي التصميم كله معتمد عليه
        ->and($first->status)->toBe(CheckoutSessionStatusEnum::Open)
        ->and($first->url)->toStartWith('https://checkout.stripe.com/')
        ->and($gateway->retrieveCheckoutSession($first->id)->status)->toBe(CheckoutSessionStatusEnum::Open);

    $gateway->expireCheckoutSession($first->id);

    expect($gateway->retrieveCheckoutSession($first->id)->status)->toBe(CheckoutSessionStatusEnum::Expired);
})->group('stripe')->skip(
    fn (): bool => ! str_starts_with((string) env('STRIPE_LIVE_SECRET'), 'sk_test_'),
    'Set STRIPE_LIVE_SECRET (an sk_test_ key) in .env to run the live Stripe test.',
);
