<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function guardAccount(string $code): int
{
    return DB::table('accounts')->insertGetId([
        'code' => $code,
        'name' => "Account {$code}",
        'type' => 'asset',
        'normal_side' => 'debit',
    ]);
}

function guardEntry(): int
{
    return DB::table('journal_entries')->insertGetId([
        'posted_at' => now(),
        'description' => 'Guard test',
        'source_type' => 'invoice',
        'source_id' => 1,
        'event' => 'opened',
    ]);
}

function guardLine(int $entryId, int $accountId, int $debit, int $credit): void
{
    DB::table('journal_lines')->insert([
        'journal_entry_id' => $entryId,
        'account_id' => $accountId,
        'debit' => $debit,
        'credit' => $credit,
    ]);
}

it('accepts a balanced entry', function () {
    $entry = guardEntry();
    guardLine($entry, guardAccount('A'), 1000, 0);
    guardLine($entry, guardAccount('B'), 0, 1000);

    // الفحص المؤجل بيشتغل عند COMMIT، والـ test عمره ما بيعمل commit، فبنطلبه يدوياً
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    expect(DB::table('journal_lines')->count())->toBe(2);
});

it('rejects an unbalanced entry', function () {
    $entry = guardEntry();
    guardLine($entry, guardAccount('A'), 1000, 0);
    guardLine($entry, guardAccount('B'), 0, 900);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->throws(QueryException::class, 'is not balanced');

it('rejects a line that is not strictly one-sided', function (int $debit, int $credit) {
    guardLine(guardEntry(), guardAccount('A'), $debit, $credit);
})->with([
    'debit and credit together' => [100, 100],
    'both zero' => [0, 0],
    'negative amount' => [-5, 0],
])->throws(QueryException::class, 'journal_lines_one_side_positive');
