<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Ledger\Enums\AccountCode;
use App\Ledger\Enums\AccountType;
use App\Ledger\Enums\NormalSide;
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
            [AccountCode::Assets, 'Assets', AccountType::Asset, NormalSide::Debit, false, null],
            [AccountCode::GatewayClearing, 'Gateway Clearing', AccountType::Asset, NormalSide::Debit, true, AccountCode::Assets],
            [AccountCode::AccountsReceivable, 'Accounts Receivable', AccountType::Asset, NormalSide::Debit, true, AccountCode::Assets],
            [AccountCode::Liabilities, 'Liabilities', AccountType::Liability, NormalSide::Credit, false, null],
            [AccountCode::TaxPayable, 'Tax Payable', AccountType::Liability, NormalSide::Credit, true, AccountCode::Liabilities],
            [AccountCode::UnappliedPayments, 'Unapplied Payments', AccountType::Liability, NormalSide::Credit, true, AccountCode::Liabilities],
            [AccountCode::Revenue, 'Revenue', AccountType::Revenue, NormalSide::Credit, false, null],
            [AccountCode::SalesRevenue, 'Sales Revenue', AccountType::Revenue, NormalSide::Credit, true, AccountCode::Revenue],
            [AccountCode::SalesRefunds, 'Sales Refunds', AccountType::Revenue, NormalSide::Debit, true, AccountCode::Revenue],
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
