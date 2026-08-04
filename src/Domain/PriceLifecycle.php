<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PriceLifecycle
{
    private const STATES = ['staged', 'approved', 'active', 'retired'];

    public function __construct(
        private readonly PriceVersion $price,
        private string $state = 'staged',
        private int $recordVersion = 1,
        private ?string $stagedBy = null,
        private ?string $approvedBy = null
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Unknown price lifecycle state.');
        }
        if ($recordVersion < 1) {
            throw new InvalidArgumentException('Price record version must be positive.');
        }
    }

    public function recordStager(string $actorReference): void
    {
        if ($this->stagedBy !== null) {
            throw new InvariantViolation('Price stager is already recorded.');
        }
        $this->stagedBy = $this->actor($actorReference);
    }

    public function approve(string $actorReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'staged') {
            throw new InvariantViolation('Only staged prices may be approved.');
        }
        $actorReference = $this->actor($actorReference);
        if ($actorReference === $this->stagedBy) {
            throw new InvariantViolation('Price stager cannot approve the same price.');
        }
        $this->approvedBy = $actorReference;
        $this->state = 'approved';
        $this->recordVersion++;
    }

    /** @param list<PriceLifecycle> $otherPrices */
    public function activate(
        string $actorReference,
        DateTimeImmutable $at,
        int $expectedVersion,
        array $otherPrices = []
    ): void {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'approved') {
            throw new InvariantViolation('Only approved prices may be activated.');
        }
        $actorReference = $this->actor($actorReference);
        if ($actorReference === $this->stagedBy || $actorReference === $this->approvedBy) {
            throw new InvariantViolation('Price activation requires a distinct authorized actor.');
        }
        if (! $this->price->isEffectiveAt($at)) {
            throw new InvariantViolation('Price is not effective at the requested activation time.');
        }

        foreach ($otherPrices as $other) {
            if (! $other instanceof self || $other === $this || $other->state !== 'active') {
                continue;
            }
            $candidate = $other->price;
            if ($candidate->productId() === $this->price->productId()
                && $candidate->region() === $this->price->region()
                && $candidate->amount()->currency() === $this->price->amount()->currency()
                && self::overlap($candidate, $this->price)
            ) {
                throw new InvariantViolation('Active approved price windows cannot overlap.');
            }
        }

        $this->state = 'active';
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
    public function price(): PriceVersion { return $this->price; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale price record version.');
        }
    }

    private function actor(string $reference): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $reference) !== 1) {
            throw new InvalidArgumentException('Price lifecycle actor reference is invalid.');
        }
        return $reference;
    }

    private static function overlap(PriceVersion $left, PriceVersion $right): bool
    {
        $leftEnd = $left->effectiveUntil()?->getTimestamp() ?? PHP_INT_MAX;
        $rightEnd = $right->effectiveUntil()?->getTimestamp() ?? PHP_INT_MAX;
        return $left->effectiveFrom()->getTimestamp() < $rightEnd
            && $right->effectiveFrom()->getTimestamp() < $leftEnd;
    }
}
