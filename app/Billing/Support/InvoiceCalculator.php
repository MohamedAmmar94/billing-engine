<?php

declare (strict_types = 1);

namespace App\Billing\Support;

use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use InvalidArgumentException;

final class InvoiceCalculator {
    /**
     * الضريبة بتتحسب مرة واحدة على الـ subtotal (مش على كل بند)
     * عشان تقريب كل بند ما يتراكمش.
     *
     * @param  list<Money>  $lineTotals
     */
    public function totals(Currency $currency, array $lineTotals, int $taxRateBp): InvoiceTotals {
        if ($taxRateBp < 0) {
            throw new InvalidArgumentException('Tax rate must not be negative.');
        }

        $subtotal = array_reduce(
            $lineTotals,
            fn(Money $carry, Money $line): Money => $carry->add($line),
            Money::zero($currency),
        );

        $tax = $subtotal->percentage($taxRateBp);

        return new InvoiceTotals($subtotal, $tax, $subtotal->add($tax));
    }
}