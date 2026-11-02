<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Enums\AccountTypeEnum;
use App\Ledger\Enums\NormalSideEnum;
use App\Ledger\Models\Account;
use App\Ledger\Models\AccountBalance;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [AccountCodeEnum::Assets, 'Assets', AccountTypeEnum::Asset, NormalSideEnum::Debit, false, null],
            [AccountCodeEnum::GatewayClearing, 'Gateway Clearing', AccountTypeEnum::Asset, NormalSideEnum::Debit, true, AccountCodeEnum::Assets],
            [AccountCodeEnum::AccountsReceivable, 'Accounts Receivable', AccountTypeEnum::Asset, NormalSideEnum::Debit, true, AccountCodeEnum::Assets],
            [AccountCodeEnum::Liabilities, 'Liabilities', AccountTypeEnum::Liability, NormalSideEnum::Credit, false, null],
            [AccountCodeEnum::TaxPayable, 'Tax Payable', AccountTypeEnum::Liability, NormalSideEnum::Credit, true, AccountCodeEnum::Liabilities],
            [AccountCodeEnum::UnappliedPayments, 'Unapplied Payments', AccountTypeEnum::Liability, NormalSideEnum::Credit, true, AccountCodeEnum::Liabilities],
            [AccountCodeEnum::Revenue, 'Revenue', AccountTypeEnum::Revenue, NormalSideEnum::Credit, false, null],
            [AccountCodeEnum::SalesRevenue, 'Sales Revenue', AccountTypeEnum::Revenue, NormalSideEnum::Credit, true, AccountCodeEnum::Revenue],
            [AccountCodeEnum::SalesRefunds, 'Sales Refunds', AccountTypeEnum::Revenue, NormalSideEnum::Debit, true, AccountCodeEnum::Revenue],
        ];

        $ids = [];

        foreach ($accounts as [$code, $name, $type, $side, $postable, $parent]) {
            $account = Account::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $name,
                    'type' => $type,
                    'normal_side' => $side,
                    'is_postable' => $postable,
                    'parent_id' => $parent === null ? null : $ids[$parent->value],
                ],
            );

            $ids[$code->value] = $account->id;

            if ($postable) {
                AccountBalance::query()->firstOrCreate(['account_id' => $account->id]);
            }
        }
    }
}
