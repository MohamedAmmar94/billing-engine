<?php

declare(strict_types=1);

namespace App\Payments\Gateway\Stripe;

use App\Payments\Gateway\CheckoutSessionDTO;
use App\Payments\Gateway\CheckoutSessionRequestDTO;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGatewayInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Stripe عبر HTTP مباشر (ADR 0008): الـ timeouts والـ idempotency والأخطاء ظاهرة هنا. */
final class StripeGateway implements PaymentGatewayInterface
{
    public function createCheckoutSession(CheckoutSessionRequestDTO $request): CheckoutSessionDTO
    {
        $response = $this->send(fn (): Response => $this->client($request->idempotencyKey)->post('/v1/checkout/sessions', [
            'mode' => 'payment',
            // 'payment_method_types' => ['card'],
            'client_reference_id' => $request->clientReferenceId,
            'expires_at' => $request->expiresAt->getTimestamp(),
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->currency->value),
                    'unit_amount' => $request->amount->amount,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
        ]));

        return $this->toSession($response);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionDTO
    {
        $path = '/v1/checkout/sessions/'.rawurlencode($sessionId);

        return $this->toSession($this->send(fn (): Response => $this->client()->get($path)));
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $path = '/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire';

        $this->send(fn (): Response => $this->client()->post($path));
    }

    private function client(?string $idempotencyKey = null): PendingRequest
    {
        $secret = config()->string('payments.stripe.secret');

        if ($secret === '') {
            throw new GatewayException('The Stripe secret key is not configured.');
        }

        $client = Http::baseUrl(config()->string('payments.stripe.base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->asForm()
            ->connectTimeout(config()->integer('payments.stripe.connect_timeout'))
            ->timeout(config()->integer('payments.stripe.timeout'));

        $version = config()->string('payments.stripe.api_version');

        if ($version !== '') {
            $client = $client->withHeaders(['Stripe-Version' => $version]);
        }

        if ($idempotencyKey !== null) {
            $client = $client->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $client;
    }

    /**
     * الشبكة وقعت أو 5xx أو 429: النتيجة مجهولة، نعيد بنفس المفتاح.
     * أي 4xx تاني: رفض نهائي.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new GatewayTimeoutException('Stripe could not be reached: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $message = (string) $response->json('error.message', 'Unknown Stripe error');

        if ($status === 429 || $status >= 500) {
            throw new GatewayTimeoutException("Stripe answered {$status}: {$message}");
        }

        throw new GatewayException("Stripe rejected the request ({$status}): {$message}");
    }

    private function toSession(Response $response): CheckoutSessionDTO
    {
        /** @var array<string, mixed> $data */
        $data = $response->json();

        return new CheckoutSessionDTO(
            id: (string) $data['id'],
            url: isset($data['url']) ? (string) $data['url'] : null,
            status: CheckoutSessionStatusEnum::from((string) $data['status']),
            expiresAt: CarbonImmutable::createFromTimestamp((int) $data['expires_at']),
            raw: $data,
        );
    }
}
