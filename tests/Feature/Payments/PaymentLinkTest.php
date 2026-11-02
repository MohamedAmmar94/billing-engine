<?php

declare(strict_types=1);

use App\Billing\Actions\VoidInvoice;
use App\Billing\Enums\InvoiceStatus;
use App\Payments\Enums\PaymentLinkStatus;
use App\Payments\Gateway\CheckoutSessionStatus;
use App\Payments\Gateway\Fake\FakeFailure;
use App\Payments\Models\PaymentLink;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
    $this->invoice = openInvoice();
});

// ---------- الحالة العادية ----------

it('creates a link on the first visit and sends the customer to Stripe', function () {
    $response = $this->get(payUrl($this->invoice));
    // dd(payUrl($this->invoice), $response);
    $link = linkOf($this->invoice);

    $response->assertRedirect($link->url);
    expect($link->status)->toBe(PaymentLinkStatus::Open)
        ->and($link->attempt)->toBe(1)
        ->and($link->stripe_session_id)->toStartWith('cs_test_fake_')
        ->and(fakeGateway()->sessionCount())->toBe(1);
});

it('reuses the same link while it is alive (problem: two live links)', function () {
    $this->get(payUrl($this->invoice));
    $first = linkOf($this->invoice);

    $this->get(payUrl($this->invoice))->assertRedirect($first->url);
    $this->get(payUrl($this->invoice))->assertRedirect($first->url);

    expect(PaymentLink::query()->count())->toBe(1)
        ->and(fakeGateway()->sessionCount())->toBe(1);
});

// ---------- اللينك القديم ----------

it('replaces a link that expired at Stripe (problem: customer opens an old email)', function () {
    $this->get(payUrl($this->invoice));
    $old = linkOf($this->invoice);
    fakeGateway()->expire($old->stripe_session_id);

    $response = $this->get(payUrl($this->invoice));
    $new = linkOf($this->invoice);

    $response->assertRedirect($new->url);
    expect($old->fresh()->status)->toBe(PaymentLinkStatus::Expired)
        ->and($new->attempt)->toBe(2)
        ->and($new->status)->toBe(PaymentLinkStatus::Open)
        ->and(liveLinkCount($this->invoice))->toBe(1);
});

it('retires a link that is about to expire and expires its session at Stripe', function () {
    $this->get(payUrl($this->invoice));
    $old = linkOf($this->invoice);

    // فاضل 10 دقايق بس (أقل من حد الـ 30)
    $this->travelTo(now()->addMinutes(710));
    $this->get(payUrl($this->invoice));
    $new = linkOf($this->invoice);

    expect($old->fresh()->status)->toBe(PaymentLinkStatus::Canceled)
        ->and(fakeGateway()->session($old->stripe_session_id)->status)->toBe(CheckoutSessionStatus::Expired)
        ->and($new->attempt)->toBe(2)
        ->and(liveLinkCount($this->invoice))->toBe(1);
});

it('does not create a new link when Stripe says the payment completed (problem: webhook not yet received)', function () {
    $this->get(payUrl($this->invoice));
    $link = linkOf($this->invoice);
    fakeGateway()->complete($link->stripe_session_id);

    $this->get(payUrl($this->invoice))
        ->assertOk()
        ->assertJsonPath('status', 'processing');

    expect(PaymentLink::query()->count())->toBe(1)
        ->and($link->fresh()->status)->toBe(PaymentLinkStatus::Open);
});

// ---------- فواتير مش قابلة للدفع ----------

it('does not create anything for invoices that cannot be paid', function (InvoiceStatus $status, int $httpStatus, string $answer) {
    $this->invoice->update(['status' => $status]);

    $this->get(payUrl($this->invoice))
        ->assertStatus($httpStatus)
        ->assertJsonPath('status', $answer);

    expect(PaymentLink::query()->count())->toBe(0)
        ->and(fakeGateway()->calls('create'))->toBe(0);
})->with([
    'paid' => [InvoiceStatus::Paid, 200, 'already_paid'],
    'void' => [InvoiceStatus::Void, 409, 'not_payable'],
    'draft' => [InvoiceStatus::Draft, 409, 'not_payable'],
]);

it('answers 404 for a malformed id', function () {
    $this->get('/api/v1/pay/abc')->assertNotFound();
});

// ---------- أعطال الـ gateway ----------

