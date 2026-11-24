<?php

declare(strict_types=1);

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Models\Account;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Models\JournalLine;
use App\Ledger\Services\LedgerBalanceService;
use App\Ledger\Services\LedgerService;
use App\Ledger\Support\JournalLineDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->balances = app(LedgerBalanceService::class);
});

it('posts a balanced entry, writes its lines and updates the balances', function () {
    $entry = $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(20406)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, ledgerUsd(17900)),
        JournalLineDTO::credit(AccountCodeEnum::TaxPayable, ledgerUsd(2506)),
    ]));

    expect(JournalLine::query()->where('journal_entry_id', $entry->id)->count())->toBe(3)
        ->and($this->balances->balance(AccountCodeEnum::AccountsReceivable)->amount)->toBe(20406)
        ->and($this->balances->balance(AccountCodeEnum::SalesRevenue)->amount)->toBe(17900)
        ->and($this->balances->balance(AccountCodeEnum::TaxPayable)->amount)->toBe(2506);
});

it('reports balances on the natural side, including contra accounts', function () {
    $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::SalesRefunds, ledgerUsd(500)),
        JournalLineDTO::credit(AccountCodeEnum::GatewayClearing, ledgerUsd(500)),
    ]));

    // مرتجعات المبيعات حساب عكسي رصيده الطبيعي مدين، فالموجب هو المطلوب
    expect($this->balances->balance(AccountCodeEnum::SalesRefunds)->amount)->toBe(500)
    // الـ Clearing أصل رصيده الطبيعي مدين، فلما يخرج منه فلوس بيبقى سالب
        ->and($this->balances->balance(AccountCodeEnum::GatewayClearing)->amount)->toBe(-500);
});

it('refuses a second entry for the same source and event (problem: processed twice)', function () {
    $lines = fn (): array => [
        JournalLineDTO::debit(AccountCodeEnum::GatewayClearing, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000)),
    ];

    $this->ledger->post(ledgerEntry($lines()));

    expect(fn () => $this->ledger->post(ledgerEntry($lines())))
        ->toThrow(LedgerException::class, 'already posted');

    expect(JournalEntry::query()->count())->toBe(1)
        ->and($this->balances->balance(AccountCodeEnum::GatewayClearing)->amount)->toBe(1000);
});

it('refuses accounts that cannot take entries and writes nothing', function (string $case, string $message) {
    match ($case) {
        'group account' => null,
        'missing balance row' => DB::table('account_balances')->where('account_id', accountId('1100'))->delete(),
        'other currency' => Account::query()->where('code', '1100')->update(['currency' => 'EUR']),
    };
    // group accountحساب تجميعي بيجمع أرصدة الحسابات الموجودة تحته،لا يستقبل اى عمليات
    $account = $case === 'group account' ? AccountCodeEnum::Assets : AccountCodeEnum::AccountsReceivable;

    expect(fn () => $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit($account, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::GatewayClearing, ledgerUsd(1000)),
    ])))->toThrow(LedgerException::class, $message);

    expect(JournalEntry::query()->count())->toBe(0);
})->with([
    ['group account', 'group account'],
    ['missing balance row', 'no balance row'],
    ['other currency', 'does not use the entry currency'],
]);

it('reverses an entry with mirrored lines and brings the balances back', function () {
    $original = $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(20406)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, ledgerUsd(17900)),
        JournalLineDTO::credit(AccountCodeEnum::TaxPayable, ledgerUsd(2506)),
    ]));

    $reversal = $this->ledger->reverse($original, 'test', 1, 'voided', 'Reversal', CarbonImmutable::now());

    $receivableLine = JournalLine::query()
        ->where('journal_entry_id', $reversal->id)
        ->where('account_id', accountId('1100'))
        ->firstOrFail();

    expect($reversal->reverses_entry_id)->toBe($original->id)
        ->and($receivableLine->credit)->toBe(20406) // كان مدين فبقى دائن
        ->and($receivableLine->debit)->toBe(0)
        ->and($this->balances->balance(AccountCodeEnum::AccountsReceivable)->amount)->toBe(0)
        ->and($this->balances->balance(AccountCodeEnum::SalesRevenue)->amount)->toBe(0)
        ->and($this->balances->balance(AccountCodeEnum::TaxPayable)->amount)->toBe(0);
});

it('refuses to reverse the same entry twice', function () {
    $original = $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::GatewayClearing, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000)),
    ]));

    $this->ledger->reverse($original, 'test', 1, 'voided', 'Reversal', CarbonImmutable::now());

    expect(fn () => $this->ledger->reverse($original, 'test', 1, 'voided-again', 'Again', CarbonImmutable::now()))
        ->toThrow(LedgerException::class, 'already reversed');
});

it('answers what an account held at a given date', function () {
    $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, ledgerUsd(1000)),
    ], 'opened', 1, CarbonImmutable::parse('2026-01-10 12:00:00')));

    $this->ledger->post(ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::GatewayClearing, ledgerUsd(400)),
        JournalLineDTO::credit(AccountCodeEnum::AccountsReceivable, ledgerUsd(400)),
    ], 'paid', 2, CarbonImmutable::parse('2026-01-20 12:00:00')));

    $receivable = AccountCodeEnum::AccountsReceivable;

    expect($this->balances->balance($receivable, CarbonImmutable::parse('2026-01-05'))->amount)->toBe(0)
        ->and($this->balances->balance($receivable, CarbonImmutable::parse('2026-01-15'))->amount)->toBe(1000)
        ->and($this->balances->balance($receivable, CarbonImmutable::parse('2026-01-25'))->amount)->toBe(600)
        ->and($this->balances->balance($receivable)->amount)->toBe(600);
});
