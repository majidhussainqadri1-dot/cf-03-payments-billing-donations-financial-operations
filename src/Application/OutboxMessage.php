<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\FinancialEventEnvelope;
use Sabri\CF03\Support\InvariantViolation;

final class OutboxMessage
{
    private ?DateTimeImmutable $lastAttemptAt = null;

    public function __construct(
        private readonly FinancialEventEnvelope $event,
        private DateTimeImmutable $availableAt,
        private string $state = 'pending',
        private int $attempts = 0,
        private ?DateTimeImmutable $leasedUntil = null,
        private ?string $lastErrorCode = null
    ) {
        if (! in_array($state, ['pending', 'leased', 'delivered', 'dead_letter'], true) || $attempts < 0 || $attempts > 100) {
            throw new InvalidArgumentException('Outbox message state or attempts are invalid.');
        }
        if (($state === 'leased') !== ($leasedUntil !== null)) {
            throw new InvalidArgumentException('Outbox leased state and lease deadline are inconsistent.');
        }
    }

    public function lease(DateTimeImmutable $now, int $leaseSeconds = 60, int $maximumAttempts = 8): void
    {
        if ($leaseSeconds < 10 || $leaseSeconds > 900 || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Outbox lease duration or maximum attempts are invalid.');
        }
        if ($this->state === 'delivered' || $this->state === 'dead_letter') {
            throw new InvariantViolation('Final outbox message cannot be leased.');
        }
        if ($this->attempts >= $maximumAttempts) {
            $this->state = 'dead_letter';
            $this->leasedUntil = null;
            throw new InvariantViolation('Outbox delivery attempts are exhausted.');
        }
        if ($now < $this->availableAt) {
            throw new InvariantViolation('Outbox message is not available yet.');
        }
        if ($this->state === 'leased' && $this->leasedUntil !== null && $now < $this->leasedUntil) {
            throw new InvariantViolation('Outbox message lease is still active.');
        }
        $this->state = 'leased';
        $this->lastAttemptAt = $now;
        $this->leasedUntil = $now->modify('+' . $leaseSeconds . ' seconds');
        $this->attempts++;
    }

    public function delivered(DateTimeImmutable $at): void
    {
        if ($this->state !== 'leased' || $this->lastAttemptAt === null || $at < $this->lastAttemptAt) {
            throw new InvariantViolation('Outbox delivery requires an active chronologically valid lease.');
        }
        $this->state = 'delivered';
        $this->leasedUntil = null;
        $this->lastErrorCode = null;
    }

    public function fail(string $errorCode, DateTimeImmutable $failedAt, DateTimeImmutable $retryAt, int $maximumAttempts = 8): void
    {
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Outbox maximum attempts are invalid.');
        }
        if ($this->state !== 'leased'
            || $this->lastAttemptAt === null
            || $failedAt < $this->lastAttemptAt
            || preg_match('/^[a-z0-9][a-z0-9._:-]{2,63}$/', $errorCode) !== 1
        ) {
            throw new InvariantViolation('Outbox failure requires an active lease, chronological failure time and safe error code.');
        }
        $this->lastErrorCode = $errorCode;
        $this->leasedUntil = null;
        if ($this->attempts >= $maximumAttempts) {
            $this->state = 'dead_letter';
            return;
        }
        if ($retryAt <= $failedAt || $retryAt <= $this->availableAt) {
            throw new InvalidArgumentException('Outbox retry time must follow both failure and previous availability.');
        }
        $this->availableAt = $retryAt;
        $this->state = 'pending';
    }

    public function event(): FinancialEventEnvelope { return $this->event; }
    public function state(): string { return $this->state; }
    public function attempts(): int { return $this->attempts; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
    public function availableAt(): DateTimeImmutable { return $this->availableAt; }
    public function lastAttemptAt(): ?DateTimeImmutable { return $this->lastAttemptAt; }
}
