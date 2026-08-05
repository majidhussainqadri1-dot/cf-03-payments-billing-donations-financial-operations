<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\PaidCapabilityAuthorization;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Support\InvariantViolation;

final class SubscriptionOperationsService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly PaidCapabilityAuthorization $authorization,
        private readonly FinancialAuditService $audit,
        private readonly DunningPolicy $dunning = new DunningPolicy()
    ) {}

    /** @return array<string,mixed> */
    public function create(
        string $subscriptionId,
        string $actorReference,
        string $productId,
        string $priceVersionId,
        string $providerReference,
        string $decisionReference,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $createdAt
    ): array {
        $this->authorization->assertAuthorized('subscription', $decisionReference);
        self::reference($actorReference, 'Subscription actor reference');
        self::reference($providerReference, 'Subscription provider reference');
        $product = $this->repository->get('products', $productId);
        $price = $this->repository->get('prices', $priceVersionId);
        if ($product === null || $price === null
            || ($product['lifecycle_state'] ?? null) !== 'active'
            || ($price['approval_state'] ?? null) !== 'approved'
            || ($price['product_id'] ?? null) !== $productId
            || ($product['kind'] ?? null) === ProductKind::DONATION->value
        ) {
            throw new InvariantViolation('Subscription requires an approved non-donation product and price version.');
        }
        $consents = $this->repository->find('recurring_consents', [
            'actor_ref' => $actorReference,
            'product_id' => $productId,
            'state' => 'active',
        ], 2);
        if (count($consents) !== 1) {
            throw new InvariantViolation('Subscription requires one active recurring consent in actor and product scope.');
        }

        $subscription = new Subscription(
            $subscriptionId,
            $actorReference,
            $productId,
            $priceVersionId,
            $providerReference,
            $periodStart,
            $periodEnd,
            'pending',
            false,
            $decisionReference
        );
        $snapshot = $subscription->snapshot();
        $existing = $this->repository->get('subscriptions', $subscriptionId);
        if ($existing !== null) {
            if (($existing['actor_ref'] ?? null) === $actorReference
                && ($existing['product_id'] ?? null) === $productId
                && ($existing['price_version_id'] ?? null) === $priceVersionId
            ) {
                return self::safe($existing) + ['reused' => true];
            }
            throw new InvariantViolation('Subscription identifier already exists with different terms.');
        }

        $record = [
            'subscription_id' => $subscriptionId,
            'actor_ref' => $actorReference,
            'product_id' => $productId,
            'price_version_id' => $priceVersionId,
            'provider_ref' => $providerReference,
            'state' => 'pending',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'policy_version' => $decisionReference,
            'record_version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $this->repository->insert('subscriptions', $subscriptionId, $record);
        $this->event('SubscriptionCreated', $subscriptionId, 1, $record, $createdAt);
        return self::safe($record) + ['version' => $snapshot['record_version'], 'reused' => false];
    }

    /** @return array<string,mixed> */
    public function activate(
        string $subscriptionId,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array {
        return $this->transition($subscriptionId, 'active', $decisionReference, $expectedVersion, $at, 'SubscriptionActivated');
    }

    /** @return array<string,mixed> */
    public function markPastDue(
        string $subscriptionId,
        string $decisionReference,
        int $attempt,
        DateTimeImmutable $failedAt,
        int $expectedVersion
    ): array {
        $updated = $this->transition($subscriptionId, 'past_due', $decisionReference, $expectedVersion, $failedAt, 'SubscriptionPastDue');
        $next = $this->dunning->nextRetryAt($failedAt, $attempt);
        $eventType = $next === null ? 'SubscriptionGraceRequired' : 'SubscriptionRetryScheduled';
        $this->event($eventType, $subscriptionId, (int)$updated['version'], [
            'subscription_id' => $subscriptionId,
            'attempt' => $attempt,
            'next_retry_at' => $next?->format(DATE_ATOM),
            'policy_version' => DunningPolicy::VERSION,
        ], $failedAt);
        return $updated + ['next_retry_at' => $next?->format(DATE_ATOM)];
    }

    /** @return array<string,mixed> */
    public function enterGrace(
        string $subscriptionId,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array {
        return $this->transition($subscriptionId, 'grace', $decisionReference, $expectedVersion, $at, 'SubscriptionGraceStarted');
    }

    /** @return array<string,mixed> */
    public function scheduleCancellation(
        string $subscriptionId,
        string $actorReference,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array {
        $this->authorization->assertAuthorized('subscription', $decisionReference);
        $record = $this->require($subscriptionId, $expectedVersion);
        if (!hash_equals((string)$record['actor_ref'], $actorReference)) {
            throw new InvariantViolation('Subscription cancellation is outside actor scope.');
        }
        $subscription = $this->hydrate($record);
        $subscription->scheduleCancellation($expectedVersion);
        $updated = $this->repository->compareAndSwap(
            'subscriptions',
            $subscriptionId,
            $expectedVersion,
            static function (array $current) use ($at): array {
                $current['cancel_at_period_end'] = true;
                $current['updated_at'] = $at;
                return $current;
            }
        );
        $this->event('SubscriptionCancellationScheduled', $subscriptionId, (int)$updated['version'], self::safe($updated), $at);
        return self::safe($updated);
    }

    /** @return array<string,mixed> */
    public function cancel(
        string $subscriptionId,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array {
        return $this->transition($subscriptionId, 'cancelled', $decisionReference, $expectedVersion, $at, 'SubscriptionCancelled');
    }

    /** @return array<string,mixed> */
    public function resume(
        string $subscriptionId,
        string $actorReference,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array {
        $this->authorization->assertAuthorized('subscription', $decisionReference);
        $record = $this->require($subscriptionId, $expectedVersion);
        if (!hash_equals((string)$record['actor_ref'], $actorReference)) {
            throw new InvariantViolation('Subscription resume is outside actor scope.');
        }
        $subscription = $this->hydrate($record);
        $subscription->resume($expectedVersion);
        $updated = $this->repository->compareAndSwap(
            'subscriptions',
            $subscriptionId,
            $expectedVersion,
            static function (array $current) use ($at): array {
                $current['cancel_at_period_end'] = false;
                $current['updated_at'] = $at;
                return $current;
            }
        );
        $this->event('SubscriptionResumed', $subscriptionId, (int)$updated['version'], self::safe($updated), $at);
        return self::safe($updated);
    }

    /** @return array<string,mixed> */
    private function transition(
        string $subscriptionId,
        string $targetState,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at,
        string $eventType
    ): array {
        $this->authorization->assertAuthorized('subscription', $decisionReference);
        $record = $this->require($subscriptionId, $expectedVersion);
        if (($record['policy_version'] ?? null) !== $decisionReference) {
            throw new InvariantViolation('Subscription decision reference does not match its immutable policy snapshot.');
        }
        $subscription = $this->hydrate($record);
        $subscription->transition($targetState, $expectedVersion);
        $updated = $this->repository->compareAndSwap(
            'subscriptions',
            $subscriptionId,
            $expectedVersion,
            static function (array $current) use ($targetState, $at): array {
                $current['state'] = $targetState;
                $current['updated_at'] = $at;
                return $current;
            }
        );
        $this->event($eventType, $subscriptionId, (int)$updated['version'], self::safe($updated), $at);
        return self::safe($updated);
    }

    /** @return array<string,mixed> */
    private function require(string $subscriptionId, int $expectedVersion): array
    {
        $record = $this->repository->get('subscriptions', $subscriptionId);
        if ($record === null || (int)($record['version'] ?? 0) !== $expectedVersion) {
            throw new InvariantViolation('Subscription is missing or stale.');
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function hydrate(array $record): Subscription
    {
        return new Subscription(
            (string)$record['subscription_id'],
            (string)$record['actor_ref'],
            (string)$record['product_id'],
            (string)$record['price_version_id'],
            (string)$record['provider_ref'],
            self::date($record['period_start']),
            self::date($record['period_end']),
            (string)$record['state'],
            (bool)$record['cancel_at_period_end'],
            (string)$record['policy_version'],
            (int)$record['version']
        );
    }

    /** @param array<string,mixed> $payload */
    private function event(string $eventType, string $subscriptionId, int $version, array $payload, DateTimeImmutable $at): void
    {
        $safe = [
            'subscription_id' => $subscriptionId,
            'actor_ref' => $payload['actor_ref'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'price_version_id' => $payload['price_version_id'] ?? null,
            'state' => $payload['state'] ?? null,
            'attempt' => $payload['attempt'] ?? null,
            'next_retry_at' => $payload['next_retry_at'] ?? null,
            'policy_version' => $payload['policy_version'] ?? null,
            'occurred_at' => $at->format(DATE_ATOM),
        ];
        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new InvariantViolation('Subscription event could not be encoded.');
        }
        $eventId = 'event.subscription.'.substr(hash('sha256', $eventType.'|'.$subscriptionId.'|'.$version.'|'.$encoded), 0, 32);
        if ($this->repository->get('outbox', $eventId) === null) {
            $this->repository->insert('outbox', $eventId, [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'aggregate_id' => $subscriptionId,
                'aggregate_version' => (string)$version,
                'schema_version' => '1.0',
                'trace_id' => 'trace:subscription:'.substr(hash('sha256', $subscriptionId), 0, 24),
                'payload_json' => $safe,
                'payload_hash' => hash('sha256', $encoded),
                'state' => 'pending',
                'attempts' => 0,
                'available_at' => $at,
                'leased_until' => null,
                'last_error_code' => null,
                'created_at' => $at,
                'delivered_at' => null,
            ]);
        }
        $this->audit->append(new AuditEnvelope(
            'audit:subscription:'.substr(hash('sha256', $eventId), 0, 32),
            (string)($safe['actor_ref'] ?? 'system:subscription'),
            strtolower($eventType),
            'subscription',
            $subscriptionId,
            'dormant_paid_capability',
            AuditOutcome::SUCCEEDED,
            $at,
            'trace:subscription:'.substr(hash('sha256', $subscriptionId), 0, 24),
            ['event_type' => $eventType, 'policy_version' => $safe['policy_version']]
        ));
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('Subscription date is missing.');
        }
        return new DateTimeImmutable($value);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function safe(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'subscription_id','actor_ref','product_id','price_version_id','state','period_start',
            'period_end','cancel_at_period_end','policy_version','record_version','version',
            'created_at','updated_at',
        ]));
    }
}
