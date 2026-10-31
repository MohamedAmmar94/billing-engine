<?php

declare(strict_types=1);

use App\Shared\Money\Currency;

it('knows how many decimal places each currency has', function (Currency $currency, int $exponent) {
    expect($currency->exponent())->toBe($exponent);
})->with([
    [Currency::EGP, 2],
    [Currency::USD, 2],
    [Currency::EUR, 2],
    [Currency::JPY, 0],
]);
