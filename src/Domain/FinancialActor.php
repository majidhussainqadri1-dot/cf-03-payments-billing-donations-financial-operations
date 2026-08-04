<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialActor
{
    /** @var array<string,true> */
    private array $capabilities = [];

    /** @param list<FinancialCapability> $capabilities */
    public function __construct(
        private readonly string $actorReference,
        array $capabilities,
        private readonly bool $suspended = false,
        private readonly ?DateTimeImmutable $recentAuthenticationAt = null
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $actorReference) !== 1) {
            throw new InvalidArgumentException('Financial actor reference is invalid.');
        }

        foreach ($capabilities as $capability) {
            if (! $capability instanceof FinancialCapability) {
                throw new InvalidArgumentException('Financial actor capabilities must use FinancialCapability values.');
            }
            $this->capabilities[$capability->value] = true;
        }
    }

    public function actorReference(): string
    {
        return $this->actorReference;
    }

    public function has(FinancialCapability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function assertCan(
        FinancialCapability $capability,
        ?DateTimeImmutable $now = null,
        int $recentAuthenticationWindowSeconds = 0
    ): void {
        if ($this->suspended) {
            throw new InvariantViolation('Suspended financial actor cannot perform financial actions.');
        }

        if (! $this->has($capability)) {
            throw new InvariantViolation('Financial capability is not granted.');
        }

        if ($recentAuthenticationWindowSeconds > 0) {
            if ($recentAuthenticationWindowSeconds < 60 || $recentAuthenticationWindowSeconds > 86400) {
                throw new InvalidArgumentException('Recent-authentication window is invalid.');
            }
            if ($now === null || $this->recentAuthenticationAt === null) {
                throw new InvariantViolation('Recent authentication is required.');
            }
            $age = $now->getTimestamp() - $this->recentAuthenticationAt->getTimestamp();
            if ($age < 0 || $age > $recentAuthenticationWindowSeconds) {
                throw new InvariantViolation('Recent authentication requirement is not satisfied.');
            }
        }
    }
}
