<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class ProductLifecycle
{
    private const STATES = ['draft', 'staged', 'approved', 'active', 'dormant', 'retired'];

    public function __construct(
        private readonly FinancialProduct $product,
        private string $state = 'draft',
        private int $recordVersion = 1,
        private ?string $stagedBy = null,
        private ?string $approvedBy = null,
        private ?DateTimeImmutable $effectiveAt = null
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Unknown financial product lifecycle state.');
        }
        if ($recordVersion < 1) {
            throw new InvalidArgumentException('Product record version must be positive.');
        }
    }

    public function stage(string $actorReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['draft', 'dormant'], true)) {
            throw new InvariantViolation('Only draft or dormant products may be staged.');
        }
        $this->stagedBy = $this->actor($actorReference);
        $this->state = 'staged';
        $this->recordVersion++;
    }

    public function approve(string $actorReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'staged') {
            throw new InvariantViolation('Only staged products may be approved.');
        }
        $actorReference = $this->actor($actorReference);
        if ($actorReference === $this->stagedBy) {
            throw new InvariantViolation('Product stager cannot approve the same product.');
        }
        $this->approvedBy = $actorReference;
        $this->state = 'approved';
        $this->recordVersion++;
    }

    public function activate(
        string $actorReference,
        DateTimeImmutable $effectiveAt,
        int $expectedVersion,
        bool $paidActivationApproved = false
    ): void {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'approved') {
            throw new InvariantViolation('Only approved products may be activated.');
        }
        $actorReference = $this->actor($actorReference);
        if ($actorReference === $this->stagedBy || $actorReference === $this->approvedBy) {
            throw new InvariantViolation('Product activation requires a distinct authorized actor.');
        }
        if ($this->product->kind() !== ProductKind::DONATION && ! $paidActivationApproved) {
            throw new InvariantViolation('Paid product activation is suspended by the governing free-platform policy.');
        }
        $this->effectiveAt = $effectiveAt;
        $this->state = 'active';
        $this->recordVersion++;
    }

    public function makeDormant(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['approved', 'active'], true)) {
            throw new InvariantViolation('Only approved or active products may become dormant.');
        }
        $this->state = 'dormant';
        $this->recordVersion++;
    }

    public function retire(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state === 'retired') {
            return;
        }
        $this->state = 'retired';
        $this->recordVersion++;
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }
    public function effectiveAt(): ?DateTimeImmutable { return $this->effectiveAt; }
    public function product(): FinancialProduct { return $this->product; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale financial product record version.');
        }
    }

    private function actor(string $reference): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $reference) !== 1) {
            throw new InvalidArgumentException('Product lifecycle actor reference is invalid.');
        }
        return $reference;
    }
}
