<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RefundBalance
{
    /** @var array<string,int> */
    private array $refundMinorById = [];

    public function __construct(
        private readonly string $paymentIntentId,
        private readonly Money $originalAmount,
        private Money $refundedAmount,
        private int $recordVersion = 1
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $paymentIntentId) !== 1) {
            throw new InvalidArgumentException('Refund balance payment-intent reference is invalid.');
        }
        if ($originalAmount->minorUnits() <= 0
            || $refundedAmount->currency() !== $originalAmount->currency()
            || $refundedAmount->minorUnits() > $originalAmount->minorUnits()
            || $recordVersion < 1
        ) {
            throw new InvalidArgumentException('Refund balance amounts or version are invalid.');
        }
    }

    public static function fresh(string $paymentIntentId, Money $originalAmount): self
    {
        return new self($paymentIntentId, $originalAmount, Money::zero($originalAmount->currency()));
    }

    public function reserve(string $refundId, Money $amount, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $refundId) !== 1) {
            throw new InvalidArgumentException('Refund reservation ID is invalid.');
        }
        if ($amount->minorUnits() <= 0 || $amount->currency() !== $this->originalAmount->currency()) {
            throw new InvalidArgumentException('Refund reservation amount is invalid.');
        }
        if (isset($this->refundMinorById[$refundId])) {
            if ($this->refundMinorById[$refundId] === $amount->minorUnits()) {
                return;
            }
            throw new InvariantViolation('Refund ID cannot be reused with a different amount.');
        }

        $next = $this->refundedAmount->add($amount);
        if ($next->minorUnits() > $this->originalAmount->minorUnits()) {
            throw new InvariantViolation('Cumulative refunds cannot exceed the original payment amount.');
        }

        $this->refundMinorById[$refundId] = $amount->minorUnits();
        $this->refundedAmount = $next;
        $this->recordVersion++;
    }

    public function remaining(): Money
    {
        return $this->originalAmount->subtract($this->refundedAmount);
    }

    public function refunded(): Money
    {
        return $this->refundedAmount;
    }

    public function fullyRefunded(): bool
    {
        return $this->refundedAmount->equals($this->originalAmount);
    }

    public function paymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    public function recordVersion(): int
    {
        return $this->recordVersion;
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale refund-balance record version.');
        }
    }
}
