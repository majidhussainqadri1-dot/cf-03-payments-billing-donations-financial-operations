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
        private readonly bool $explicitlyConfirmed
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
        if (! str_starts_with($cancellationPath, '/') || str_starts_with($cancellationPath, '//')) {
            throw new InvalidArgumentException('Recurring consent cancellation path must be same-origin.');
        }
        if (! $explicitlyConfirmed) {
            throw new InvariantViolation('Recurring billing requires explicit unpreselected consent.');
        }
    }

    public function assertRenewalParity(Money $amount, string $interval, string $termsSha256): void
    {
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
        ];
    }
}
