<?php

declare(strict_types=1);

namespace App\Webhooks\Http\Controllers;

use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Exceptions\InvalidWebhookSignatureException;
use App\Webhooks\Jobs\ProcessWebhookEventJob;
use App\Webhooks\Services\StripeSignatureVerifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StripeWebhookController
{
    public function __invoke(Request $request, StripeSignatureVerifierService $verifier): JsonResponse
    {
        // الـ body الخام: أي إعادة تشفير JSON بتبوّظ التوقيع
        $payload = $request->getContent();

        /** @var list<string> $secrets */
        $secrets = array_values(array_map('strval', config()->array('payments.stripe.webhook_secrets')));

        try {
            $verifier->verify(
                $payload,
                (string) $request->header('Stripe-Signature', ''),
                now()->getTimestamp(),
                $secrets,
                config()->integer('payments.stripe.webhook_tolerance'),
            );
        } catch (InvalidWebhookSignatureException $e) {
            Log::warning('Stripe webhook rejected', ['reason' => $e->getMessage()]);

            return response()->json(['code' => 'invalid_signature', 'message' => 'The signature is invalid.'], 400);
        }

        $event = json_decode($payload, true);
        $eventId = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['code' => 'invalid_payload', 'message' => 'The event payload is invalid.'], 400);
        }

        // INSERT ... ON CONFLICT DO NOTHING: الـ UNIQUE (provider, event_id) هو اللي بيمنع التكرار
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'provider' => 'stripe',
            'event_id' => $eventId,
            'type' => $type,
            'payload' => $payload,
            'status' => WebhookEventStatusEnum::Received->value,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('webhook_events')->where('provider', 'stripe')->where('event_id', $eventId)->first();

        // لو الـ event مخزّن قبل كده لكن لسه ما اتعالجش (الـ queue وقعت وقتها): نعيد إرساله، والـ job آمنة للتكرار
        if ($row !== null && ($inserted === 1 || $row->status === WebhookEventStatusEnum::Received->value)) {
            ProcessWebhookEventJob::dispatch((int) $row->id);
        }

        return response()->json(['status' => $inserted === 1 ? 'received' : 'duplicate']);
    }
}
