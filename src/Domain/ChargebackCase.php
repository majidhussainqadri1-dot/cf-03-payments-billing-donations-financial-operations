<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class ChargebackCase
{
    private const STATES = ['notified', 'evidence_due', 'submitted', 'accepted', 'won', 'lost', 'ledger_adjusted', 'closed'];

    public function __construct(
        private readonly string $caseId,
        private readonly string $providerCode,
        private readonly string $providerCaseReference,
        private readonly string $paymentIntentId,
        private readonly Money $disputedAmount,
        private readonly string $reasonCode,
        private readonly DateTimeImmutable $responseDeadline,
        private string $state = 'notified',
        private int $recordVersion = 1,
        private ?string $evidenceSha256 = null,
        private ?Money $providerFee = null
    ) {
        foreach ([$caseId, $providerCode, $providerCaseReference, $paymentIntentId, $reasonCode] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Chargeback reference is invalid.');
            }
        }
        if ($disputedAmount->minorUnits() <= 0 || ! in_array($state, self::STATES, true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Chargeback amount, state or version is invalid.');
        }
    }

    public function requireEvidence(DateTimeImmutable $at, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'notified') {
            throw new InvariantViolation('Chargeback evidence cannot be requested from the current state.');
        }
        if ($at >= $this->responseDeadline) {
            throw new InvariantViolation('Chargeback response deadline has passed.');
        }
        $this->state = 'evidence_due';
        $this->recordVersion++;
    }

    public function submitEvidence(string $evidenceSha256, DateTimeImmutable $at, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['notified', 'evidence_due'], true)) {
            throw new InvariantViolation('Chargeback evidence cannot be submitted from the current state.');
        }
        if ($at > $this->responseDeadline || preg_match('/^[a-f0-9]{64}$/', $evidenceSha256) !== 1) {
            throw new InvariantViolation('Chargeback evidence is late or invalid.');
        }
        $this->evidenceSha256 = $evidenceSha256;
        $this->state = 'submitted';
        $this->recordVersion++;
    }

    public function recordProviderAcceptance(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'submitted') {
            throw new InvariantViolation('Chargeback evidence is not submitted.');
        }
        $this->state = 'accepted';
        $this->recordVersion++;
    }

    public function recordOutcome(bool $won, Money $providerFee, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['submitted', 'accepted'], true)) {
            throw new InvariantViolation('Chargeback is not awaiting an outcome.');
        }
        if ($providerFee->currency() !== $this->disputedAmount->currency()) {
            throw new InvariantViolation('Chargeback provider fee currency mismatch.');
        }
        $this->providerFee = $providerFee;
        $this->state = $won ? 'won' : 'lost';
        $this->recordVersion++;
    }

    public function markLedgerAdjusted(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['won', 'lost'], true)) {
            throw new InvariantViolation('Chargeback outcome is not final.');
        }
        $this->state = 'ledger_adjusted';
        $this->recordVersion++;
    }

    public function close(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'ledger_adjusted') {
            throw new InvariantViolation('Chargeback cannot close before ledger adjustment.');
        }
        $this->state = 'closed';
        $this->recordVersion++;
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }
    public function providerFee(): ?Money { return $this->providerFee; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale chargeback record version.');
        }
    }
}
