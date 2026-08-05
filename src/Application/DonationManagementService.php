<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\RecurringDonationProvider;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class DonationManagementService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly DonationProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function view(string $actorReference): array
    {
        self::reference($actorReference, 'Donation-management actor');
        $records = $this->repository->find('recurring_consents', ['actor_ref' => $actorReference], 100);
        $safe = [];
        foreach ($records as $record) {
            $safe[] = array_intersect_key($record, array_flip([
                'consent_id','product_id','amount_minor','currency','interval_code','next_charge_at',
                'state','captured_at','revoked_at','version','record_version',
            ]));
        }
        return [
            'recurring_donations' => $safe,
            'cancellation_must_be_easy' => true,
            'automatic_renewal_without_explicit_consent' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function cancel(
        string $consentId,
        string $actorReference,
        string $idempotencyKey,
        int $expectedVersion,
        DateTimeImmutable $now
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::reference($consentId, 'Recurring donation consent');
        self::reference($actorReference, 'Recurring donation actor');
        $this->assertIdempotency($idempotencyKey);
        $record = $this->ownedActive($consentId, $actorReference, $expectedVersion);
        $providerRef = $this->providerReference($record);
        $provider = $this->providers->get($this->configuration->providerCode());
        if (!$provider instanceof RecurringDonationProvider) {
            throw new InvariantViolation('Configured donation provider does not support recurring cancellation.');
        }

        $claim = 'cancel_pending:'.substr(hash('sha256', $consentId.'|'.$idempotencyKey), 0, 40);
        $claimed = $this->repository->compareAndSwap(
            'recurring_consents',
            $consentId,
            $expectedVersion,
            static function (array $current) use ($claim): array {
                if (($current['state'] ?? null) !== 'active') {
                    throw new InvariantViolation('Recurring donation is no longer active for cancellation.');
                }
                $current['state'] = 'cancellation_pending';
                $current['terms_hash'] = $claim;
                return $current;
            }
        );
        $claimedVersion = (int)$claimed['version'];

        try {
            $confirmation = $provider->cancelRecurringDonation($providerRef, $idempotencyKey);
            self::reference($confirmation, 'Recurring cancellation confirmation');
            $confirmed = $this->repository->updateWhere('recurring_consents', [
                'consent_id' => $consentId,
                'state' => 'cancellation_pending',
                'terms_hash' => $claim,
                'record_version' => $claimedVersion,
            ], [
                'state' => 'cancelled',
                'revoked_at' => $now,
                'terms_hash' => hash('sha256', 'cancelled|'.$claim),
            ]);
            if ($confirmed !== 1) {
                throw new InvariantViolation('Recurring cancellation checkpoint changed before provider confirmation.');
            }
            $updated = $this->repository->get('recurring_consents', $consentId);
            if ($updated === null) {
                throw new InvariantViolation('Confirmed recurring cancellation record disappeared.');
            }
            return $this->safe($updated) + ['changed_at' => $now->format(DATE_ATOM)];
        } catch (Throwable $error) {
            $this->markUncertain($consentId, $claimedVersion, 'cancellation_pending', $claim);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function changeAmount(
        string $consentId,
        string $actorReference,
        Money $amount,
        string $idempotencyKey,
        int $expectedVersion,
        DateTimeImmutable $now
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::reference($consentId, 'Recurring donation consent');
        self::reference($actorReference, 'Recurring donation actor');
        $this->assertIdempotency($idempotencyKey);
        (new PlatformFinancialPolicy())->assertSuggestedOrCustomDonation($amount);
        $record = $this->ownedActive($consentId, $actorReference, $expectedVersion);
        if ((int)$record['amount_minor'] === $amount->minorUnits()
            && (string)$record['currency'] === $amount->currency()
        ) {
            return $this->safe($record) + ['changed_at' => null, 'reused' => true];
        }
        $providerRef = $this->providerReference($record);
        $provider = $this->providers->get($this->configuration->providerCode());
        if (!$provider instanceof RecurringDonationProvider) {
            throw new InvariantViolation('Configured donation provider does not support amount changes.');
        }

        $claim = 'amount_pending:'.substr(
            hash('sha256', $consentId.'|'.$amount->minorUnits().'|'.$amount->currency().'|'.$idempotencyKey),
            0,
            40
        );
        $claimed = $this->repository->compareAndSwap(
            'recurring_consents',
            $consentId,
            $expectedVersion,
            static function (array $current) use ($claim): array {
                if (($current['state'] ?? null) !== 'active') {
                    throw new InvariantViolation('Recurring donation is no longer active for amount change.');
                }
                $current['state'] = 'amount_change_pending';
                $current['terms_hash'] = $claim;
                return $current;
            }
        );
        $claimedVersion = (int)$claimed['version'];

        try {
            $confirmation = $provider->changeRecurringDonationAmount($providerRef, $amount, $idempotencyKey);
            self::reference($confirmation, 'Recurring amount-change confirmation');
            $confirmed = $this->repository->updateWhere('recurring_consents', [
                'consent_id' => $consentId,
                'state' => 'amount_change_pending',
                'terms_hash' => $claim,
                'record_version' => $claimedVersion,
            ], [
                'state' => 'active',
                'amount_minor' => $amount->minorUnits(),
                'currency' => $amount->currency(),
                'terms_hash' => hash(
                    'sha256',
                    'donation.monthly|'.$amount->minorUnits().'|'.$amount->currency()
                ),
            ]);
            if ($confirmed !== 1) {
                throw new InvariantViolation('Recurring amount-change checkpoint changed before provider confirmation.');
            }
            $updated = $this->repository->get('recurring_consents', $consentId);
            if ($updated === null) {
                throw new InvariantViolation('Confirmed recurring amount-change record disappeared.');
            }
            return $this->safe($updated) + ['changed_at' => $now->format(DATE_ATOM), 'reused' => false];
        } catch (Throwable $error) {
            $this->markUncertain($consentId, $claimedVersion, 'amount_change_pending', $claim);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function ownedActive(string $consentId, string $actorReference, int $expectedVersion): array
    {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Recurring donation expected version must be positive.');
        }
        $record = $this->repository->get('recurring_consents', $consentId);
        if ($record === null || !hash_equals((string)($record['actor_ref'] ?? ''), $actorReference)) {
            throw new InvariantViolation('Recurring donation was not found in actor scope.');
        }
        if ((int)($record['version'] ?? 0) !== $expectedVersion || ($record['state'] ?? null) !== 'active') {
            throw new InvariantViolation('Recurring donation is stale or not changeable.');
        }
        if (($record['product_id'] ?? null) !== 'donation.monthly'
            || ($record['interval_code'] ?? null) !== 'month'
            || (int)($record['amount_minor'] ?? 0) <= 0
            || ($record['currency'] ?? null) !== 'USD'
        ) {
            throw new InvariantViolation('Recurring donation consent contains inconsistent canonical terms.');
        }
        return $record;
    }

    private function providerReference(array $consent): string
    {
        $consentId = (string)$consent['consent_id'];
        $donations = $this->repository->find('donations', ['recurring_consent_id' => $consentId], 2);
        if (count($donations) !== 1) {
            throw new InvariantViolation('Recurring donation must resolve to exactly one canonical donation record.');
        }
        $donation = $donations[0];
        if (($donation['donor_ref'] ?? null) !== ($consent['actor_ref'] ?? null)
            || !(bool)($donation['recurring'] ?? false)
            || ($donation['currency'] ?? null) !== ($consent['currency'] ?? null)
            || !in_array((string)($donation['state'] ?? ''), ['settled', 'partially_refunded'], true)
            || !is_string($donation['provider_ref'] ?? null)
            || $donation['provider_ref'] === ''
        ) {
            throw new InvariantViolation('Recurring donation provider evidence does not match its canonical consent.');
        }
        return (string)$donation['provider_ref'];
    }

    private function markUncertain(string $consentId, int $claimedVersion, string $expectedState, string $claim): void
    {
        try {
            $this->repository->updateWhere('recurring_consents', [
                'consent_id' => $consentId,
                'state' => $expectedState,
                'terms_hash' => $claim,
                'record_version' => $claimedVersion,
            ], [
                'state' => 'uncertain',
            ]);
        } catch (Throwable) {
            // Reconciliation must inspect the durable claim if the uncertainty marker loses a race.
        }
    }

    /** @return array<string,mixed> */
    private function safe(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'consent_id','product_id','amount_minor','currency','interval_code','next_charge_at',
            'state','captured_at','revoked_at','version','record_version',
        ]));
    }

    private function assertIdempotency(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $key) !== 1) {
            throw new InvalidArgumentException('Recurring donation idempotency key is invalid.');
        }
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
