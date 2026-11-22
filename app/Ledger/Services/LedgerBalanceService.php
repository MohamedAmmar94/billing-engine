<?php

declare(strict_types=1);

namespace App\Ledger\Services;

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Enums\NormalSideEnum;
use App\Ledger\Models\Account;
use App\Ledger\Models\AccountBalance;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class LedgerBalanceService
{
    /**
     * الرصيد على جهة الحساب الطبيعية. من غير تاريخ: من جدول الأرصدة (سريع).
     * بتاريخ: بيتحسب من القيود (رصيد في وقت معين).
     */
    public function balance(AccountCodeEnum $code, ?CarbonInterface $asOf = null): Money
    {
        $account = Account::query()->where('code', $code->value)->firstOrFail();

        if ($asOf === null) {
            $stored = AccountBalance::query()->whereKey($account->id)->firstOrFail();
            $debit = $stored->debit_total;
            $credit = $stored->credit_total;
        } else {
            $sums = DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.account_id', $account->id)
                ->where('e.posted_at', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
                ->first();

            $debit = (int) ($sums->debit ?? 0);
            $credit = (int) ($sums->credit ?? 0);
        }

        $net = $account->normal_side === NormalSideEnum::Debit ? $debit - $credit : $credit - $debit;

        return Money::of($net, CurrencyEnum::from($account->currency));
    }
}
