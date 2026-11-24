<?php

declare(strict_types=1);

namespace App\Ledger\Services;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Models\Invoice;
use App\Ledger\Enums\AccountCodeEnum;
use App\Payments\Models\Payment;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * بيفحص إن الدفتر سليم، وإنه متطابق مع الفواتير والدفعات.
 * لما نضيف الـ refunds (Phase 5) فحص الـ Clearing والـ Unapplied هيطرح المرتجعات.
 */
final class LedgerIntegrityService
{
    public function __construct(private readonly LedgerBalanceService $balances) {}

    /** @return list<string> */
    public function problems(): array
    {
        return [
            ...$this->globalBalance(),
            ...$this->unbalancedEntries(),
            ...$this->storedBalancesMatchLines(),
            ...$this->controlAccounts(),
        ];
    }

    /** @return list<string> */
    private function globalBalance(): array
    {
        $totals = DB::table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $debit = (int) ($totals->debit ?? 0);
        $credit = (int) ($totals->credit ?? 0);

        return $debit === $credit
            ? []
            : ["Total debits ({$debit}) differ from total credits ({$credit})."];
    }

    /** @return list<string> */
    private function unbalancedEntries(): array
    {
        $ids = DB::table('journal_lines')
            ->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit) <> SUM(credit)')
            ->pluck('journal_entry_id');

        return $ids->map(fn (mixed $id): string => "Journal entry {$id} is not balanced.")->values()->all();
    }

    /** @return list<string> */
    private function storedBalancesMatchLines(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.code,
                   COALESCE(b.debit_total, 0)  AS stored_debit,
                   COALESCE(b.credit_total, 0) AS stored_credit,
                   COALESCE(s.debit, 0)        AS lines_debit,
                   COALESCE(s.credit, 0)       AS lines_credit
            FROM accounts a
            LEFT JOIN account_balances b ON b.account_id = a.id
            LEFT JOIN (
                SELECT account_id, SUM(debit) AS debit, SUM(credit) AS credit
                FROM journal_lines GROUP BY account_id
            ) s ON s.account_id = a.id
            WHERE COALESCE(b.debit_total, 0)  <> COALESCE(s.debit, 0)
               OR COALESCE(b.credit_total, 0) <> COALESCE(s.credit, 0)
            ORDER BY a.code
        SQL);

        $problems = [];

        foreach ($rows as $row) {
            $problems[] = sprintf(
                'Account %s: stored balance (Dr %d / Cr %d) does not match its journal lines (Dr %d / Cr %d).',
                $row->code,
                (int) $row->stored_debit,
                (int) $row->stored_credit,
                (int) $row->lines_debit,
                (int) $row->lines_credit,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function controlAccounts(): array
    {
        $currency = config()->string('billing.currency');

        $openInvoices = (int) Invoice::query()
            ->where('status', InvoiceStatusEnum::Open->value)
            ->where('currency', $currency)
            ->sum('total');

        $received = (int) Payment::query()
            ->where('currency', $currency)
            ->where('amount', '>', 0)
            ->sum('amount');

        $unapplied = (int) Payment::query()
            ->where('currency', $currency)
            ->where('amount', '>', 0)
            ->where('is_applied', false)
            ->sum('amount');

        return [
            ...$this->expect(AccountCodeEnum::AccountsReceivable, $openInvoices, 'open invoices'),
            ...$this->expect(AccountCodeEnum::GatewayClearing, $received, 'payments received'),
            ...$this->expect(AccountCodeEnum::UnappliedPayments, $unapplied, 'unapplied payments'),
        ];
    }

    /** @return list<string> */
    private function expect(AccountCodeEnum $account, int $expected, string $against): array
    {
        $actual = $this->balances->balance($account)->amount;

        if ($actual === $expected) {
            return [];
        }

        $currency = CurrencyEnum::from(config()->string('billing.currency'));

        return [sprintf(
            'Account %s: the ledger says %s but %s total %s (%s).',
            $account->value,
            Money::of($actual, $currency)->toDecimalString(),
            $against,
            Money::of($expected, $currency)->toDecimalString(),
            $currency->value,
        )];
    }
}
