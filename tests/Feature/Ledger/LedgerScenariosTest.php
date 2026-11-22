<?php

declare(strict_types=1);

use App\Billing\Actions\OpenInvoiceAction;
use App\Billing\Actions\VoidInvoiceAction;
use App\Billing\Enums\InvoiceStatusEnum;
use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Models\AccountBalance;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Services\LedgerBalanceService;
use App\Payments\Models\Payment;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->seed(SequenceSeeder::class);
});

// الفاتورة المرجعية: subtotal 179.00 + ضريبة 25.06 = 204.06

it('records receivable, revenue and tax when an invoice is opened', function () {
    $invoice = openInvoice(17900);

    expect(ledgerBalances())->toEqual([
        'clearing' => 0, 'receivable' => 20406, 'tax' => 2506, 'unapplied' => 0, 'revenue' => 17900, 'refunds' => 0,
    ]);

    $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->firstOrFail();

    expect($entry->event)->toBe('opened')
        ->and($entry->lines()->count())->toBe(3)
        ->and($entry->posted_at->getTimestamp())->toBe($invoice->issued_at->getTimestamp());

    assertLedgerIsHealthy();
});

it('moves the money from receivable to the gateway when the payment is confirmed', function () {
    $invoice = openInvoice(17900);
    $link = openLinkFor($invoice);

    deliverWebhook(completedEvent($invoice, $link))->assertOk();

    expect(ledgerBalances())->toEqual([
        'clearing' => 20406, 'receivable' => 0, 'tax' => 2506, 'unapplied' => 0, 'revenue' => 17900, 'refunds' => 0,
    ]);

    $payment = Payment::query()->firstOrFail();
    $entry = JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail();

    expect($entry->event)->toBe('recorded')
        ->and($entry->lines()->count())->toBe(2);

    assertLedgerIsHealthy();
});

it('reverses the opening entry when an open invoice is voided', function () {
    $invoice = openInvoice(17900);

    app(VoidInvoiceAction::class)->handle($invoice);

    expect(ledgerBalances())->toEqual([
        'clearing' => 0, 'receivable' => 0, 'tax' => 0, 'unapplied' => 0, 'revenue' => 0, 'refunds' => 0,
    ]);

    $opened = JournalEntry::query()->where('event', 'opened')->firstOrFail();
    $voided = JournalEntry::query()->where('event', 'voided')->firstOrFail();

    expect($voided->reverses_entry_id)->toBe($opened->id)
        ->and($voided->source_id)->toBe($invoice->id)
        ->and(JournalEntry::query()->count())->toBe(2) // مفيش قيد اتعدل أو اتمسح
        ->and($invoice->fresh()->status)->toBe(InvoiceStatusEnum::Void);

    assertLedgerIsHealthy();
});

it('treats a second payment on a paid invoice as a liability, not revenue (problem: paid twice)', function () {
    $invoice = openInvoice(17900);
    deliverWebhook(completedEvent($invoice, openLinkFor($invoice)))->assertOk();

    deliverWebhook(completedEvent($invoice, secondOpenLink($invoice)))->assertOk();

    expect(ledgerBalances())->toEqual([
        'clearing' => 40812, 'receivable' => 0, 'tax' => 2506, 'unapplied' => 20406, 'revenue' => 17900, 'refunds' => 0,
    ]);

    assertLedgerIsHealthy();
});

it('keeps a payment that arrives after the invoice was voided as a liability', function () {
    $invoice = openInvoice(17900);
    $link = openLinkFor($invoice);

    app(VoidInvoiceAction::class)->handle($invoice);
    deliverWebhook(completedEvent($invoice, $link))->assertOk();

    expect(ledgerBalances())->toEqual([
        'clearing' => 20406, 'receivable' => 0, 'tax' => 0, 'unapplied' => 20406, 'revenue' => 0, 'refunds' => 0,
    ]);

    assertLedgerIsHealthy();
});

it('leaves the invoice receivable alone when the amount paid is wrong', function () {
    $invoice = openInvoice(17900);
    $link = openLinkFor($invoice);

    deliverWebhook(completedEvent($invoice, $link, ['amount_total' => $invoice->total - 1]))->assertOk();

    // الفاتورة لسه مفتوحة، فالمدينين لسه 204.06، والفلوس اللي وصلت (204.05) التزام لحد ما نرجّعها
    expect(ledgerBalances())->toEqual([
        'clearing' => 20405, 'receivable' => 20406, 'tax' => 2506, 'unapplied' => 20405, 'revenue' => 17900, 'refunds' => 0,
    ]);

    assertLedgerIsHealthy();
});

it('keeps a payment in another currency out of the single-currency ledger', function () {
    $invoice = openInvoice(17900);
    $link = openLinkFor($invoice);
    Log::spy();

    deliverWebhook(completedEvent($invoice, $link, ['currency' => 'eur']))->assertOk();

    $payment = Payment::query()->firstOrFail();

    expect($payment->is_applied)->toBeFalse()
        ->and($payment->currency)->toBe('EUR')
        ->and(JournalEntry::query()->where('source_type', 'payment')->count())->toBe(0)
        ->and(ledgerBalances()['clearing'])->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'not posted to the ledger'))
        ->once();

    assertLedgerIsHealthy();
});

it('does not open the invoice when the ledger cannot record it (same transaction)', function () {
    $draft = draftInvoice(17900);
    DB::table('account_balances')->where('account_id', accountId('1100'))->delete();

    expect(fn () => app(OpenInvoiceAction::class)->handle($draft))
        ->toThrow(LedgerException::class, 'no balance row');

    expect($draft->fresh()->status)->toBe(InvoiceStatusEnum::Draft)
        ->and($draft->fresh()->number)->toBeNull()
        ->and((int) DB::table('sequences')->where('name', 'invoice')->value('last_value'))->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('retries a payment safely after a ledger outage and leaves nothing half-recorded', function () {
    Queue::fake(); // نشغّل الـ job بإيدينا عشان نتحكم في كل محاولة

    $invoice = openInvoice(17900);
    $link = openLinkFor($invoice);
    $event = completedEvent($invoice, $link);
    deliverWebhook($event)->assertOk();
    $stored = storedEvent($event['id']);

    $clearingId = accountId('1010');
    DB::table('account_balances')->where('account_id', $clearingId)->delete();

    expect(fn () => runWebhookJob($stored))->toThrow(LedgerException::class);

    expect(Payment::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatusEnum::Open)
        ->and($stored->fresh()->status)->toBe(WebhookEventStatusEnum::Received)
        ->and(app(LedgerBalanceService::class)->balance(AccountCodeEnum::AccountsReceivable)->amount)->toBe(20406);

    // الـ ledger رجع: المحاولة التانية تنجح
    AccountBalance::query()->create(['account_id' => $clearingId]);
    runWebhookJob($stored);

    expect($stored->fresh()->status)->toBe(WebhookEventStatusEnum::Processed)
        ->and(Payment::query()->count())->toBe(1);

    assertLedgerIsHealthy();
});