it('keeps a pending row when Stripe times out and resumes later (problem: timeout before creation)', function () {
    fakeGateway()->failNext('create', FakeFailure::TimeoutBeforeEffect);

    $this->get(payUrl($this->invoice))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('status', 'try_again');

    $link = linkOf($this->invoice);
    expect($link->status)->toBe(PaymentLinkStatus::Pending)
        ->and($link->stripe_session_id)->toBeNull();

    // لسه جوه فترة السماح: حد ممكن يكون بيكلم Stripe، فما نكلمهوش تاني
    $this->get(payUrl($this->invoice))->assertStatus(503);
    expect(fakeGateway()->calls('create'))->toBe(1);

    // بعد فترة السماح: نكمّل
    $this->travelTo(now()->addMinutes(3));
    $this->get(payUrl($this->invoice))->assertRedirect();

    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Open)
        ->and(PaymentLink::query()->count())->toBe(1)
        ->and(fakeGateway()->sessionCount())->toBe(1);
});

it('never creates two Stripe sessions when the response is lost (problem: crash or timeout after Stripe created the session)', function () {
    fakeGateway()->failNext('create', FakeFailure::TimeoutAfterEffect);

    $this->get(payUrl($this->invoice))->assertStatus(503);

    // Stripe عمل الـ session فعلاً، بس إحنا ما عرفناش
    expect(fakeGateway()->sessionCount())->toBe(1)
        ->and(linkOf($this->invoice)->stripe_session_id)->toBeNull();

    $this->travelTo(now()->addMinutes(3));
    $this->get(payUrl($this->invoice))->assertRedirect();

    $link = linkOf($this->invoice);
    expect(fakeGateway()->sessionCount())->toBe(1)
        ->and($link->stripe_session_id)->toBe('cs_test_fake_000001')
        ->and($link->status)->toBe(PaymentLinkStatus::Open)
        ->and(PaymentLink::query()->count())->toBe(1);
});

it('frees the slot when Stripe rejects the request and lets the customer try again', function () {
    fakeGateway()->failNext('create', FakeFailure::Rejected);

    $this->get(payUrl($this->invoice))->assertStatus(502)->assertJsonPath('status', 'unavailable');

    expect(linkOf($this->invoice)->status)->toBe(PaymentLinkStatus::Canceled);

    $this->get(payUrl($this->invoice))->assertRedirect();

    expect(linkOf($this->invoice)->attempt)->toBe(2)
        ->and(liveLinkCount($this->invoice))->toBe(1);
});

it('asks the customer to retry when Stripe cannot be reached while checking a link', function () {
    $this->get(payUrl($this->invoice));
    fakeGateway()->failNext('retrieve', FakeFailure::TimeoutBeforeEffect);

    $this->get(payUrl($this->invoice))->assertStatus(503);

    expect(linkOf($this->invoice)->status)->toBe(PaymentLinkStatus::Open)
        ->and(PaymentLink::query()->count())->toBe(1);
});

// ---------- الـ DB بتحمي لوحدها ----------

it('lets the database refuse a second live link even if the code were wrong', function () {
    $attributes = ['invoice_id' => $this->invoice->id, 'status' => 'pending', 'expires_at' => now()->addHour()];

    PaymentLink::query()->create([...$attributes, 'attempt' => 1]);
    PaymentLink::query()->create([...$attributes, 'attempt' => 2]);
})->throws(QueryException::class, 'payment_links_one_live_per_invoice');

// ---------- الإلغاء والتنظيف ----------

it('cancels live links and expires the Stripe session when the invoice is voided', function () {
    $this->get(payUrl($this->invoice));
    $link = linkOf($this->invoice);

    app(VoidInvoice::class)->handle($this->invoice);

    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Canceled)
        ->and(fakeGateway()->session($link->stripe_session_id)->status)->toBe(CheckoutSessionStatus::Expired);

    $this->get(payUrl($this->invoice))->assertStatus(409)->assertJsonPath('status', 'not_payable');
});

it('marks open links past their expiry as expired', function () {
    $this->get(payUrl($this->invoice));
    $link = linkOf($this->invoice);

    $this->travelTo(now()->addHours(13));
    $this->artisan('payments:expire-links')->assertSuccessful();

    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Expired);
});

// ---------- الـ API بتاع الموظف ----------

it('shows the stable pay URL to staff only while the invoice is open', function () {
    Sanctum::actingAs(makeStaff());
    $draft = draftInvoice();

    $this->getJson("/api/v1/invoices/{$draft->uuid}")->assertJsonPath('data.pay_url', null);
    $this->getJson("/api/v1/invoices/{$this->invoice->uuid}")->assertJsonPath('data.pay_url', payUrl($this->invoice));
});
