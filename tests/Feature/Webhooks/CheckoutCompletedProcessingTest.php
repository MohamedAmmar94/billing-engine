<?php

declare(strict_types=1);

use App\Billing\Actions\VoidInvoiceAction;
use App\Billing\Enums\InvoiceStatusEnum;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Models\Payment;
use App\Payments\Models\PaymentLink;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Models\WebhookEvent;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['queue.default' => 'sync']); // الـ event بيتعالج في نفس الطلب
    $this->seed(SequenceSeeder::class);
    $this->invoice = openInvoice();
    $this->link = openLinkFor($this->invoice);
});

// ---------- الحالة العادية ----------

it('records the payment, pays the invoice and completes the link', function () {
    $event = completedEvent($this->invoice, $this->link);

    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'received');

    $payment = Payment::query()->firstOrFail();

    expect($payment->is_applied)->toBeTrue()
        ->and($payment->amount)->toBe($this->invoice->total)
        ->and($payment->currency)->toBe('USD')
        ->and($payment->invoice_id)->toBe($this->invoice->id)
        ->and($payment->payment_link_id)->toBe($this->link->id)
        ->and($payment->stripe_payment_intent_id)->toBe($event['data']['object']['payment_intent'])
        ->and($payment->paid_at->getTimestamp())->toBe($event['created'])
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid)
        ->and($this->invoice->fresh()->paid_at)->not->toBeNull()
        ->and($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed)
        ->and($this->link->fresh()->response['id'])->toBe($this->link->stripe_session_id)
        ->and(storedEvent($event['id'])->status)->toBe(WebhookEventStatusEnum::Processed);
});

// ---------- الدفع على لينك قفلناه محلياً ----------

it('still accepts money paid on a link our database already closed (problem: late webhook)', function (PaymentLinkStatusEnum $status) {
    $this->link->update(['status' => $status]);

    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    expect(Payment::query()->firstOrFail()->is_applied)->toBeTrue()
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid)
        ->and($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed);
})->with([
    'expired' => [PaymentLinkStatusEnum::Expired],
    'canceled' => [PaymentLinkStatusEnum::Canceled],
]);

it('cancels the newer live link when the older one is paid (problem: customer paid on the old link)', function () {
    // اللينك الأول "منتهي" عندنا، بس عند Stripe لسه مفتوح
    $this->link->update(['status' => PaymentLinkStatusEnum::Expired]);
    $this->get(payUrl($this->invoice))->assertRedirect();
    $newer = linkOf($this->invoice);

    expect($newer->id)->not->toBe($this->link->id)
        ->and($newer->status)->toBe(PaymentLinkStatusEnum::Open);

    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    expect(Payment::query()->firstOrFail()->payment_link_id)->toBe($this->link->id)
        ->and($newer->fresh()->status)->toBe(PaymentLinkStatusEnum::Canceled)
        ->and(fakeGateway()->session($newer->stripe_session_id)->status)->toBe(CheckoutSessionStatusEnum::Expired)
        ->and(liveLinkCount($this->invoice))->toBe(0);
});

// ---------- فلوس وصلت ومينفعش نطبقها ----------

it('keeps a second payment but does not apply it (problem: paid twice)', function () {
    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    $second = PaymentLink::query()->create([
        'invoice_id' => $this->invoice->id,
        'attempt' => 2,
        'stripe_session_id' => 'cs_test_second',
        'url' => 'https://checkout.fake/pay/cs_test_second',
        'status' => PaymentLinkStatusEnum::Open,
        'expires_at' => now()->addHour(),
    ]);

    $event = completedEvent($this->invoice, $second);
    deliverWebhook($event)->assertOk();

    $payments = Payment::query()->orderBy('id')->get();

    expect($payments)->toHaveCount(2)
        ->and($payments[0]->is_applied)->toBeTrue()
        ->and($payments[1]->is_applied)->toBeFalse()
        ->and($payments[1]->payment_link_id)->toBe($second->id)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid)
        ->and($second->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed)
        ->and(storedEvent($event['id'])->note)->toContain('not applied');
});

it('keeps a payment that arrives after the invoice was voided (problem: customer was already on the Stripe page)', function () {
    app(VoidInvoiceAction::class)->handle($this->invoice);

    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    expect(Payment::query()->firstOrFail()->is_applied)->toBeFalse()
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Void)
        ->and($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed);
});

it('keeps a payment whose amount or currency differs from the invoice', function (string $case) {
    $override = match ($case) {
        'amount too low' => ['amount_total' => $this->invoice->total - 1],
        'amount too high' => ['amount_total' => $this->invoice->total + 1],
        'other currency' => ['currency' => 'eur'],
    };

    deliverWebhook(completedEvent($this->invoice, $this->link, $override))->assertOk();

    expect(Payment::query()->firstOrFail()->is_applied)->toBeFalse()
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Open)
        ->and($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed);
})->with(['amount too low', 'amount too high', 'other currency']);

// ---------- أحداث بنتجاهلها ----------

it('ignores sessions that are not ours, not paid, or events we do not handle', function (string $case) {
    $event = match ($case) {
        'unknown link' => completedEvent($this->invoice, $this->link, ['client_reference_id' => (string) Str::uuid()]),
        'reference is not a uuid' => completedEvent($this->invoice, $this->link, ['client_reference_id' => 'order-1234']),
        'session not paid' => completedEvent($this->invoice, $this->link, ['payment_status' => 'unpaid']),
        'other event type' => stripeEvent('payment_intent.succeeded', ['id' => 'pi_1']),
    };

    deliverWebhook($event)->assertOk();

    expect(Payment::query()->count())->toBe(0)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Open)
        ->and(storedEvent($event['id'])->status)->toBe(WebhookEventStatusEnum::Ignored);
})->with(['unknown link', 'reference is not a uuid', 'session not paid', 'other event type']);

// ---------- التكرار ----------

it('records one payment even if Stripe delivers the same event twice (problem: duplicate delivery)', function () {
    $event = completedEvent($this->invoice, $this->link);

    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'received');
    deliverWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(Payment::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('records one payment when a different event id announces the same checkout again', function () {
    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    $again = completedEvent($this->invoice, $this->link);
    deliverWebhook($again)->assertOk();

    expect(Payment::query()->count())->toBe(1)
        ->and(storedEvent($again['id'])->note)->toBe('The payment was already recorded.');
});

// ---------- الترتيب ----------

it('marks a live link expired when Stripe reports the session expired', function () {
    $event = expiredEvent($this->link);

    deliverWebhook($event)->assertOk();

    expect($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Expired)
        ->and(storedEvent($event['id'])->status)->toBe(WebhookEventStatusEnum::Processed);
});

it('lets a late expired event change nothing once the payment is recorded (problem: events out of order)', function () {
    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    $late = expiredEvent($this->link);
    deliverWebhook($late)->assertOk();

    expect($this->link->fresh()->status)->toBe(PaymentLinkStatusEnum::Completed)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid)
        ->and(storedEvent($late['id'])->status)->toBe(WebhookEventStatusEnum::Ignored);
});

it('still applies the payment when the expired event arrived first (problem: events out of order)', function () {
    deliverWebhook(expiredEvent($this->link))->assertOk();
    deliverWebhook(completedEvent($this->invoice, $this->link))->assertOk();

    expect(Payment::query()->firstOrFail()->is_applied)->toBeTrue()
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Paid);
});
