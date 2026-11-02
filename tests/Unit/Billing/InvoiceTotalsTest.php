<?php

declare(strict_types=1);

use App\Billing\Support\InvoiceCalculatorService;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\CurrencyMismatchException;
use App\Shared\Money\Money;

it('calculates subtotal, tax and total', function (array $lines, int $rateBp, int $subtotal, int $tax, int $total) {
    $result = (new InvoiceCalculatorService)->totals(
        CurrencyEnum::USD,
        array_map(fn (int $cents): Money => Money::of($cents, CurrencyEnum::USD), $lines),
        $rateBp,
    );

    expect($result->subtotal->amount)->toBe($subtotal)
        ->and($result->tax->amount)->toBe($tax)
        ->and($result->total->amount)->toBe($total);
})->with([
    'the reference invoice (99 + 2 x 15 + 50 at 14%)' => [[9900, 3000, 5000], 1400, 17900, 2506, 20406],
    'tax is rounded half away from zero' => [[357], 1400, 357, 50, 407],
    'tax is computed once on the subtotal, not per line' => [[5, 5, 5], 1400, 15, 2, 17],
    'half a cent rounds up' => [[5], 1000, 5, 1, 6],
    'zero tax rate' => [[1000], 0, 1000, 0, 1000],
    'no lines' => [[], 1400, 0, 0, 0],
]);

it('refuses a negative tax rate', function () {
    (new InvoiceCalculatorService)->totals(CurrencyEnum::USD, [], -1);
})->throws(InvalidArgumentException::class);

it('refuses line totals in another currency', function () {
    (new InvoiceCalculatorService)->totals(CurrencyEnum::USD, [Money::of(100, CurrencyEnum::EGP)], 1400);
})->throws(CurrencyMismatchException::class);
