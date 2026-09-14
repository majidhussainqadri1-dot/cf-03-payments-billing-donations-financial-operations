<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Compatibility tombstone for the superseded paid-subscription dunning model.
 * Automatic retry schedules, renewal collection and grace/dunning behavior are
 * prohibited by the current single-free-tier, one-time-donation constitution.
 */
final class DunningPolicy
{
    /** @param list<int> $retryDelaysSeconds */
    public function __construct(
        array $retryDelaysSeconds,
        int $quietHourStart = 21,
        int $quietHourEnd = 8,
        int $maximumAttempts = 4
    ) {
        throw new InvariantViolation(
            'Dunning is retired; CF-03 does not perform subscription renewal or automatic repeat collection.'
        );
    }

    public function nextRetryAt(
        DateTimeImmutable $failedAt,
        int $attemptNumber,
        DateTimeZone $userTimeZone,
        bool $providerOutage = false,
        bool $consentActive = true,
        bool $subscriptionCancelled = false
    ): DateTimeImmutable {
        throw new InvariantViolation('Dunning retry scheduling is retired.');
    }

    public function maximumAttempts(): int
    {
        throw new InvariantViolation('Dunning attempt limits are retired.');
    }
}
