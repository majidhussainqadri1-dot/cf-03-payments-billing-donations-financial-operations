<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RefundRequest
{
    private const STATES = [
        'requested',
        'eligibility_review',
        'approved',
        'denied',
        'provider_pending',
        'processing',
        'succeeded',
        'failed',
        'uncertain',
        'reconciled',
        'closed',
    ];

    public function __construct(
        private readonly string $refundId,
        private readonly string $paymentIntentId,
        private readonly string $requester,
        private readonly Money $amount,
        private readonly string $reason,
        private string $status = 'requested',
        private ?string $reviewer = null,
        private ?string $executor = null,
        private readonly ?Money $refundableBalance = null,
        private readonly string $policyVersion = 'refund.policy.v1',
        private readonly ?DateTimeImmutable $requestedAt = null,
        private int $recordVersion = 1,
        private ?string $providerReference = null,
        private ?string $decisionReason = null
    ) {
        foreach ([$refundId, $paymentIntentId, $requester, $reason, $policyVersion] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Refund fields are required.');
            }
        }
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }
        if ($refundableBalance !== null) {
            if ($refundableBalance->currency() !== $amount->currency()
                || $amount->minorUnits() > $refundableBalance->minorUnits()
            ) {
                throw new InvariantViolation('Refund amount exceeds the refundable balance.');
            }
        }
        if (! in_array($status, self::STATES, true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Refund state or record version is invalid.');
        }
    }

    public function beginEligibilityReview(string $reviewer, int $expectedVersion = 1): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->status !== 'requested') {
            throw new InvariantViolation('Refund is not eligible to begin review.');
        }
        $this->assertReviewer($reviewer);
        $this->reviewer = $reviewer;
        $this->status = 'eligibility_review';
        $this->recordVersion++;
    }

    public function approve(string $reviewer, ?string $decisionReason = null, ?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['requested', 'eligibility_review'], true)) {
            throw new InvariantViolation('Refund is not reviewable.');
        }
        $this->assertReviewer($reviewer);
        if ($this->reviewer !== null && $this->reviewer !== $reviewer) {
            throw new InvariantViolation('Refund reviewer cannot be silently replaced.');
        }
        $this->reviewer = $reviewer;
        $this->decisionReason = $decisionReason;
        $this->status = 'approved';
        $this->recordVersion++;
    }

    public function deny(string $reviewer, string $decisionReason, ?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['requested', 'eligibility_review'], true)) {
            throw new InvariantViolation('Refund is not reviewable.');
        }
        $this->assertReviewer($reviewer);
        if (trim($decisionReason) === '') {
            throw new InvalidArgumentException('Refund denial requires a reason.');
        }
        $this->reviewer = $reviewer;
        $this->decisionReason = $decisionReason;
        $this->status = 'denied';
        $this->recordVersion++;
    }

    public function markExecuting(string $executor, ?string $providerReference = null, ?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if ($this->status !== 'approved') {
            throw new InvariantViolation('Refund is not approved.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $executor) !== 1) {
            throw new InvalidArgumentException('Refund executor reference is invalid.');
        }
        if ($executor === $this->reviewer || $executor === $this->requester) {
            throw new InvariantViolation('Requester/reviewer cannot execute the same high-risk refund.');
        }
        if ($providerReference !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $providerReference) !== 1) {
            throw new InvalidArgumentException('Refund provider reference is invalid.');
        }
        $this->executor = $executor;
        $this->providerReference = $providerReference;
        $this->status = 'processing';
        $this->recordVersion++;
    }

    public function markProviderPending(string $executor, string $providerReference, ?int $expectedVersion = null): void
    {
        $this->markExecuting($executor, $providerReference, $expectedVersion);
        $this->status = 'provider_pending';
    }

    public function succeed(?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['processing', 'provider_pending', 'uncertain'], true)) {
            throw new InvariantViolation('Refund is not awaiting a provider result.');
        }
        $this->status = 'succeeded';
        $this->recordVersion++;
    }

    public function fail(?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['processing', 'provider_pending', 'uncertain'], true)) {
            throw new InvariantViolation('Refund is not awaiting a provider result.');
        }
        $this->status = 'failed';
        $this->recordVersion++;
    }

    public function markUncertain(?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['processing', 'provider_pending'], true)) {
            throw new InvariantViolation('Only an in-flight refund can become uncertain.');
        }
        $this->status = 'uncertain';
        $this->recordVersion++;
    }

    public function reconcile(bool $providerSucceeded, ?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['succeeded', 'failed', 'uncertain'], true)) {
            throw new InvariantViolation('Refund is not reconcilable.');
        }
        $this->status = $providerSucceeded ? 'reconciled' : 'failed';
        $this->recordVersion++;
    }

    public function close(?int $expectedVersion = null): void
    {
        $this->assertVersion($expectedVersion ?? $this->recordVersion);
        if (! in_array($this->status, ['denied', 'failed', 'reconciled'], true)) {
            throw new InvariantViolation('Refund cannot close before a final reconciled outcome.');
        }
        $this->status = 'closed';
        $this->recordVersion++;
    }

    public function status(): string { return $this->status; }
    public function amount(): Money { return $this->amount; }
    public function recordVersion(): int { return $this->recordVersion; }
    public function reviewer(): ?string { return $this->reviewer; }
    public function executor(): ?string { return $this->executor; }
    public function providerReference(): ?string { return $this->providerReference; }

    private function assertReviewer(string $reviewer): void
    {
        if (trim($reviewer) === '' || $reviewer === $this->requester) {
            throw new InvariantViolation('Requester cannot review own refund.');
        }
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale refund record version.');
        }
    }
}
