<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use InvalidArgumentException;

final class InvoiceCalculatorService
{
    /**
     * الضريبة بتتحسب مرة واحدة على الـ subtotal (مش على كل بند)
     * عشان تقريب كل بند ما يتراكمش.
     *
     * @param  list<Money>  $lineTotals
     */
    public function totals(CurrencyEnum $currency, array $lineTotals, int $taxRateBp): InvoiceTotalsDTO
    {
        if ($taxRateBp < 0) {
            throw new InvalidArgumentException('Tax rate must not be negative.');
        }

        // $subtotal = array_reduce(
        //     $lineTotals,
        //     fn (Money $carry, Money $line): Money => $carry->add($line),
        //     Money::zero($currency),
        // );
        $subtotal = Money::zero($currency);
        foreach ($lineTotals as $line) {
            $subtotal = $subtotal->add($line);
        }
        $tax = $subtotal->percentage($taxRateBp);

        return new InvoiceTotalsDTO($subtotal, $tax, $subtotal->add($tax));
    }
}
