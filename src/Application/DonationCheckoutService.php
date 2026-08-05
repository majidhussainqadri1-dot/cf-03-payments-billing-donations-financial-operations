<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class DonationCheckoutService
{
    public function __construct(
        private readonly RuntimeConfiguration $configuration,
        private readonly DonationProviderRegistry $providers,
        private readonly QueryableFinancialRepository $repository
    ) {}

    /** @return array<string,mixed> */
    public function create(DonationIntentDraft $draft, string $providerCode): array
    {
        $this->configuration->assertDonationCheckoutReady();
        $draft->assertProviderCheckoutAvailable();
        if ($providerCode !== $this->configuration->providerCode()) {
            throw new InvariantViolation('Requested donation provider does not match the approved runtime provider.');
        }

        $requestHash = $this->requestHash($draft, $providerCode);
        $claimId = 'idem.'.substr(
            hash('sha256', 'donation-checkout|'.$draft->donorReference().'|'.$draft->idempotencyKey()),
            0,
            48
        );
        $existing = $this->repository->get('idempotency', $claimId);
        if ($existing !== null) {
            return $this->resumeClaim($existing, $requestHash, $draft, $providerCode);
        }

        $now = $draft->createdAt();
        $this->repository->insert('idempotency', $claimId, [
            'scope' => 'donation_checkout',
            'idempotency_key' => $claimId,
            'actor_ref' => $draft->donorReference(),
            'request_hash' => $requestHash,
            'state' => 'pending',
            'result_ref' => null,
            'created_at' => $now,
            'completed_at' => null,
            'expires_at' => $now->modify('+24 hours'),
        ]);

        try {
            $provider = $this->providers->get($providerCode);
            $checkout = $provider->createHostedDonationCheckout($draft->providerSafeClone());
            $this->assertCheckoutUsable($checkout, $providerCode, $draft->createdAt());
            $updated = $this->repository->updateWhere('idempotency', [
                'idempotency_key' => $claimId,
                'state' => 'pending',
            ], [
                'state' => 'provider_created',
                'result_ref' => $checkout->providerSessionReference(),
            ]);
            if ($updated !== 1) {
                throw new InvariantViolation('Hosted donation checkout could not be durably checkpointed.');
            }
            return $this->persistProviderCreated(
                $draft,
                $providerCode,
                $checkout,
                $requestHash,
                $claimId,
                false
            );
        } catch (Throwable $error) {
            $this->repository->updateWhere('idempotency', [
                'idempotency_key' => $claimId,
                'state' => 'pending',
            ], [
                'state' => 'failed',
                'completed_at' => new DateTimeImmutable('now'),
            ]);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function resumeClaim(
        array $claim,
        string $requestHash,
        DonationIntentDraft $draft,
        string $providerCode
    ): array {
        if (($claim['request_hash'] ?? null) !== $requestHash) {
            throw new InvariantViolation('Donation idempotency key was reused with a different request.');
        }
        if (($claim['actor_ref'] ?? null) !== $draft->donorReference()
            || ($claim['scope'] ?? null) !== 'donation_checkout'
        ) {
            throw new InvariantViolation('Donation idempotency claim is outside the canonical actor or scope.');
        }
        $state = (string)($claim['state'] ?? '');
        if ($state === 'completed') {
            return $this->replayCompleted($claim, $requestHash, $draft->createdAt());
        }
        if ($state === 'provider_created' && is_string($claim['result_ref'] ?? null)) {
            $checkout = $this->providers->get($providerCode)
                ->resumeHostedDonationCheckout((string)$claim['result_ref']);
            if (!hash_equals((string)$claim['result_ref'], $checkout->providerSessionReference())) {
                throw new InvariantViolation('Resumed hosted checkout does not match the durable provider checkpoint.');
            }
            $this->assertCheckoutUsable($checkout, $providerCode, $draft->createdAt());
            return $this->persistProviderCreated(
                $draft,
                $providerCode,
                $checkout,
                $requestHash,
                (string)$claim['idempotency_key'],
                true
            );
        }
        throw new InvariantViolation('Donation checkout request is already in progress or previously failed.');
    }

    /** @return array<string,mixed> */
    private function persistProviderCreated(
        DonationIntentDraft $draft,
        string $providerCode,
        HostedCheckoutReference $checkout,
        string $requestHash,
        string $claimId,
        bool $recovered
    ): array {
        $existingIntent = $this->repository->get('intents', $draft->intentId());
        if ($existingIntent !== null) {
            $this->assertExistingIntent($existingIntent, $draft, $providerCode, $requestHash, $checkout);
            $this->assertDependentRecords($draft, $providerCode, $checkout);
            $updated = $this->repository->updateWhere('idempotency', [
                'idempotency_key' => $claimId,
                'state' => 'provider_created',
            ], [
                'state' => 'completed',
                'result_ref' => $draft->intentId(),
                'completed_at' => $draft->createdAt(),
            ]);
            if ($updated !== 1) {
                throw new InvariantViolation('Recovered donation checkout claim could not be completed.');
            }
            return $this->result($draft, $providerCode, $checkout, true);
        }

        $now = $draft->createdAt();
        $donationId = self::donationIdForIntent($draft->intentId());
        $consentId = $draft->monthly() ? self::consentIdForIntent($draft->intentId()) : null;
        $traceId = 'trace.'.substr(hash('sha256', $draft->intentId().'|'.$requestHash), 0, 40);

        $this->repository->transaction(function () use (
            $draft,
            $providerCode,
            $checkout,
            $requestHash,
            $claimId,
            $donationId,
            $consentId,
            $traceId,
            $now
        ): void {
            $this->repository->insert('intents', $draft->intentId(), [
                'intent_id' => $draft->intentId(),
                'actor_ref' => $draft->donorReference(),
                'product_id' => $draft->monthly() ? 'donation.monthly' : 'donation.one_time',
                'price_version_id' => null,
                'amount_minor' => $draft->amount()->minorUnits(),
                'currency' => $draft->amount()->currency(),
                'provider' => $providerCode,
                'provider_ref' => $checkout->providerSessionReference(),
                'state' => 'provider_pending',
                'failure_code' => null,
                'idempotency_key' => $claimId,
                'request_hash' => $requestHash,
                'expires_at' => $checkout->expiresAt(),
                'record_version' => 1,
                'trace_id' => $traceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($consentId !== null) {
                $this->repository->insert('recurring_consents', $consentId, [
                    'consent_id' => $consentId,
                    'actor_ref' => $draft->donorReference(),
                    'product_id' => 'donation.monthly',
                    'amount_minor' => $draft->amount()->minorUnits(),
                    'currency' => $draft->amount()->currency(),
                    'interval_code' => 'month',
                    'next_charge_at' => $now->modify('+1 month'),
                    'terms_hash' => hash(
                        'sha256',
                        'donation.monthly|'.$draft->amount()->minorUnits().'|'.$draft->amount()->currency()
                    ),
                    'cancellation_path' => '/billing/donations',
                    'state' => 'pending_provider',
                    'captured_at' => $now,
                    'revoked_at' => null,
                    'record_version' => 1,
                ]);
            }
            $this->repository->insert('donations', $donationId, [
                'donation_id' => $donationId,
                'donor_ref' => $draft->donorReference(),
                'amount_minor' => $draft->amount()->minorUnits(),
                'currency' => $draft->amount()->currency(),
                'purpose_code' => 'institutional_sustainability_and_homeopathy_advancement',
                'recurring' => $draft->monthly(),
                'recurring_consent_id' => $consentId,
                'provider_ref' => $checkout->providerSessionReference(),
                'receipt_ref' => null,
                'state' => 'provider_pending',
                'anonymous_public' => true,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $updated = $this->repository->updateWhere('idempotency', [
                'idempotency_key' => $claimId,
                'state' => 'provider_created',
            ], [
                'state' => 'completed',
                'result_ref' => $draft->intentId(),
                'completed_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new InvariantViolation('Donation checkout idempotency claim was not completed atomically.');
            }
        });

        return $this->result($draft, $providerCode, $checkout, $recovered);
    }

    /** @return array<string,mixed> */
    private function replayCompleted(array $claim, string $requestHash, DateTimeImmutable $requestedAt): array
    {
        if (($claim['request_hash'] ?? null) !== $requestHash
            || !is_string($claim['result_ref'] ?? null)
        ) {
            throw new InvariantViolation('Completed donation idempotency record is invalid.');
        }
        $intent = $this->repository->get('intents', (string)$claim['result_ref']);
        if ($intent === null) {
            throw new InvariantViolation('Completed donation idempotency record has no canonical intent.');
        }
        $providerCode = (string)($intent['provider'] ?? '');
        if ($providerCode !== $this->configuration->providerCode()) {
            throw new InvariantViolation('Completed donation checkout belongs to a different configured provider.');
        }
        $provider = $this->providers->get($providerCode);
        $checkout = $provider->resumeHostedDonationCheckout((string)$intent['provider_ref']);
        $this->assertCheckoutUsable($checkout, $providerCode, $requestedAt);
        $monthly = ((string)($intent['product_id'] ?? '')) === 'donation.monthly';
        $draft = new DonationIntentDraft(
            (string)$intent['intent_id'],
            (string)$intent['actor_ref'],
            new \Sabri\CF03\Domain\Money((int)$intent['amount_minor'], (string)$intent['currency']),
            $monthly,
            $monthly,
            $this->configuration->state(),
            self::externalIdempotencyKey((string)$claim['idempotency_key']),
            $requestedAt
        );
        $this->assertDependentRecords($draft, $providerCode, $checkout);
        return $this->result($draft, $providerCode, $checkout, true);
    }

    private function assertExistingIntent(
        array $intent,
        DonationIntentDraft $draft,
        string $providerCode,
        string $requestHash,
        HostedCheckoutReference $checkout
    ): void {
        $expectedProduct = $draft->monthly() ? 'donation.monthly' : 'donation.one_time';
        if (($intent['actor_ref'] ?? null) !== $draft->donorReference()
            || ($intent['product_id'] ?? null) !== $expectedProduct
            || (int)($intent['amount_minor'] ?? -1) !== $draft->amount()->minorUnits()
            || ($intent['currency'] ?? null) !== $draft->amount()->currency()
            || ($intent['provider'] ?? null) !== $providerCode
            || ($intent['request_hash'] ?? null) !== $requestHash
            || ($intent['provider_ref'] ?? null) !== $checkout->providerSessionReference()
            || self::date($intent['expires_at'] ?? null) != $checkout->expiresAt()
        ) {
            throw new InvariantViolation('Existing donation intent does not match the durable checkout checkpoint.');
        }
    }

    private function assertDependentRecords(
        DonationIntentDraft $draft,
        string $providerCode,
        HostedCheckoutReference $checkout
    ): void {
        $donation = $this->repository->get('donations', self::donationIdForIntent($draft->intentId()));
        if ($donation === null
            || ($donation['donor_ref'] ?? null) !== $draft->donorReference()
            || (int)($donation['amount_minor'] ?? -1) !== $draft->amount()->minorUnits()
            || ($donation['currency'] ?? null) !== $draft->amount()->currency()
            || (bool)($donation['recurring'] ?? false) !== $draft->monthly()
            || ($donation['provider_ref'] ?? null) !== $checkout->providerSessionReference()
            || !in_array((string)($donation['state'] ?? ''), [
                'provider_pending', 'settled', 'partially_refunded', 'refunded', 'disputed',
            ], true)
        ) {
            throw new InvariantViolation('Canonical donation record is missing or inconsistent with the checkout intent.');
        }
        if ($providerCode !== $this->configuration->providerCode()) {
            throw new InvariantViolation('Donation aggregate provider does not match runtime configuration.');
        }
        if (!$draft->monthly()) {
            return;
        }
        $consent = $this->repository->get('recurring_consents', self::consentIdForIntent($draft->intentId()));
        if ($consent === null
            || ($consent['actor_ref'] ?? null) !== $draft->donorReference()
            || ($consent['product_id'] ?? null) !== 'donation.monthly'
            || (int)($consent['amount_minor'] ?? -1) !== $draft->amount()->minorUnits()
            || ($consent['currency'] ?? null) !== $draft->amount()->currency()
            || ($consent['interval_code'] ?? null) !== 'month'
            || !in_array((string)($consent['state'] ?? ''), [
                'pending_provider', 'active', 'cancellation_pending', 'amount_change_pending',
                'cancelled', 'uncertain',
            ], true)
        ) {
            throw new InvariantViolation('Canonical recurring consent is missing or inconsistent with the checkout intent.');
        }
    }

    private function assertCheckoutUsable(
        HostedCheckoutReference $checkout,
        string $providerCode,
        DateTimeImmutable $requestedAt
    ): void {
        if ($checkout->providerCode() !== $providerCode) {
            throw new InvariantViolation('Hosted checkout provider identity mismatch.');
        }
        if ($checkout->issuedAt() > $requestedAt->modify('+5 minutes')) {
            throw new InvariantViolation('Hosted checkout issue time is implausibly in the future.');
        }
        if ($checkout->expiresAt() <= $requestedAt) {
            throw new InvariantViolation('Hosted donation checkout has expired and cannot be replayed.');
        }
    }

    /** @return array<string,mixed> */
    private function result(
        DonationIntentDraft $draft,
        string $providerCode,
        HostedCheckoutReference $checkout,
        bool $reused
    ): array {
        return [
            'status' => 'provider_pending',
            'intent_id' => $draft->intentId(),
            'provider' => $providerCode,
            'provider_session_reference' => $checkout->providerSessionReference(),
            'hosted_url' => $checkout->hostedUrl(),
            'expires_at' => $checkout->expiresAt()->format(DATE_ATOM),
            'monthly' => $draft->monthly(),
            'amount_minor' => $draft->amount()->minorUnits(),
            'currency' => $draft->amount()->currency(),
            'reused' => $reused,
        ];
    }

    private function requestHash(DonationIntentDraft $draft, string $providerCode): string
    {
        try {
            return hash('sha256', json_encode([
                'actor' => $draft->donorReference(),
                'provider' => $providerCode,
                'intent_id' => $draft->intentId(),
                'amount_minor_units' => $draft->amount()->minorUnits(),
                'currency' => $draft->amount()->currency(),
                'monthly' => $draft->monthly(),
                'explicit_monthly_consent' => $draft->explicitMonthlyConsent(),
                'idempotency_key' => $draft->idempotencyKey(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $error) {
            throw new InvariantViolation('Donation request could not be canonicalized.', 0, $error);
        }
    }

    private static function externalIdempotencyKey(string $claimId): string
    {
        return 'replay-'.substr(hash('sha256', $claimId), 0, 48);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }
        throw new InvariantViolation('Donation checkout timestamp is missing.');
    }

    public static function donationIdForIntent(string $intentId): string
    {
        return 'donation.'.substr(hash('sha256', $intentId), 0, 40);
    }

    public static function consentIdForIntent(string $intentId): string
    {
        return 'consent.'.substr(hash('sha256', $intentId), 0, 40);
    }
}
