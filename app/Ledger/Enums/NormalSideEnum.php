<?php

declare(strict_types=1);

namespace App\Ledger\Enums;

enum NormalSideEnum: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
