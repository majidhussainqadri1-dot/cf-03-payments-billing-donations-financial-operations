<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\PaidCapabilityAuthorization;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Compatibility tombstone for the superseded paid-subscription model.
 * All public operations fail closed so old callers cannot revive renewal/grace
 * semantics after the one-free-tier constitution became governing.
 */
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
    ): array { return $this->retired(); }

    /** @return array<string,mixed> */
    public function activate(string $subscriptionId, string $decisionReference, int $expectedVersion, DateTimeImmutable $at): array
    { return $this->retired(); }

    /** @return array<string,mixed> */
    public function markPastDue(
        string $subscriptionId,
        string $decisionReference,
        int $attempt,
        DateTimeImmutable $failedAt,
        int $expectedVersion
    ): array { return $this->retired(); }

    /** @return array<string,mixed> */
    public function enterGrace(string $subscriptionId, string $decisionReference, int $expectedVersion, DateTimeImmutable $at): array
    { return $this->retired(); }

    /** @return array<string,mixed> */
    public function scheduleCancellation(
        string $subscriptionId,
        string $actorReference,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array { return $this->retired(); }

    /** @return array<string,mixed> */
    public function cancel(string $subscriptionId, string $decisionReference, int $expectedVersion, DateTimeImmutable $at): array
    { return $this->retired(); }

    /** @return array<string,mixed> */
    public function resume(
        string $subscriptionId,
        string $actorReference,
        string $decisionReference,
        int $expectedVersion,
        DateTimeImmutable $at
    ): array { return $this->retired(); }

    /** @return never */
    private function retired(): array
    {
        throw new InvariantViolation(
            'Paid subscription, renewal, grace and dunning operations are retired under the single-free-tier CF-03 constitution.'
        );
    }
}
