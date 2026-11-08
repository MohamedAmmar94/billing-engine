<?php

declare(strict_types=1);

namespace App\Webhooks\Console;

use App\Payments\Gateway\Fake\FakeGateway;
use App\Payments\Gateway\PaymentGatewayInterface;
use App\Payments\Models\PaymentLink;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SimulateStripeWebhookCommand extends Command
{
    protected $signature = 'stripe:simulate-webhook {link : UUID of an open payment link}';

    protected $description = 'Complete a fake checkout session and deliver a signed checkout.session.completed webhook';

    public function handle(PaymentGatewayInterface $gateway, Kernel $kernel): int
    {
        if (! $gateway instanceof FakeGateway) {
            $this->error('This works with the fake gateway only. With Stripe, use the Stripe CLI and a test card.');

            return self::FAILURE;
        }

        $secret = config()->array('payments.stripe.webhook_secrets')[0] ?? null;

        if (! is_string($secret) || $secret === '') {
            $this->error('Set STRIPE_WEBHOOK_SECRET to any value (for example whsec_local) first.');

            return self::FAILURE;
        }

        $uuid = (string) $this->argument('link');
        $link = Str::isUuid($uuid) ? PaymentLink::query()->where('uuid', $uuid)->with('invoice')->first() : null;

        if ($link === null || $link->stripe_session_id === null) {
            $this->error('No payment link with a Stripe session was found.');

            return self::FAILURE;
        }

        $invoice = $link->invoice;
        $gateway->complete($link->stripe_session_id);

        $body = json_encode([
            'id' => 'evt_sim_'.Str::random(24),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => $link->stripe_session_id,
                'object' => 'checkout.session',
                'client_reference_id' => $link->uuid,
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => $invoice->total,
                'currency' => strtolower($invoice->currency),
                'payment_intent' => 'pi_sim_'.Str::random(24),
            ]],
        ], JSON_THROW_ON_ERROR);

        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $request = Request::create('/api/webhooks/stripe', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);

        $response = $kernel->handle($request);

        $this->info('Webhook delivered: HTTP '.$response->getStatusCode().'. The queue worker will process it.');

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }
}
