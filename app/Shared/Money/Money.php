<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    private function __construct(
        public int $amount,
        public CurrencyEnum $currency,
    ) {
        // abs() و negate() على أصغر int بيرجعوا float، فبنرفضه من الأول
        if ($amount === PHP_INT_MIN) {
            throw new OverflowException('Amount is out of the supported range.');
        }
    }

    public static function of(int $amount, CurrencyEnum $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(CurrencyEnum $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amount + $other->amount), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amount - $other->amount), $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self(self::checked($this->amount * $factor), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    /**
     * نسبة مئوية بالـ basis points (1 bp = 0.01%، يعني 14% = 1400).
     * التقريب: لو الباقي نص أو أكتر نقرّب بعيد عن الصفر.
     */
    public function percentage(int $basisPoints): self
    {
        $product = self::checked($this->amount * $basisPoints);
        $quotient = intdiv($product, 10_000);
        $remainder = $product % 10_000;

        if (abs($remainder) * 2 >= 10_000) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return new self($quotient, $this->currency);
    }

    /**
     * يقسم المبلغ حسب النسب من غير ما يضيع أو يزيد ولا وحدة صغرى.
     * الباقي بيتوزع وحدة وحدة على أول النسب الأكبر من صفر.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('At least one ratio is required.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Ratios must not be negative.');
            }
        }

        $total = self::checked(array_sum($ratios));

        if ($total <= 0) {
            throw new InvalidArgumentException('The sum of ratios must be greater than zero.');
        }

        $sign = $this->amount < 0 ? -1 : 1;
        $abs = abs($this->amount);

        $shares = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv(self::checked($abs * $ratio), $total);
            $shares[] = $share;
            $allocated += $share;
        }

        $remainder = $abs - $allocated;

        for ($i = 0; $remainder > 0; $i++) {
            if ($ratios[$i] > 0) {
                $shares[$i]++;
                $remainder--;
            }
        }

        return array_map(
            fn (int $share): self => new self($sign * $share, $this->currency),
            $shares,
        );
    }

    /** @return list<self> */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least 1.');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
        && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /** للعرض فقط، عمرنا ما نحسب بالنتيجة دي. مثال: 1050 → "10.50" */
    public function toDecimalString(): string
    {
        $exponent = $this->currency->exponent();

        if ($exponent === 0) {
            return (string) $this->amount;
        }

        $divisor = (int) (10 ** $exponent);
        $abs = abs($this->amount);

        return sprintf(
            '%s%d.%0'.$exponent.'d',
            $this->amount < 0 ? '-' : '',
            intdiv($abs, $divisor),
            $abs % $divisor,
        );
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    /**
     * لو PHP حوّل النتيجة لـ float بسبب overflow، بنرمي exception واضح
     * بدل ما نسيب TypeError غامضة تطلع.
     */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new OverflowException('Money arithmetic overflowed.');
        }

        return $value;
    }
}
