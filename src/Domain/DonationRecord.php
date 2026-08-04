<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class DonationRecord
{
    private const STATES = ['intent_created', 'donor_confirmed', 'settled', 'receipt_issued', 'refunded', 'chargedback'];

    public function __construct(
        private readonly string $donationId,
        private readonly string $donorReference,
        private readonly Money $amount,
        private readonly string $purposeCode,
        private readonly bool $recurring,
        private readonly bool $explicitRecurringConsent,
        private readonly bool $anonymousPublicAcknowledgment,
        private readonly DateTimeImmutable $createdAt,
        private string $state = 'intent_created',
        private int $recordVersion = 1,
        private ?string $providerReference = null,
        private ?string $receiptReference = null
    ) {
        foreach ([$donationId, $donorReference, $purposeCode] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Donation reference is invalid.');
            }
        }
        if ($amount->minorUnits() <= 0 || ! in_array($state, self::STATES, true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Donation amount, state or version is invalid.');
        }
        if ($recurring !== $explicitRecurringConsent) {
            throw new InvariantViolation('Recurring donation requires explicit consent and one-time donation cannot carry recurring consent.');
        }
    }

    public function confirmDonor(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'intent_created') {
            throw new InvariantViolation('Donation donor confirmation is invalid for the current state.');
        }
        $this->state = 'donor_confirmed';
        $this->recordVersion++;
    }

    public function settle(TrustedDonationFact $fact, string $providerReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'donor_confirmed') {
            throw new InvariantViolation('Donation cannot settle before donor confirmation.');
        }
        if (! in_array($fact->type(), [DonationFinancialFactType::ONE_TIME_COMPLETED, DonationFinancialFactType::MONTHLY_STARTED], true)) {
            throw new InvariantViolation('Trusted donation fact does not represent settlement.');
        }
        if ($this->recurring !== ($fact->type() === DonationFinancialFactType::MONTHLY_STARTED)) {
            throw new InvariantViolation('Donation settlement recurrence does not match donor consent.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $providerReference) !== 1) {
            throw new InvalidArgumentException('Donation provider reference is invalid.');
        }
        $this->providerReference = $providerReference;
        $this->state = 'settled';
        $this->recordVersion++;
    }

    public function issueReceipt(string $receiptReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'settled') {
            throw new InvariantViolation('Donation receipt requires settled donation.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $receiptReference) !== 1) {
            throw new InvalidArgumentException('Donation receipt reference is invalid.');
        }
        $this->receiptReference = $receiptReference;
        $this->state = 'receipt_issued';
        $this->recordVersion++;
    }

    public function refund(TrustedDonationFact $fact, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['settled', 'receipt_issued'], true)) {
            throw new InvariantViolation('Donation is not refundable from the current state.');
        }
        if ($fact->type() !== DonationFinancialFactType::ONE_TIME_COMPLETED
            && $fact->type() !== DonationFinancialFactType::MONTHLY_CANCELLED
        ) {
            throw new InvariantViolation('Donation refund/cancellation requires an applicable trusted financial fact.');
        }
        $this->state = 'refunded';
        $this->recordVersion++;
    }

    public function markChargedback(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['settled', 'receipt_issued'], true)) {
            throw new InvariantViolation('Donation chargeback is invalid for the current state.');
        }
        $this->state = 'chargedback';
        $this->recordVersion++;
    }

    /** @return array<string,mixed> */
    public function publicProjection(bool $supporterAcknowledgmentConsent = false): array
    {
        return [
            'donation_id' => $this->donationId,
            'amount_public' => false,
            'donor_reference' => null,
            'supporter_acknowledgment' => $supporterAcknowledgmentConsent && ! $this->anonymousPublicAcknowledgment,
            'privileges' => [],
        ];
    }

    /** @return array<string,mixed> */
    public function financeSnapshot(): array
    {
        return [
            'donation_id' => $this->donationId,
            'donor_reference' => $this->donorReference,
            'amount_minor' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'purpose_code' => $this->purposeCode,
            'recurring' => $this->recurring,
            'state' => $this->state,
            'provider_reference' => $this->providerReference,
            'receipt_reference' => $this->receiptReference,
            'record_version' => $this->recordVersion,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale donation record version.');
        }
    }
}
