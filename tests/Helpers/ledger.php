<?php

declare(strict_types=1);

use App\Billing\Models\Invoice;
use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Models\Account;
use App\Ledger\Services\LedgerBalanceService;
use App\Ledger\Services\LedgerIntegrityService;
use App\Ledger\Support\JournalEntryDTO;
use App\Ledger\Support\JournalLineDTO;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Models\PaymentLink;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

function ledgerUsd(int $cents): Money
{
    return Money::of($cents, CurrencyEnum::USD);
}

/** @param  list<JournalLineDTO>  $lines */
function ledgerEntry(
    array $lines,
    string $event = 'recorded',
    int $sourceId = 1,
    ?CarbonImmutable $at = null,
    CurrencyEnum $currency = CurrencyEnum::USD,
): JournalEntryDTO {
    return new JournalEntryDTO(
        description: 'Test entry',
        postedAt: $at ?? CarbonImmutable::now(),
        currency: $currency,
        sourceType: 'test',
        sourceId: $sourceId,
        event: $event,
        lines: $lines,
    );
}

function accountId(string $code): int
{
    return (int) Account::query()->where('code', $code)->value('id');
}

/** @return array<string, int> أرصدة الحسابات على جهتها الطبيعية */
function ledgerBalances(): array
{
    $balances = app(LedgerBalanceService::class);

    return [
        'clearing' => $balances->balance(AccountCodeEnum::GatewayClearing)->amount,
        'receivable' => $balances->balance(AccountCodeEnum::AccountsReceivable)->amount,
        'tax' => $balances->balance(AccountCodeEnum::TaxPayable)->amount,
        'unapplied' => $balances->balance(AccountCodeEnum::UnappliedPayments)->amount,
        'revenue' => $balances->balance(AccountCodeEnum::SalesRevenue)->amount,
        'refunds' => $balances->balance(AccountCodeEnum::SalesRefunds)->amount,
    ];
}

/** بتتندهش في آخر أي سيناريو فلوس: الدفاتر سليمة ومتطابقة مع الفواتير والدفعات. */
function assertLedgerIsHealthy(): void
{
    expect(app(LedgerIntegrityService::class)->problems())->toBe([]);

    // المعادلة المحاسبية: الأصول = الالتزامات + صافي الإيراد
    $b = ledgerBalances();

    expect($b['clearing'] + $b['receivable'])
        ->toBe($b['tax'] + $b['unapplied'] + $b['revenue'] - $b['refunds']);
}

/** لينك تاني مفتوح لنفس الفاتورة، عشان نحاكي دفع تاني بعد ما الأول اتقفل. */
function secondOpenLink(Invoice $invoice, string $sessionId = 'cs_test_second'): PaymentLink
{
    return PaymentLink::query()->create([
        'invoice_id' => $invoice->id,
        'attempt' => 2,
        'stripe_session_id' => $sessionId,
        'url' => 'https://checkout.fake/pay/'.$sessionId,
        'status' => PaymentLinkStatusEnum::Open,
        'expires_at' => now()->addHour(),
    ]);
}
