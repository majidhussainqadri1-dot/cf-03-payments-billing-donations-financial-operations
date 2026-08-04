<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialAdjustment
{
    public function __construct(
        private readonly string $adjustmentId,
        private readonly string $sourceTransactionId,
        private readonly Money $amount,
        private readonly string $reasonCode,
        private readonly string $evidenceSha256,
        private readonly string $requesterReference,
        private readonly DateTimeImmutable $requestedAt,
        private string $state = 'requested',
        private ?string $approverReference = null,
        private ?string $executorReference = null,
        private int $recordVersion = 1
    ) {
        foreach ([$adjustmentId, $sourceTransactionId, $reasonCode, $requesterReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Financial adjustment reference is invalid.');
            }
        }
        if ($amount->minorUnits() <= 0 || preg_match('/^[a-f0-9]{64}$/', $evidenceSha256) !== 1) {
            throw new InvalidArgumentException('Financial adjustment amount or evidence is invalid.');
        }
        if (! in_array($state, ['requested', 'approved', 'executed', 'rejected'], true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Financial adjustment state or version is invalid.');
        }
    }

    public function approve(string $approverReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'requested' || $approverReference === $this->requesterReference || trim($approverReference) === '') {
            throw new InvariantViolation('Financial adjustment approval violates separation of duties.');
        }
        $this->approverReference = $approverReference;
        $this->state = 'approved';
        $this->recordVersion++;
    }

    public function reject(string $approverReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'requested' || $approverReference === $this->requesterReference || trim($approverReference) === '') {
            throw new InvariantViolation('Financial adjustment rejection violates separation of duties.');
        }
        $this->approverReference = $approverReference;
        $this->state = 'rejected';
        $this->recordVersion++;
    }

    public function execute(string $executorReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'approved'
            || trim($executorReference) === ''
            || $executorReference === $this->requesterReference
            || $executorReference === $this->approverReference
        ) {
            throw new InvariantViolation('Financial adjustment execution violates separation of duties.');
        }
        $this->executorReference = $executorReference;
        $this->state = 'executed';
        $this->recordVersion++;
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }
    public function amount(): Money { return $this->amount; }
    public function sourceTransactionId(): string { return $this->sourceTransactionId; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale financial adjustment version.');
        }
    }
}
