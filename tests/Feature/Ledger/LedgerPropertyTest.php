<?php

declare(strict_types=1);

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Services\LedgerBalanceService;
use App\Ledger\Services\LedgerIntegrityService;
use App\Ledger\Services\LedgerService;
use App\Ledger\Support\JournalLineDTO;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps the books balanced and every stored balance exact after random entries and reversals', function () {
    mt_srand(2026); // seed ثابت: لو فشل نقدر نعيد نفس الحالة بالظبط

    $ledger = app(LedgerService::class);
    $balances = app(LedgerBalanceService::class);

    // حسابات مالهاش control account، عشان ledger:verify يفضل صح من غير فواتير ولا دفعات
    $accounts = [AccountCodeEnum::TaxPayable, AccountCodeEnum::SalesRevenue, AccountCodeEnum::SalesRefunds];

    // نموذج مستقل في الـ test: بنحسب بإيدينا اللي المفروض يحصل
    $model = [];
    foreach ($accounts as $account) {
        $model[$account->value] = ['debit' => 0, 'credit' => 0];
    }

    $recipes = [];
    $posted = [];

    for ($i = 1; $i <= 120; $i++) {
        $total = mt_rand(3, 1_000_000);
        $lines = [];

        foreach (Money::of($total, CurrencyEnum::USD)->split(mt_rand(1, 3)) as $part) {
            $account = $accounts[array_rand($accounts)];
            $lines[] = JournalLineDTO::debit($account, $part);
            $recipes[$i][] = [$account->value, 'debit', $part->amount];
            $model[$account->value]['debit'] += $part->amount;
        }

        foreach (Money::of($total, CurrencyEnum::USD)->split(mt_rand(1, 3)) as $part) {
            $account = $accounts[array_rand($accounts)];
            $lines[] = JournalLineDTO::credit($account, $part);
            $recipes[$i][] = [$account->value, 'credit', $part->amount];
            $model[$account->value]['credit'] += $part->amount;
        }

        $posted[$i] = $ledger->post(ledgerEntry($lines, 'posted', $i));
    }

    $reversed = 0;

    foreach ($posted as $i => $entry) {
        if (mt_rand(1, 100) > 25) {
            continue;
        }

        $ledger->reverse($entry, 'test', $i, 'reversed', 'Reversal', CarbonImmutable::now());
        $reversed++;

        foreach ($recipes[$i] as [$code, $side, $amount]) {
            $model[$code][$side === 'debit' ? 'credit' : 'debit'] += $amount; // العكس
        }
    }

    // 1) كل رصيد = اللي النموذج المستقل حسبه (المرتجعات حساب عكسي رصيده الطبيعي مدين)
    foreach ($accounts as $account) {
        $debits = $model[$account->value]['debit'];
        $credits = $model[$account->value]['credit'];
        $natural = $account === AccountCodeEnum::SalesRefunds ? $debits - $credits : $credits - $debits;

        expect($balances->balance($account)->amount)->toBe($natural);
    }

    // 2) مجموع كل المدين = مجموع كل الدائن في الدفتر كله
    expect((int) DB::table('journal_lines')->sum('debit'))->toBe((int) DB::table('journal_lines')->sum('credit'));

    // 3) الأرصدة المخزنة = مجموع السطور، وكل قيد متوازن
    expect(app(LedgerIntegrityService::class)->problems())->toBe([]);

    // 4) التجربة فعلاً جربت إلغاءات
    expect($reversed)->toBeGreaterThan(0)
        ->and(JournalEntry::query()->count())->toBe(120 + $reversed);
});
