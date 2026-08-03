<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class Money
{
    public function __construct(
        private readonly int $minorUnits,
        private readonly string $currency
    ) {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Money cannot contain negative minor units.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase ISO-style code.');
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public static function fromDecimal(string $decimal, string $currency, int $minorUnit = 2): self
    {
        if ($minorUnit < 0 || $minorUnit > 6) {
            throw new InvalidArgumentException('Minor unit must be between 0 and 6.');
        }

        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $decimal, $matches)) {
            throw new InvalidArgumentException('Decimal money must be a non-negative canonical decimal string.');
        }

        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $minorUnit) {
            throw new InvalidArgumentException('Decimal precision exceeds the currency minor unit.');
        }

        $whole = $matches[1];
        $fraction = str_pad($fraction, $minorUnit, '0');
        $factor = 10 ** $minorUnit;

        if ((int) $whole > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidArgumentException('Money value exceeds supported integer range.');
        }

        $wholeMinorUnits = (int) $whole * $factor;
        $fractionMinorUnits = $fraction === '' ? 0 : (int) $fraction;
        if ($fractionMinorUnits > PHP_INT_MAX - $wholeMinorUnits) {
            throw new InvalidArgumentException('Money value exceeds supported integer range.');
        }

        return new self($wholeMinorUnits + $fractionMinorUnits, strtoupper($currency));
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > PHP_INT_MAX - $this->minorUnits) {
            throw new InvariantViolation('Money addition would overflow the supported integer range.');
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > $this->minorUnits) {
            throw new InvariantViolation('Money subtraction cannot produce a negative amount.');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    public function toDecimal(int $minorUnit = 2): string
    {
        if ($minorUnit < 0 || $minorUnit > 6) {
            throw new InvalidArgumentException('Minor unit must be between 0 and 6.');
        }

        if ($minorUnit === 0) {
            return (string) $this->minorUnits;
        }

        $factor = 10 ** $minorUnit;
        $whole = intdiv($this->minorUnits, $factor);
        $fraction = $this->minorUnits % $factor;

        return $whole . '.' . str_pad((string) $fraction, $minorUnit, '0', STR_PAD_LEFT);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvariantViolation('Money operations require matching currencies.');
        }
    }
}
