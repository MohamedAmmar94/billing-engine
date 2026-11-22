<?php

declare(strict_types=1);

namespace App\Ledger\Exceptions;

use App\Shared\Money\Money;

final class UnbalancedEntryException extends LedgerException
{
    public function __construct(public readonly Money $debit, public readonly Money $credit)
    {
        parent::__construct(sprintf(
            'Journal entry is not balanced: debit %s <> credit %s (%s).',
            $debit->toDecimalString(),
            $credit->toDecimalString(),
            $debit->currency->value,
        ));
    }
}
