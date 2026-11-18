<?php

declare(strict_types=1);

use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Jobs\ProcessWebhookEventJob;
use App\Webhooks\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('rejects requests not signed by Stripe and stores nothing (problem: forged or replayed webhook)', function (string $case) {
    $body = json_encode(stripeEvent('checkout.session.completed', ['id' => 'cs_1']), JSON_THROW_ON_ERROR);

    $response = match ($case) {
        'no signature header' => sendWebhook($body),
        'signed with another secret' => sendWebhook($body, stripeSignatureHeader($body, secret: 'whsec_attacker')),
        'body changed after signing' => sendWebhook($body.' ', stripeSignatureHeader($body)),
        'replayed from the past' => sendWebhook($body, stripeSignatureHeader($body, now()->getTimestamp() - 400)),
        'garbage header' => sendWebhook($body, 'hello'),
    };

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_signature');

    expect(WebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no signature header',
    'signed with another secret',
    'body changed after signing',
    'replayed from the past',
    'garbage header',
]);

it('rejects a correctly signed body that is not a Stripe event', function (string $body) {
    sendWebhook($body, stripeSignatureHeader($body))
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_payload');

    expect(WebhookEvent::query()->count())->toBe(0);
})->with([
    'not json' => ['not json at all'],
    'no id' => ['{"type":"x"}'],
    'no type' => ['{"id":"evt_1"}'],
    'a list' => ['[1,2,3]'],
]);

it('stores a valid event as received and queues it, without any token', function () {
    $event = stripeEvent('checkout.session.completed', ['id' => 'cs_1', 'client_reference_id' => 'x']);

    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'received');

    $stored = storedEvent($event['id']);

    expect($stored->provider)->toBe('stripe')
        ->and($stored->type)->toBe('checkout.session.completed')
        ->and($stored->status)->toBe(WebhookEventStatusEnum::Received)
        ->and($stored->payload)->toEqual($event)
        ->and($stored->received_at)->not->toBeNull();

    Queue::assertPushed(
        ProcessWebhookEventJob::class,
        fn (ProcessWebhookEventJob $job): bool => $job->webhookEventId === $stored->id,
    );
});

it('answers 200 duplicate for a stored event and re-queues it only while it is unprocessed (problem: duplicate delivery)', function () {
    $event = stripeEvent('checkout.session.completed', ['id' => 'cs_1']);

    deliverWebhook($event)->assertJsonPath('status', 'received');
    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(WebhookEvent::query()->count())->toBe(1);
    // لسه received: بنعيد الإرسال لأن الـ queue ممكن تكون ضيّعته
    Queue::assertPushed(ProcessWebhookEventJob::class, 2);

    storedEvent($event['id'])->update(['status' => WebhookEventStatusEnum::Processed]);

    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
    Queue::assertPushed(ProcessWebhookEventJob::class, 2);
});
