<?php

declare(strict_types=1);

use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\CurrencyMismatchException;
use App\Shared\Money\Money;

// php artisan test tests/Unit/Shared/MoneyTest.php

it('stores the amount in minor units together with its currency', function () {
    $money = Money::of(1050, CurrencyEnum::EGP);

    expect($money->amount)->toBe(1050)
        ->and($money->currency)->toBe(CurrencyEnum::EGP);
});

it('is equal to another money with the same amount and currency', function () {
    expect(Money::of(100, CurrencyEnum::EGP)->equals(Money::of(100, CurrencyEnum::EGP)))->toBeTrue();
});

it('is not equal when the amount differs', function () {
    expect(Money::of(100, CurrencyEnum::EGP)->equals(Money::of(101, CurrencyEnum::EGP)))->toBeFalse();
});

it('is not equal when the currency differs', function () {
    expect(Money::of(100, CurrencyEnum::EGP)->equals(Money::of(100, CurrencyEnum::USD)))->toBeFalse();
});

it('rejects float amounts instead of silently converting them', function () {
    Money::of(10.5, CurrencyEnum::EGP);
})->throws(TypeError::class);

it('adds amounts in the same currency', function () {
    $result = Money::of(1050, CurrencyEnum::EGP)->add(Money::of(250, CurrencyEnum::EGP));

    expect($result->amount)->toBe(1300)
        ->and($result->currency)->toBe(CurrencyEnum::EGP);
});

it('subtracts amounts in the same currency', function () {
    $result = Money::of(1050, CurrencyEnum::EGP)->subtract(Money::of(250, CurrencyEnum::EGP));

    expect($result->amount)->toBe(800);
});

it('allows a negative result when subtracting', function () {
    $result = Money::of(100, CurrencyEnum::EGP)->subtract(Money::of(250, CurrencyEnum::EGP));

    expect($result->amount)->toBe(-150);
});

it('does not change the original when adding (immutability)', function () {
    $original = Money::of(100, CurrencyEnum::EGP);

    $original->add(Money::of(50, CurrencyEnum::EGP));

    expect($original->amount)->toBe(100);
});

it('refuses to add different currencies', function () {
    Money::of(100, CurrencyEnum::EGP)->add(Money::of(100, CurrencyEnum::USD));
})->throws(CurrencyMismatchException::class);

it('refuses to subtract different currencies', function () {
    Money::of(100, CurrencyEnum::EGP)->subtract(Money::of(100, CurrencyEnum::USD));
})->throws(CurrencyMismatchException::class);

it('creates a zero amount', function () {
    $zero = Money::zero(CurrencyEnum::USD);

    expect($zero->amount)->toBe(0)
        ->and($zero->currency)->toBe(CurrencyEnum::USD);
});

it('multiplies by an integer factor', function () {
    expect(Money::of(250, CurrencyEnum::EGP)->multiply(3)->amount)->toBe(750);
});

it('negates an amount', function () {
    expect(Money::of(250, CurrencyEnum::EGP)->negate()->amount)->toBe(-250)
        ->and(Money::of(-250, CurrencyEnum::EGP)->negate()->amount)->toBe(250);
});

it('compares amounts in the same currency', function () {
    $small = Money::of(100, CurrencyEnum::EGP);
    $big = Money::of(200, CurrencyEnum::EGP);

    expect($big->greaterThan($small))->toBeTrue()
        ->and($small->greaterThan($big))->toBeFalse()
        ->and($small->greaterThan($small))->toBeFalse()
        ->and($small->lessThan($big))->toBeTrue()
        ->and($big->lessThan($small))->toBeFalse();
});

it('refuses to compare different currencies', function () {
    Money::of(100, CurrencyEnum::EGP)->greaterThan(Money::of(100, CurrencyEnum::USD));
})->throws(CurrencyMismatchException::class);

it('knows whether it is zero, positive or negative', function (int $amount, bool $zero, bool $positive, bool $negative) {
    $money = Money::of($amount, CurrencyEnum::EGP);

    expect($money->isZero())->toBe($zero)
        ->and($money->isPositive())->toBe($positive)
        ->and($money->isNegative())->toBe($negative);
})->with([
    'zero' => [0, true, false, false],
    'positive' => [5, false, true, false],
    'negative' => [-5, false, false, true],
]);

