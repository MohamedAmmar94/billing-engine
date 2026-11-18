<?php

declare(strict_types=1);

use App\Payments\Gateway\CheckoutSessionRequestDTO;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Gateway\Fake\FakeGateway;
use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGatewayInterface;
use App\Payments\Gateway\Stripe\StripeGateway;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function gatewaySessionRequest(): CheckoutSessionRequestDTO
{
    return new CheckoutSessionRequestDTO(
        amount: Money::of(20406, CurrencyEnum::USD),
        description: 'Invoice INV-000001',
        clientReferenceId: '5f0c1a2e-0000-4000-8000-000000000001',
        idempotencyKey: 'invoice-abc-link-1',
        expiresAt: CarbonImmutable::createFromTimestamp(1_900_000_000),
        successUrl: 'https://app.test/success',
        cancelUrl: 'https://app.test/cancel',
    );
}

/** @param  array<string, mixed>  $overrides */
function gatewaySessionBody(array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_test_1',
        'object' => 'checkout.session',
        'status' => 'open',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
        'expires_at' => 1_900_000_000,
    ], $overrides);
}

function gatewayError(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $error) {
        return $error;
    }

    throw new RuntimeException('Expected the call to throw.');
}

beforeEach(function () {
    config([
        'payments.stripe.secret' => 'sk_test_unit',
        'payments.stripe.api_version' => '2026-09-30.endive',
        'payments.stripe.base_url' => 'https://api.stripe.test',
    ]);

    $this->gateway = new StripeGateway;
});

it('creates a checkout session with exactly the request Stripe expects', function () {
    Http::fake(['api.stripe.test/*' => Http::response(gatewaySessionBody(), 200)]);

    $session = $this->gateway->createCheckoutSession(gatewaySessionRequest());

    $sent = Http::recorded()->first()[0];
    $data = $sent->data();

    expect($sent->method())->toBe('POST')
        ->and($sent->url())->toBe('https://api.stripe.test/v1/checkout/sessions')
        ->and($sent->isForm())->toBeTrue()
        ->and($sent->header('Authorization'))->toBe(['Bearer sk_test_unit'])
        ->and($sent->header('Idempotency-Key'))->toBe(['invoice-abc-link-1'])
        ->and($sent->header('Stripe-Version'))->toBe(['2026-09-30.endive'])
        ->and($data['mode'])->toBe('payment')
        ->and($data['client_reference_id'])->toBe('5f0c1a2e-0000-4000-8000-000000000001')
        ->and((string) $data['expires_at'])->toBe('1900000000')
        ->and($data['success_url'])->toBe('https://app.test/success')
        ->and($data['cancel_url'])->toBe('https://app.test/cancel')
        ->and((string) data_get($data, 'line_items.0.quantity'))->toBe('1')
        ->and((string) data_get($data, 'line_items.0.price_data.unit_amount'))->toBe('20406')
        ->and(data_get($data, 'line_items.0.price_data.currency'))->toBe('usd')
        ->and(data_get($data, 'line_items.0.price_data.product_data.name'))->toBe('Invoice INV-000001')
    // كارت بس، من الكود: المعامل القديم اتشال من Stripe
    // ->and(data_get($data, 'allowed_payment_method_types.0'))->toBe('card')
        ->and(array_key_exists('payment_method_types', $data))->toBeFalse();

    expect($session->id)->toBe('cs_test_1')
        ->and($session->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_1')
        ->and($session->status)->toBe(CheckoutSessionStatusEnum::Open)
        ->and($session->expiresAt->getTimestamp())->toBe(1_900_000_000)
        ->and($session->raw['id'])->toBe('cs_test_1');
});

it('retrieves and expires a session on the right endpoints without an idempotency key', function () {
    Http::fake([
        'api.stripe.test/v1/checkout/sessions/cs_test_1/expire' => Http::response(gatewaySessionBody(['status' => 'expired', 'url' => null]), 200),
        'api.stripe.test/v1/checkout/sessions/cs_test_1' => Http::response(gatewaySessionBody(['status' => 'complete', 'url' => null]), 200),
    ]);

    $retrieved = $this->gateway->retrieveCheckoutSession('cs_test_1');
    $this->gateway->expireCheckoutSession('cs_test_1');

    [$get, $post] = Http::recorded()->map(fn (array $pair) => $pair[0])->all();

    expect($get->method())->toBe('GET')
        ->and($get->url())->toBe('https://api.stripe.test/v1/checkout/sessions/cs_test_1')
        ->and($get->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($post->method())->toBe('POST')
        ->and($post->url())->toBe('https://api.stripe.test/v1/checkout/sessions/cs_test_1/expire')
        ->and($retrieved->status)->toBe(CheckoutSessionStatusEnum::Complete)
        ->and($retrieved->url)->toBeNull();
});

it('treats 5xx and 429 as an unknown outcome, so the caller retries with the same key', function (int $status) {
    Http::fake(['api.stripe.test/*' => Http::response(['error' => ['message' => 'try later']], $status)]);

    expect(fn () => $this->gateway->createCheckoutSession(gatewaySessionRequest()))
        ->toThrow(GatewayTimeoutException::class);
})->with([500, 502, 503, 429]);

it('treats a lost connection as an unknown outcome', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    expect(fn () => $this->gateway->createCheckoutSession(gatewaySessionRequest()))
        ->toThrow(GatewayTimeoutException::class, 'could not be reached');
});

it('treats other 4xx answers as final rejections', function (int $status) {
    Http::fake(['api.stripe.test/*' => Http::response(['error' => ['message' => 'Invalid request']], $status)]);

    $error = gatewayError(fn () => $this->gateway->createCheckoutSession(gatewaySessionRequest()));

    expect($error)->toBeInstanceOf(GatewayException::class)
        ->and($error)->not->toBeInstanceOf(GatewayTimeoutException::class)
        ->and($error->getMessage())->toContain("({$status})");
})->with([400, 401, 402, 404, 409]);

it('refuses to call Stripe without a secret key and sends nothing', function () {
    config(['payments.stripe.secret' => '']);
    Http::fake();

    expect(fn () => $this->gateway->retrieveCheckoutSession('cs_test_1'))
        ->toThrow(GatewayException::class, 'not configured');

    Http::assertNothingSent();
});

it('omits the Stripe-Version header when no version is pinned', function () {
    config(['payments.stripe.api_version' => '']);
    Http::fake(['api.stripe.test/*' => Http::response(gatewaySessionBody(), 200)]);

    $this->gateway->retrieveCheckoutSession('cs_test_1');

    expect(Http::recorded()->first()[0]->hasHeader('Stripe-Version'))->toBeFalse();
});

it('selects the gateway from configuration', function (string $name, string $class) {
    config(['payments.gateway' => $name]);
    app()->forgetInstance(PaymentGatewayInterface::class);

    expect(app(PaymentGatewayInterface::class))->toBeInstanceOf($class);
})->with([
    'stripe' => ['stripe', StripeGateway::class],
    'fake' => ['fake', FakeGateway::class],
]);

it('refuses an unknown gateway name', function () {
    config(['payments.gateway' => 'paypal']);
    app()->forgetInstance(PaymentGatewayInterface::class);

    app(PaymentGatewayInterface::class);
})->throws(InvalidArgumentException::class);
