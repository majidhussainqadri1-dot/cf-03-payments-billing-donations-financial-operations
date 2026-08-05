<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Support\InvariantViolation;

final class DonationIntentDraft
{
    public function __construct(
        private readonly string $intentId,
        private readonly string $donorReference,
        private readonly Money $amount,
        private readonly bool $monthly,
        private readonly bool $explicitMonthlyConsent,
        private readonly DonationServiceState $serviceState,
        private readonly string $idempotencyKey,
        private readonly DateTimeImmutable $createdAt
    ) {
        foreach ([$intentId, $donorReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Donation intent reference is invalid.');
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Donation idempotency key is invalid.');
        }

        (new PlatformFinancialPolicy())->assertSuggestedOrCustomDonation($amount);
        if ($monthly && ! $explicitMonthlyConsent) {
            throw new InvariantViolation('Monthly donation requires explicit, unpreselected donor consent.');
        }
        if (! $monthly && $explicitMonthlyConsent) {
            throw new InvalidArgumentException('Monthly consent cannot be recorded for a one-time donation.');
        }
    }

    public function assertProviderCheckoutAvailable(): void
    {
        if ($this->serviceState === DonationServiceState::PREPARING) {
            throw new InvariantViolation('Donation service is being prepared; no live financial collection is available.');
        }
    }

    public function intentId(): string { return $this->intentId; }
    public function donorReference(): string { return $this->donorReference; }
    public function amount(): Money { return $this->amount; }
    public function monthly(): bool { return $this->monthly; }
    public function explicitMonthlyConsent(): bool { return $this->explicitMonthlyConsent; }
    public function serviceState(): DonationServiceState { return $this->serviceState; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /** @return array<string,mixed> */
    public function toSafePayload(): array
    {
        return [
            'intent_id' => $this->intentId,
            'amount_minor_units' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'monthly' => $this->monthly,
            'service_state' => $this->serviceState->value,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
