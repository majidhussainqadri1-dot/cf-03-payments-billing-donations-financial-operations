<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\FinancialEventEnvelope;
use Sabri\CF03\Support\InvariantViolation;

final class OutboxMessage
{
    public function __construct(
        private readonly FinancialEventEnvelope $event,
        private readonly DateTimeImmutable $availableAt,
        private string $state = 'pending',
        private int $attempts = 0,
        private ?DateTimeImmutable $leasedUntil = null,
        private ?string $lastErrorCode = null
    ) {
        if (! in_array($state, ['pending', 'leased', 'delivered', 'dead_letter'], true) || $attempts < 0 || $attempts > 100) {
            throw new InvalidArgumentException('Outbox message state or attempts are invalid.');
        }
    }

    public function lease(DateTimeImmutable $now, int $leaseSeconds = 60): void
    {
        if ($leaseSeconds < 10 || $leaseSeconds > 900) {
            throw new InvalidArgumentException('Outbox lease duration is invalid.');
        }
        if ($this->state === 'delivered' || $this->state === 'dead_letter') {
            throw new InvariantViolation('Final outbox message cannot be leased.');
        }
        if ($now < $this->availableAt) {
            throw new InvariantViolation('Outbox message is not available yet.');
        }
        if ($this->state === 'leased' && $this->leasedUntil !== null && $now < $this->leasedUntil) {
            throw new InvariantViolation('Outbox message lease is still active.');
        }
        $this->state = 'leased';
        $this->leasedUntil = $now->modify('+' . $leaseSeconds . ' seconds');
        $this->attempts++;
    }

    public function delivered(): void
    {
        if ($this->state !== 'leased') {
            throw new InvariantViolation('Outbox delivery requires an active lease.');
        }
        $this->state = 'delivered';
        $this->leasedUntil = null;
        $this->lastErrorCode = null;
    }

    public function fail(string $errorCode, DateTimeImmutable $retryAt, int $maximumAttempts = 8): void
    {
        if ($this->state !== 'leased' || preg_match('/^[a-z0-9][a-z0-9._:-]{2,63}$/', $errorCode) !== 1) {
            throw new InvariantViolation('Outbox failure requires active lease and safe error code.');
        }
        $this->lastErrorCode = $errorCode;
        $this->leasedUntil = null;
        if ($this->attempts >= $maximumAttempts) {
            $this->state = 'dead_letter';
            return;
        }
        $this->state = 'pending';
        if ($retryAt <= $this->availableAt) {
            throw new InvalidArgumentException('Outbox retry time must move forward.');
        }
    }

    public function event(): FinancialEventEnvelope { return $this->event; }
    public function state(): string { return $this->state; }
    public function attempts(): int { return $this->attempts; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
}
