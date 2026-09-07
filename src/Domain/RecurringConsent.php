<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Superseded compatibility type.
 *
 * CF-03 v1.0 permits voluntary one-time donations only. This class remains so a
 * legacy caller gets an explicit fail-closed error rather than silently creating
 * a continuing mandate.
 */
final class RecurringConsent
{
    public function __construct(
        string $consentId,
        string $actorReference,
        string $productId,
        Money $amount,
        string $interval,
        DateTimeImmutable $nextChargeAt,
        string $termsSha256,
        string $cancellationPath,
        DateTimeImmutable $capturedAt,
        bool $explicitlyConfirmed,
        ?DateTimeImmutable $revokedAt = null,
        int $recordVersion = 1
    ) {
        throw new InvariantViolation(
            'Recurring donation consent is retired; CF-03 accepts a fresh explicit one-time donation action only.'
        );
    }

    public function revoke(DateTimeImmutable $at, int $expectedVersion): void
    { throw new InvariantViolation('Recurring donation consent is retired.'); }

    public function assertActiveAt(DateTimeImmutable $at): void
    { throw new InvariantViolation('Recurring donation consent is retired.'); }

    public function assertRenewalParity(Money $amount, string $interval, string $termsSha256, ?DateTimeImmutable $at = null): void
    { throw new InvariantViolation('Recurring donation renewal is prohibited.'); }

    /** @return array<string,mixed> */
    public function disclosure(): array
    {
        return ['state' => 'retired', 'recurring_available' => false, 'one_time_only' => true];
    }

    public function recordVersion(): int { return 0; }
    public function revokedAt(): ?DateTimeImmutable { return null; }
}
