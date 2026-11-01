<?php

declare(strict_types=1);

namespace App\Ledger\Enums;

enum AccountCode: string
{
    case Assets = '1000';
    case GatewayClearing = '1010';
    case AccountsReceivable = '1100';
    case Liabilities = '2000';
    case TaxPayable = '2100';
    case UnappliedPayments = '2200';
    case Revenue = '4000';
    case SalesRevenue = '4010';
    case SalesRefunds = '4020';
}
