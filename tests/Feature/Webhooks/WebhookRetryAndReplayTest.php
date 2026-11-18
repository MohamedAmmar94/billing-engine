<?php

declare(strict_types=1);

use App\Billing\Enums\InvoiceStatusEnum;
use App\Payments\Events\PaymentRecordedEvent;
use App\Payments\Models\Payment;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Jobs\ProcessWebhookEventJob;
use App\Webhooks\Models\WebhookEvent;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake(); // الـ event بيتخزن من غير ما يتعالج، ونشغّل الـ job بإيدينا
    $this->seed(SequenceSeeder::class);
    $this->invoice = openInvoice();
    $this->link = openLinkFor($this->invoice);
    $this->event = completedEvent($this->invoice, $this->link);

    deliverWebhook($this->event)->assertOk();
    $this->stored = storedEvent($this->event['id']);
});

it('retries five times with growing delays before giving up', function () {
    $job = new ProcessWebhookEventJob(1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900]);
});

it('leaves everything untouched when processing fails, and succeeds on the retry (problem: handler failure)', function () {
    // نحاكي فشل جوه الـ transaction (مثلاً الـ ledger في Phase 4 يقع)
    Event::listen(PaymentRecordedEvent::class, function (): void {
        throw new RuntimeException('ledger unavailable');
    });

    expect(fn () => runWebhookJob($this->stored))->toThrow(RuntimeException::class, 'ledger unavailable');

    $failed = $this->stored->fresh();

    expect($failed->status)->toBe(WebhookEventStatusEnum::Received)
        ->and($failed->attempts)->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Open);

    Event::forget(PaymentRecordedEvent::class);
    runWebhookJob($this->stored);

    $retried = $this->stored->fresh();

    expect($retried->status)->toBe(WebhookEventStatusEnum::Processed)
        ->and($retried->attempts)->toBe(2)
        ->and(Payment::query()->count())->toBe(1)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid);
});

it('marks the event dead when the queue gives up (dead letter)', function () {
    (new ProcessWebhookEventJob($this->stored->id))->failed(new RuntimeException('database is down'));

    $dead = $this->stored->fresh();

    expect($dead->status)->toBe(WebhookEventStatusEnum::Dead)
        ->and($dead->note)->toContain('database is down');
});

it('never turns an already processed event into a dead one', function () {
    runWebhookJob($this->stored);

    (new ProcessWebhookEventJob($this->stored->id))->failed(new RuntimeException('late failure'));

    expect($this->stored->fresh()->status)->toBe(WebhookEventStatusEnum::Processed);
});

it('does nothing when the job runs again for a processed event', function () {
    runWebhookJob($this->stored);
    runWebhookJob($this->stored);

    expect(Payment::query()->count())->toBe(1);
});

it('replays a dead event: queued again, then processed', function () {
    $this->stored->update(['status' => WebhookEventStatusEnum::Dead, 'attempts' => 5, 'note' => 'database is down']);

    $this->artisan('webhooks:replay', ['event' => $this->stored->uuid])->assertSuccessful();

    $replayed = $this->stored->fresh();

    expect($replayed->status)->toBe(WebhookEventStatusEnum::Received)
        ->and($replayed->attempts)->toBe(0)
        ->and($replayed->note)->toBeNull();

    Queue::assertPushed(ProcessWebhookEventJob::class, 2); // التسليم الأصلي + الـ replay

    runWebhookJob($replayed);

    expect($replayed->fresh()->status)->toBe(WebhookEventStatusEnum::Processed)
        ->and(Payment::query()->count())->toBe(1);
});

it('replays only dead events when asked for all of them', function () {
    $this->stored->update(['status' => WebhookEventStatusEnum::Dead]);

    $other = WebhookEvent::query()->create([
        'provider' => 'stripe',
        'event_id' => 'evt_other',
        'type' => 'payment_intent.succeeded',
        'payload' => [],
        'status' => WebhookEventStatusEnum::Processed,
        'received_at' => now(),
    ]);

    $this->artisan('webhooks:replay', ['--dead' => true])
        ->expectsOutputToContain('Re-queued 1 event(s).')
        ->assertSuccessful();

    expect($this->stored->fresh()->status)->toBe(WebhookEventStatusEnum::Received)
        ->and($other->fresh()->status)->toBe(WebhookEventStatusEnum::Processed);
});

it('refuses to replay without a target, with a bad uuid, or when nothing is dead', function (array $arguments) {
    $this->artisan('webhooks:replay', $arguments)->assertFailed();
})->with([
    'no argument and no option' => [[]],
    'not a uuid' => [['event' => 'abc']],
    'nothing is dead' => [['--dead' => true]],
]);
