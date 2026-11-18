<?php

declare(strict_types=1);

use App\Billing\Models\Invoice;
use App\Payments\Models\PaymentLink;
use App\Webhooks\Jobs\ProcessWebhookEventJob;
use App\Webhooks\Models\WebhookEvent;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function webhookSecret(): string
{
    return (string) config()->array('payments.stripe.webhook_secrets')[0];
}

/** @param  array<string, mixed>  $object */
function stripeEvent(string $type, array $object, ?string $id = null): array
{
    return [
        'id' => $id ?? 'evt_'.Str::random(24),
        'object' => 'event',
        'type' => $type,
        'created' => now()->getTimestamp(),
        'livemode' => false,
        'data' => ['object' => $object],
    ];
}

function completedEvent(Invoice $invoice, PaymentLink $link, array $overrides = [], ?string $id = null): array
{
    return stripeEvent('checkout.session.completed', array_merge([
        'id' => $link->stripe_session_id,
        'object' => 'checkout.session',
        'client_reference_id' => $link->uuid,
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => $invoice->total,
        'currency' => strtolower($invoice->currency),
        'payment_intent' => 'pi_'.Str::random(24),
    ], $overrides), $id);
}

function expiredEvent(PaymentLink $link): array
{
    return stripeEvent('checkout.session.expired', [
        'id' => $link->stripe_session_id,
        'object' => 'checkout.session',
        'client_reference_id' => $link->uuid,
        'status' => 'expired',
        'payment_status' => 'unpaid',
    ]);
}

function stripeSignatureHeader(string $body, ?int $timestamp = null, ?string $secret = null): string
{
    $timestamp ??= now()->getTimestamp();

    return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? webhookSecret());
}

function sendWebhook(string $body, ?string $signatureHeader = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signatureHeader !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $signatureHeader;
    }

    return test()->call('POST', '/api/webhooks/stripe', [], [], [], $server, $body);
}

function deliverWebhook(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return sendWebhook($body, stripeSignatureHeader($body));
}

function storedEvent(string $eventId): WebhookEvent
{
    return WebhookEvent::query()->where('event_id', $eventId)->firstOrFail();
}

/** بيشغّل الـ job بإيدينا (بدل الـ queue) عشان نتحكم في كل محاولة. */
function runWebhookJob(WebhookEvent $event): void
{
    app()->call([new ProcessWebhookEventJob($event->id), 'handle']);
}

function openLinkFor(Invoice $invoice): PaymentLink
{
    test()->get(payUrl($invoice))->assertRedirect();

    return linkOf($invoice);
}
