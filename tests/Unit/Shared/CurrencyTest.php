<?php

declare(strict_types=1);

use App\Shared\Money\CurrencyEnum;

it('knows how many decimal places each currency has', function (CurrencyEnum $currency, int $exponent) {
    expect($currency->exponent())->toBe($exponent);
})->with([
    [CurrencyEnum::EGP, 2],
    [CurrencyEnum::USD, 2],
    [CurrencyEnum::EUR, 2],
    [CurrencyEnum::JPY, 0],
]);