it('calculates basis-point percentages rounding half away from zero', function (int $amount, int $bp, int $expected) {
    expect(Money::of($amount, CurrencyEnum::EGP)->percentage($bp)->amount)->toBe($expected);
})->with([
    '14% of 10.50 is exact' => [1050, 1400, 147],
    '14% of 0.99 is 13.86 so rounds up' => [99, 1400, 14],
    '10% of 0.04 is 0.4 so rounds down' => [4, 1000, 0],
    '10% of 0.05 is exactly half so rounds up' => [5, 1000, 1],
    'negative half rounds away from zero' => [-5, 1000, -1],
    'negative below half rounds toward zero' => [-4, 1000, 0],
    '100% is the same amount' => [1234, 10000, 1234],
    '0% is zero' => [1234, 0, 0],
    'zero stays zero' => [0, 1400, 0],
]);
it('keeps the currency when calculating a percentage', function () {
    expect(Money::of(1000, CurrencyEnum::USD)->percentage(1400)->currency)->toBe(CurrencyEnum::USD);
});
it('splits 1.00 EGP between 3 people without losing a piastre', function () {
    $parts = Money::of(100, CurrencyEnum::EGP)->split(3);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([34, 33, 33]);
});

it('allocates by ratios', function () {
    $parts = Money::of(100, CurrencyEnum::EGP)->allocate(70, 30);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([70, 30]);
});

it('gives the leftover to the first parts', function () {
    $parts = Money::of(101, CurrencyEnum::EGP)->allocate(70, 30);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([71, 30]);
});

it('can allocate fewer minor units than parts', function () {
    $parts = Money::of(1, CurrencyEnum::EGP)->split(3);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([1, 0, 0]);
});

it('allocates negative amounts symmetrically', function () {
    $parts = Money::of(-100, CurrencyEnum::EGP)->split(3);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([-34, -33, -33]);
});

it('gives nothing to a zero ratio, even when there is a leftover', function () {
    $parts = Money::of(101, CurrencyEnum::EGP)->allocate(1, 0, 1);

    expect(array_map(fn (Money $m): int => $m->amount, $parts))->toBe([51, 0, 50]);
});

it('keeps the currency in every part', function () {
    $parts = Money::of(100, CurrencyEnum::USD)->split(2);

    expect($parts[0]->currency)->toBe(CurrencyEnum::USD)
        ->and($parts[1]->currency)->toBe(CurrencyEnum::USD);
});

it('never loses or creates a single minor unit when allocating (1000 random cases)', function () {
    mt_srand(42); // seed ثابت: لو فشل، نعيد نفس الحالة بالظبط

    for ($i = 0; $i < 1000; $i++) {
        $amount = mt_rand(-1_000_000, 1_000_000);
        $ratios = array_map(fn () => mt_rand(0, 100), range(1, mt_rand(1, 7)));

        if (array_sum($ratios) === 0) {
            continue;
        }

        $parts = Money::of($amount, CurrencyEnum::EGP)->allocate(...$ratios);

        expect(array_sum(array_map(fn (Money $m): int => $m->amount, $parts)))->toBe($amount);
    }
});

it('rejects invalid allocation ratios', function (array $ratios) {
    Money::of(100, CurrencyEnum::EGP)->allocate(...$ratios);
})->with([
    'no ratios' => [[]],
    'negative ratio' => [[1, -1]],
    'all zero' => [[0, 0]],
])->throws(InvalidArgumentException::class);

it('rejects splitting into fewer than one part', function (int $parts) {
    Money::of(100, CurrencyEnum::EGP)->split($parts);
})->with([0, -1])->throws(InvalidArgumentException::class);
it('fails clearly when addition overflows', function () {
    Money::of(PHP_INT_MAX, CurrencyEnum::EGP)->add(Money::of(1, CurrencyEnum::EGP));
})->throws(OverflowException::class);

it('fails clearly when subtraction overflows', function () {
    Money::of(-PHP_INT_MAX, CurrencyEnum::EGP)->subtract(Money::of(2, CurrencyEnum::EGP));
})->throws(OverflowException::class);

it('fails clearly when multiplication overflows', function () {
    Money::of(PHP_INT_MAX, CurrencyEnum::EGP)->multiply(2);
})->throws(OverflowException::class);

it('fails clearly when a percentage overflows', function () {
    Money::of(PHP_INT_MAX, CurrencyEnum::EGP)->percentage(2);
})->throws(OverflowException::class);

it('rejects the smallest integer because its absolute value does not fit', function () {
    Money::of(PHP_INT_MIN, CurrencyEnum::EGP);
})->throws(OverflowException::class);

it('formats a decimal string for display', function (int $amount, CurrencyEnum $currency, string $expected) {
    expect(Money::of($amount, $currency)->toDecimalString())->toBe($expected);
})->with([
    'whole amount' => [1050, CurrencyEnum::EGP, '10.50'],
    'less than one unit' => [5, CurrencyEnum::EGP, '0.05'],
    'negative less than one unit' => [-5, CurrencyEnum::EGP, '-0.05'],
    'negative amount' => [-1050, CurrencyEnum::EGP, '-10.50'],
    'zero' => [0, CurrencyEnum::USD, '0.00'],
    'currency without minor units' => [500, CurrencyEnum::JPY, '500'],
]);
