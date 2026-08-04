<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancePeriod
{
    public function __construct(
        private readonly string $periodId,
        private bool $closed = false,
        private ?string $closedBy = null,
        private string $state = 'open',
        private int $recordVersion = 1,
        private ?string $reviewedBy = null,
        private ?DateTimeImmutable $closedAt = null,
        private ?string $reopenReasonReference = null
    ) {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodId) !== 1
            || ! in_array($state, ['open', 'reconciliation', 'exception_review', 'approved_close', 'locked'], true)
            || $recordVersion < 1
        ) {
            throw new InvalidArgumentException('Finance period identity, state or version is invalid.');
        }
        if ($closed) {
            $this->state = 'locked';
        }
    }

    public function beginReconciliation(int $expectedVersion): void
    {
        $this->transition('open', 'reconciliation', $expectedVersion);
    }

    public function beginExceptionReview(int $expectedVersion): void
    {
        $this->transition('reconciliation', 'exception_review', $expectedVersion);
    }

    public function approveClose(
        ReconciliationResult $result,
        string $reviewer,
        string $approver,
        int $expectedVersion
    ): void {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['reconciliation', 'exception_review'], true)) {
            throw new InvariantViolation('Finance period is not ready for close approval.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reviewer) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $approver) !== 1
            || $reviewer === $approver
        ) {
            throw new InvariantViolation('Finance close requires valid separate reviewer and approver identities.');
        }

        // Material exceptions are never closeable through an accepted-risk string.
        // They must first be resolved in reconciliation and reflected in a new result.
        $result->assertClosable();

        $this->reviewedBy = $reviewer;
        $this->closedBy = $approver;
        $this->state = 'approved_close';
        $this->recordVersion++;
    }

    public function lock(DateTimeImmutable $at, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'approved_close') {
            throw new InvariantViolation('Finance period requires approved close before lock.');
        }
        $this->closed = true;
        $this->closedAt = $at;
        $this->state = 'locked';
        $this->recordVersion++;
    }

    public function reopen(
        string $requester,
        string $approver,
        string $reasonReference,
        int $expectedVersion
    ): void {
        $this->assertVersion($expectedVersion);
        if (! $this->closed
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $requester) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $approver) !== 1
            || $requester === $approver
        ) {
            throw new InvariantViolation('Finance period reopen requires locked state and valid dual control.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reasonReference) !== 1) {
            throw new InvalidArgumentException('Finance period reopen reason reference is invalid.');
        }
        $this->closed = false;
        $this->closedBy = null;
        $this->closedAt = null;
        $this->state = 'exception_review';
        $this->reopenReasonReference = $reasonReference;
        $this->recordVersion++;
    }

    public function assertWritable(): void
    {
        if ($this->closed || in_array($this->state, ['approved_close', 'locked'], true)) {
            throw new InvariantViolation('Closed periods are immutable; use next-period adjustment.');
        }
    }

    public function closed(): bool { return $this->closed; }
    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }

    private function transition(string $from, string $to, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== $from) {
            throw new InvariantViolation('Finance period transition is invalid.');
        }
        $this->state = $to;
        $this->recordVersion++;
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale finance period record version.');
        }
    }
}
