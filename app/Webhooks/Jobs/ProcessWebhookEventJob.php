<?php

declare(strict_types=1);

namespace App\Webhooks\Jobs;

use App\Payments\Actions\MarkPaymentLinkExpiredAction;
use App\Payments\Actions\RecordCheckoutSessionCompletedAction;
use App\Payments\Enums\PaymentRecordingOutcomeEnum;
use App\Payments\Support\CheckoutSessionCompletedDTO;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** مفيش حد مستني رد هنا (Stripe خد 200 خلاص)، فالـ queue هي اللي بتعيد بـ backoff، وبعد 5 محاولات الـ event بيبقى dead. */
final class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $webhookEventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(
        RecordCheckoutSessionCompletedAction $recordCompleted,
        MarkPaymentLinkExpiredAction $markExpired,
    ): void {
        WebhookEvent::query()->whereKey($this->webhookEventId)->increment('attempts');

        DB::transaction(function () use ($recordCompleted, $markExpired): void {
            $event = WebhookEvent::query()->whereKey($this->webhookEventId)->lockForUpdate()->first();

            // اتعالج قبل كده (تكرار أو replay): ما نعملش حاجة
            if ($event === null || $event->status !== WebhookEventStatusEnum::Received) {
                return;
            }

            [$status, $note] = match ($event->type) {
                'checkout.session.completed' => $this->completed($event, $recordCompleted),
                'checkout.session.expired' => $this->expired($event, $markExpired),
                default => [WebhookEventStatusEnum::Ignored, 'Event type is not handled.'],
            };

            $event->update(['status' => $status, 'note' => $note, 'processed_at' => now()]);
        });
    }

    public function failed(Throwable $exception): void
    {
        WebhookEvent::query()
            ->whereKey($this->webhookEventId)
            ->where('status', WebhookEventStatusEnum::Received->value)
            ->update([
                'status' => WebhookEventStatusEnum::Dead->value,
                'note' => Str::limit($exception->getMessage(), 1000),
            ]);
    }

    /** @return array{0: WebhookEventStatusEnum, 1: string|null} */
    private function completed(WebhookEvent $event, RecordCheckoutSessionCompletedAction $action): array
    {
        $payload = $event->payload;
        $paymentIntent = data_get($payload, 'data.object.payment_intent');

        $outcome = $action->handle(new CheckoutSessionCompletedDTO(
            sessionId: (string) data_get($payload, 'data.object.id', ''),
            clientReferenceId: (string) data_get($payload, 'data.object.client_reference_id', ''),
            paymentStatus: (string) data_get($payload, 'data.object.payment_status', ''),
            paymentIntentId: $paymentIntent === null ? null : (string) $paymentIntent,
            amountTotal: (int) data_get($payload, 'data.object.amount_total', 0),
            currency: (string) data_get($payload, 'data.object.currency', ''),
            paidAt: CarbonImmutable::createFromTimestamp((int) data_get($payload, 'created', time())),
            raw: (array) data_get($payload, 'data.object', []),
        ));

        return match ($outcome) {
            PaymentRecordingOutcomeEnum::Applied => [WebhookEventStatusEnum::Processed, null],
            PaymentRecordingOutcomeEnum::NotApplied => [WebhookEventStatusEnum::Processed, 'Payment recorded but not applied to the invoice; needs review.'],
            PaymentRecordingOutcomeEnum::AlreadyRecorded => [WebhookEventStatusEnum::Processed, 'The payment was already recorded.'],
            PaymentRecordingOutcomeEnum::UnknownLink => [WebhookEventStatusEnum::Ignored, 'No payment link matches client_reference_id.'],
            PaymentRecordingOutcomeEnum::NotPaid => [WebhookEventStatusEnum::Ignored, 'The checkout session is not paid.'],
        };
    }

    /** @return array{0: WebhookEventStatusEnum, 1: string|null} */
    private function expired(WebhookEvent $event, MarkPaymentLinkExpiredAction $action): array
    {
        $changed = $action->handle((string) data_get($event->payload, 'data.object.client_reference_id', ''));

        return [
            $changed ? WebhookEventStatusEnum::Processed : WebhookEventStatusEnum::Ignored,
            $changed ? null : 'The payment link is unknown or no longer live.',
        ];
    }
}
