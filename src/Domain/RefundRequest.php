<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RefundRequest
{
    public function __construct(
        private readonly string $refundId,
        private readonly string $paymentIntentId,
        private readonly string $requester,
        private readonly Money $amount,
        private readonly string $reason,
        private string $status = 'requested',
        private ?string $reviewer = null,
        private ?string $executor = null
    ) {
        foreach ([$refundId, $paymentIntentId, $requester, $reason] as $value) {
            if (trim($value) === '') { throw new InvalidArgumentException('Refund fields are required.'); }
        }
        if ($amount->minorUnits() <= 0) { throw new InvalidArgumentException('Refund amount must be positive.'); }
    }

    public function approve(string $reviewer): void
    {
        if ($reviewer === $this->requester) { throw new InvariantViolation('Requester cannot approve own refund.'); }
        if ($this->status !== 'requested') { throw new InvariantViolation('Refund is not reviewable.'); }
        $this->reviewer = $reviewer;
        $this->status = 'approved';
    }

    public function markExecuting(string $executor): void
    {
        if ($this->status !== 'approved') { throw new InvariantViolation('Refund is not approved.'); }
        if ($executor === $this->reviewer) { throw new InvariantViolation('Reviewer cannot execute the same high-risk refund.'); }
        $this->executor = $executor;
        $this->status = 'processing';
    }

    public function succeed(): void { if ($this->status !== 'processing') { throw new InvariantViolation('Refund is not processing.'); } $this->status = 'succeeded'; }
    public function fail(): void { if ($this->status !== 'processing') { throw new InvariantViolation('Refund is not processing.'); } $this->status = 'failed'; }
    public function status(): string { return $this->status; }
    public function amount(): Money { return $this->amount; }
}
