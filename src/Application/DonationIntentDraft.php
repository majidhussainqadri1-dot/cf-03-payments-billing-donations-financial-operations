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
    private const WITHHELD_PROVIDER_SUBJECT = 'donor:withheld';

    public function __construct(
        private readonly string $intentId,
        private readonly string $donorReference,
        private readonly Money $amount,
        bool $monthly,
        bool $explicitMonthlyConsent,
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

        $policy = new PlatformFinancialPolicy();
        $policy->assertSuggestedOrCustomDonation($amount);
        if ($monthly || $explicitMonthlyConsent) {
            throw new InvariantViolation(
                'Recurring or monthly donation controls are unavailable. Start a new independent one-time donation when desired.'
            );
        }
    }

    public function assertProviderCheckoutAvailable(): void
    {
        if ($this->serviceState === DonationServiceState::PREPARING) {
            throw new InvariantViolation('Donation service is being prepared; no live financial collection is available.');
        }
    }

    public function providerSafeClone(): self
    {
        return new self(
            $this->intentId,
            self::WITHHELD_PROVIDER_SUBJECT,
            $this->amount,
            false,
            false,
            $this->serviceState,
            $this->idempotencyKey,
            $this->createdAt
        );
    }

    public function intentId(): string { return $this->intentId; }
    public function donorReference(): string { return $this->donorReference; }
    public function amount(): Money { return $this->amount; }
    /** @deprecated Always false under the one-time-only constitution. */
    public function monthly(): bool { return false; }
    /** @deprecated Always false under the one-time-only constitution. */
    public function explicitMonthlyConsent(): bool { return false; }
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
            'donation_type' => 'one_time',
            'recurring' => false,
            'service_state' => $this->serviceState->value,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
