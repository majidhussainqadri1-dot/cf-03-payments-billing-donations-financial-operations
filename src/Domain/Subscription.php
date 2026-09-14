<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Compatibility tombstone for the superseded paid-subscription domain model.
 *
 * The current CF-03 constitution permits one free core tier and voluntary
 * one-time donations only. Subscription, renewal, grace, pause and resumption
 * semantics are therefore retained only as an explicit fail-closed boundary for
 * stale integrations and historical serialized references.
 */
final class Subscription
{
    public function __construct(
        string $subscriptionId,
        string $ownerReference,
        string $productId,
        string $priceVersionId,
        string $state,
        int $version = 1,
        string $policyVersion = 'subscription.policy.v1',
        ?string $providerReference = null,
        ?DateTimeImmutable $currentPeriodEnd = null,
        ?DateTimeImmutable $graceUntil = null,
        ?DateTimeImmutable $pausedUntil = null,
        ?DateTimeImmutable $cancellationEffectiveAt = null
    ) {
        throw new InvariantViolation(
            'Paid subscriptions are retired; CF-03 permits voluntary one-time donations only.'
        );
    }

    public function transition(string $next, int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription state transitions are retired.'); }

    public function activate(
        string $providerReference,
        DateTimeImmutable $activatedAt,
        DateTimeImmutable $periodEnd,
        int $expectedVersion
    ): void { throw new InvariantViolation('Paid subscription activation is retired.'); }

    public function markPastDue(int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription past-due handling is retired.'); }

    public function startGrace(DateTimeImmutable $graceUntil, int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription grace periods are retired.'); }

    public function pause(DateTimeImmutable $pausedUntil, DateTimeImmutable $at, int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription pausing is retired.'); }

    public function cancelAtPeriodEnd(int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription period-end cancellation is retired.'); }

    public function resume(DateTimeImmutable $newPeriodEnd, int $expectedVersion): void
    { throw new InvariantViolation('Paid subscription resumption is retired.'); }

    /** @return array<string,mixed> */
    public function snapshot(): array
    { throw new InvariantViolation('Paid subscription snapshots are historical-only and unavailable as active runtime truth.'); }

    public function state(): string
    { throw new InvariantViolation('Paid subscription state is retired.'); }

    public function version(): int
    { throw new InvariantViolation('Paid subscription versioning is retired.'); }
}
