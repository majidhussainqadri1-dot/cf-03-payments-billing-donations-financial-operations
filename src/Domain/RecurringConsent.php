<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RecurringConsent
{
    public function __construct(
        private readonly string $consentId,
        private readonly string $actorReference,
        private readonly string $productId,
        private readonly Money $amount,
        private readonly string $interval,
        private readonly DateTimeImmutable $nextChargeAt,
        private readonly string $termsSha256,
        private readonly string $cancellationPath,
        private readonly DateTimeImmutable $capturedAt,
        private readonly bool $explicitlyConfirmed,
        private ?DateTimeImmutable $revokedAt = null,
        private int $recordVersion = 1
    ) {
        foreach ([$consentId, $actorReference, $productId] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Recurring consent reference is invalid.');
            }
        }
        if (! in_array($interval, ['week', 'month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException('Recurring consent interval is invalid.');
        }
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Recurring consent amount must be positive.');
        }
        if ($nextChargeAt <= $capturedAt) {
            throw new InvalidArgumentException('Recurring consent next charge must be in the future.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $termsSha256) !== 1) {
            throw new InvalidArgumentException('Recurring consent terms hash is invalid.');
        }
        if (! str_starts_with($cancellationPath, '/')
            || str_starts_with($cancellationPath, '//')
            || str_contains($cancellationPath, "\r")
            || str_contains($cancellationPath, "\n")
            || str_contains($cancellationPath, '\\')
        ) {
            throw new InvalidArgumentException('Recurring consent cancellation path must be same-origin.');
        }
        if (! $explicitlyConfirmed) {
            throw new InvariantViolation('Recurring billing requires explicit unpreselected consent.');
        }
        if ($recordVersion < 1 || ($revokedAt !== null && $revokedAt < $capturedAt)) {
            throw new InvalidArgumentException('Recurring consent revocation state or version is invalid.');
        }
    }

    public function revoke(DateTimeImmutable $at, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($at < $this->capturedAt) {
            throw new InvariantViolation('Recurring consent cannot be revoked before it was captured.');
        }
        if ($this->revokedAt !== null) {
            if ($this->revokedAt == $at) {
                return;
            }
            throw new InvariantViolation('Recurring consent revocation timestamp is immutable.');
        }
        $this->revokedAt = $at;
        $this->recordVersion++;
    }

    public function assertActiveAt(DateTimeImmutable $at): void
    {
        if ($at < $this->capturedAt) {
            throw new InvariantViolation('Recurring consent was not yet captured at the requested time.');
        }
        if ($this->revokedAt !== null && $at >= $this->revokedAt) {
            throw new InvariantViolation('Recurring consent has been revoked.');
        }
    }

    public function assertRenewalParity(
        Money $amount,
        string $interval,
        string $termsSha256,
        ?DateTimeImmutable $at = null
    ): void {
        if ($at !== null) {
            $this->assertActiveAt($at);
        }
        if (! $this->amount->equals($amount)
            || $this->interval !== $interval
            || ! hash_equals($this->termsSha256, $termsSha256)
        ) {
            throw new InvariantViolation('Material recurring terms changed and require new consent.');
        }
    }

    /** @return array<string,mixed> */
    public function disclosure(): array
    {
        return [
            'consent_id' => $this->consentId,
            'product_id' => $this->productId,
            'amount_minor' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'interval' => $this->interval,
            'next_charge_at' => $this->nextChargeAt->format(DATE_ATOM),
            'cancellation_path' => $this->cancellationPath,
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
            'revoked_at' => $this->revokedAt?->format(DATE_ATOM),
            'state' => $this->revokedAt === null ? 'active' : 'revoked',
            'record_version' => $this->recordVersion,
        ];
    }

    public function recordVersion(): int { return $this->recordVersion; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale recurring-consent record version.');
        }
    }
}
