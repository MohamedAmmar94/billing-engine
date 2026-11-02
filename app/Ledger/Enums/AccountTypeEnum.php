<?php

declare(strict_types=1);

namespace App\Ledger\Enums;

enum AccountTypeEnum: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';
}
