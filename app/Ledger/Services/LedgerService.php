<?php

declare(strict_types=1);

namespace App\Ledger\Services;

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Enums\NormalSideEnum;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\UnbalancedEntryException;
use App\Ledger\Models\Account;
use App\Ledger\Models\AccountBalance;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Models\JournalLine;
use App\Ledger\Support\JournalEntryDTO;
use App\Ledger\Support\JournalLineDTO;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الكاتب الوحيد للدفتر. بيتنده جوه transaction العملية اللي سببت القيد
 * (listeners متزامنة)، فالقيد والعملية بينجحوا أو يفشلوا مع بعض.
 */
final class LedgerService
{
    public function post(JournalEntryDTO $entry): JournalEntry
    {
        $this->assertValid($entry);

        return DB::transaction(function () use ($entry): JournalEntry {
            $accounts = $this->resolveAccounts($entry);

            // القفل الأول، وبعدها فحص التكرار: لو طلبين متوازيين، التاني بيستنى وبعدين يشوف القيد
            $this->lockBalances($accounts);
            $this->assertNotPostedBefore($entry);

            $journalEntry = JournalEntry::query()->create([
                'posted_at' => $entry->postedAt,
                'description' => $entry->description,
                'currency' => $entry->currency->value,
                'source_type' => $entry->sourceType,
                'source_id' => $entry->sourceId,
                'event' => $entry->event,
                'reverses_entry_id' => $entry->reversesEntryId,
            ]);

            // INSERT واحد لكل السطور: الـ trigger بيفحص بعد ما كلها تكون موجودة
            JournalLine::query()->insert($this->lineRows($journalEntry, $entry, $accounts));

            $this->applyToBalances($entry, $accounts);
            $this->checkBalanceInDatabase();

            return $journalEntry;
        });
    }

    /** قيد عكسي: نفس الأرقام بجهات مقلوبة، ومربوط بالأصلي (ينعكس مرة واحدة بس). */
    public function reverse(
        JournalEntry $original,
        string $sourceType,
        int $sourceId,
        string $event,
        string $description,
        CarbonImmutable $postedAt,
    ): JournalEntry {
        $currency = CurrencyEnum::from($original->currency);

        $rows = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_entry_id', $original->id)
            ->orderBy('l.id')
            ->get(['a.code', 'l.debit', 'l.credit']);

        $lines = [];

        foreach ($rows as $row) {
            $code = AccountCodeEnum::from((string) $row->code);

            $lines[] = (int) $row->debit > 0
                ? JournalLineDTO::credit($code, Money::of((int) $row->debit, $currency))
                : JournalLineDTO::debit($code, Money::of((int) $row->credit, $currency));
        }

        return $this->post(new JournalEntryDTO(
            description: $description,
            postedAt: $postedAt,
            currency: $currency,
            sourceType: $sourceType,
            sourceId: $sourceId,
            event: $event,
            lines: $lines,
            reversesEntryId: $original->id,
        ));
    }

    private function assertValid(JournalEntryDTO $entry): void
    {
        if (count($entry->lines) < 2) {
            throw LedgerException::tooFewLines();
        }

        $debit = Money::zero($entry->currency);
        $credit = Money::zero($entry->currency);

        foreach ($entry->lines as $line) {
            if ($line->amount->currency !== $entry->currency) {
                throw LedgerException::lineCurrencyMismatch($line->account->value, $entry->currency->value);
            }

            if ($line->side === NormalSideEnum::Debit) {
                $debit = $debit->add($line->amount);
            } else {
                $credit = $credit->add($line->amount);
            }
        }

        if (! $debit->equals($credit)) {
            throw new UnbalancedEntryException($debit, $credit);
        }
    }

    /** @return Collection<int, Account> */
    private function resolveAccounts(JournalEntryDTO $entry): Collection
    {
        $codes = array_values(array_unique(array_map(
            fn (JournalLineDTO $line): string => $line->account->value,
            $entry->lines,
        )));

        $accounts = Account::query()->whereIn('code', $codes)->get();

        foreach ($codes as $code) {
            $account = $accounts->firstWhere('code', $code);

            if ($account === null) {
                throw LedgerException::unknownAccount($code);
            }

            if (! $account->is_postable) {
                throw LedgerException::accountNotPostable($code);
            }

            if ($account->currency !== $entry->currency->value) {
                throw LedgerException::accountCurrencyMismatch($code, $entry->currency->value);
            }
        }

        return $accounts;
    }

    /**
     * ترتيب ثابت (account_id تصاعدي) لكل الطلبات: طلبين ما يقدروش يمسكوا نفس الحسابين
     * بترتيبين مختلفين، فمفيش deadlock.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function lockBalances(Collection $accounts): void
    {
        $ids = $accounts->pluck('id')->sort()->values()->all();

        $locked = AccountBalance::query()
            ->whereIn('account_id', $ids)
            ->orderBy('account_id')
            ->lockForUpdate()
            ->get();

        if ($locked->count() !== count($ids)) {
            throw LedgerException::missingBalanceRow();
        }
    }

    private function assertNotPostedBefore(JournalEntryDTO $entry): void
    {
        $posted = JournalEntry::query()
            ->where('source_type', $entry->sourceType)
            ->where('source_id', $entry->sourceId)
            ->where('event', $entry->event)
            ->exists();

        if ($posted) {
            throw LedgerException::alreadyPosted($entry->sourceType, $entry->sourceId, $entry->event);
        }

        if ($entry->reversesEntryId !== null
            && JournalEntry::query()->where('reverses_entry_id', $entry->reversesEntryId)->exists()) {
            throw LedgerException::alreadyReversed($entry->reversesEntryId);
        }
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return list<array{journal_entry_id: int, account_id: int, debit: int, credit: int}>
     */
    private function lineRows(JournalEntry $journalEntry, JournalEntryDTO $entry, Collection $accounts): array
    {
        $rows = [];

        foreach ($entry->lines as $line) {
            $rows[] = [
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $this->accountFor($accounts, $line->account)->id,
                'debit' => $line->side === NormalSideEnum::Debit ? $line->amount->amount : 0,
                'credit' => $line->side === NormalSideEnum::Credit ? $line->amount->amount : 0,
            ];
        }

        return $rows;
    }

    /** @param  Collection<int, Account>  $accounts */
    private function applyToBalances(JournalEntryDTO $entry, Collection $accounts): void
    {
        $totals = [];

        foreach ($entry->lines as $line) {
            $accountId = $this->accountFor($accounts, $line->account)->id;
            $totals[$accountId] ??= ['debit' => 0, 'credit' => 0];
            $totals[$accountId][$line->side === NormalSideEnum::Debit ? 'debit' : 'credit'] += $line->amount->amount;
        }

        ksort($totals);

        foreach ($totals as $accountId => $sum) {
            DB::table('account_balances')->where('account_id', $accountId)->update([
                'debit_total' => DB::raw('debit_total + '.(int) $sum['debit']),
                'credit_total' => DB::raw('credit_total + '.(int) $sum['credit']),
                'updated_at' => now(),
            ]);
        }
    }

    /** الـ trigger مؤجل لحد الـ COMMIT، فبنجبره يفحص دلوقتي عشان الفشل يبان هنا. */
    private function checkBalanceInDatabase(): void
    {
        DB::statement('SET CONSTRAINTS journal_lines_must_balance IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_lines_must_balance DEFERRED');
    }

    /** @param  Collection<int, Account>  $accounts */
    private function accountFor(Collection $accounts, AccountCodeEnum $code): Account
    {
        return $accounts->firstWhere('code', $code->value) ?? throw LedgerException::unknownAccount($code->value);
    }
}
