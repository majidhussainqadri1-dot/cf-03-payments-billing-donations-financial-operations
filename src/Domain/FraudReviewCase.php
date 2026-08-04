<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FraudReviewCase
{
    private const ALLOWED_SIGNALS = [
        'velocity',
        'provider_risk',
        'billing_country_mismatch',
        'amount_anomaly',
        'duplicate_instrument_reference',
        'repeated_failed_authentication',
        'refund_abuse_pattern',
    ];

    /** @var list<array{code:string,weight:int,evidence_ref:string}> */
    private array $signals;

    /** @param list<array{code:string,weight:int,evidence_ref:string}> $signals */
    public function __construct(
        private readonly string $reviewId,
        private readonly string $subjectReference,
        array $signals,
        private readonly DateTimeImmutable $openedAt,
        private readonly DateTimeImmutable $holdUntil,
        private string $state = 'open',
        private int $recordVersion = 1,
        private ?string $reviewerReference = null,
        private ?string $decisionReason = null
    ) {
        foreach ([$reviewId, $subjectReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Fraud review reference is invalid.');
            }
        }
        if (! in_array($state, ['open', 'approved', 'declined', 'appealed', 'closed'], true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Fraud review state or version is invalid.');
        }
        if ($holdUntil <= $openedAt || $holdUntil > $openedAt->modify('+7 days')) {
            throw new InvalidArgumentException('Fraud hold must end after opening and within seven days.');
        }

        $normalized = [];
        $seenCodes = [];
        $seenEvidence = [];
        foreach ($signals as $signal) {
            if (! is_array($signal)
                || array_keys($signal) !== ['code', 'weight', 'evidence_ref']
                || ! is_string($signal['code'])
                || ! is_int($signal['weight'])
                || ! is_string($signal['evidence_ref'])
                || ! in_array($signal['code'], self::ALLOWED_SIGNALS, true)
                || $signal['weight'] < 1
                || $signal['weight'] > 100
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $signal['evidence_ref']) !== 1
            ) {
                throw new InvalidArgumentException('Fraud review signal is invalid or prohibited.');
            }
            if (isset($seenCodes[$signal['code']]) || isset($seenEvidence[$signal['evidence_ref']])) {
                throw new InvariantViolation('Fraud risk cannot be inflated by duplicate signal codes or evidence references.');
            }
            $seenCodes[$signal['code']] = true;
            $seenEvidence[$signal['evidence_ref']] = true;
            $normalized[] = $signal;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Fraud review requires at least one bounded signal.');
        }
        $this->signals = $normalized;
    }

    public function riskScore(): int
    {
        return min(100, array_sum(array_column($this->signals, 'weight')));
    }

    public function decide(
        bool $approved,
        string $reviewerReference,
        string $reason,
        DateTimeImmutable $at,
        int $expectedVersion
    ): void {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['open', 'appealed'], true)) {
            throw new InvariantViolation('Fraud review is not decisionable.');
        }
        if ($at < $this->openedAt || $at > $this->holdUntil) {
            throw new InvariantViolation('Fraud decision is outside the bounded review window; manual escalation is required.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reviewerReference) !== 1 || trim($reason) === '') {
            throw new InvalidArgumentException('Fraud review decision requires a valid reviewer and reason.');
        }
        if ($this->state === 'appealed' && $reviewerReference === $this->reviewerReference) {
            throw new InvariantViolation('Fraud appeal requires a fresh reviewer.');
        }
        $this->reviewerReference = $reviewerReference;
        $this->decisionReason = $reason;
        $this->state = $approved ? 'approved' : 'declined';
        $this->recordVersion++;
    }

    public function appeal(string $reason, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'declined' || trim($reason) === '') {
            throw new InvariantViolation('Only a reasoned declined review may be appealed.');
        }
        $this->state = 'appealed';
        $this->decisionReason = $reason;
        $this->recordVersion++;
    }

    public function close(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['approved', 'declined'], true)) {
            throw new InvariantViolation('Fraud review cannot close before a final decision.');
        }
        $this->state = 'closed';
        $this->recordVersion++;
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }
    public function openedAt(): DateTimeImmutable { return $this->openedAt; }
    public function holdUntil(): DateTimeImmutable { return $this->holdUntil; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale fraud review record version.');
        }
    }
}
