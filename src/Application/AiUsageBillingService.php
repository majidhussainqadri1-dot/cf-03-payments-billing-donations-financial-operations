<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\PaidCapabilityAuthorization;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\UsageSigningSecretResolver;
use Sabri\CF03\Domain\AiUsageAuthorization;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Historical compatibility boundary only.
 *
 * Sabri Classical Homeopathy AI is an approved free core capability under the
 * current central and CF-03 constitutions. CF-03 must not meter, sell, charge,
 * gate or post receivables for AI usage. The class remains so old integrations
 * fail explicitly instead of silently falling back to a paid path.
 */
final class AiUsageBillingService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly PaidCapabilityAuthorization $authorization,
        private readonly UsageSigningSecretResolver $secrets,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function authorize(
        AiUsageAuthorization $authorization,
        string $decisionReference,
        DateTimeImmutable $createdAt
    ): array {
        throw new InvariantViolation(
            'AI usage billing is retired: approved Sabri AI core capability is free and cannot be finance- or donor-gated.'
        );
    }

    /** @return array<string,mixed> */
    public function recordUsage(
        string $usageId,
        string $authorizationId,
        string $producerReference,
        string $keyVersion,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $decisionReference
    ): array {
        throw new InvariantViolation(
            'Financial AI metering is prohibited under CF-03 v1.0; reliability/fair-use controls must live outside financial charging.'
        );
    }
}
