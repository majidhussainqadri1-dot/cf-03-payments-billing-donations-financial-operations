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

    /**
     * Fixed-price activation is retained only as a historical API boundary.
     * The current constitution has no collectible fixed-price product: all core
     * services are free and donations are donor-entered, voluntary and one-time.
     * @param list<PriceLifecycle> $otherPrices
     */
    public function activate(
        string $actorReference,
        DateTimeImmutable $at,
        int $expectedVersion,
        array $otherPrices = []
    ): void {
        $this->assertVersion($expectedVersion);
        $this->actor($actorReference);
        throw new InvariantViolation(
            'Fixed-price activation is retired while CF-03 operates the single-free-tier, voluntary one-time donation model.'
        );
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
}
