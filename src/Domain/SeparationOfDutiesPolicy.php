<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class SeparationOfDutiesPolicy
{
    /** @param list<string> $actorReferences */
    public function assertDistinct(array $actorReferences, string $operation): void
    {
        $normalized = [];
        foreach ($actorReferences as $reference) {
            $reference = trim($reference);
            if ($reference === '') {
                throw new InvariantViolation('Financial duty actor is missing for ' . $operation . '.');
            }
            $normalized[] = $reference;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvariantViolation('Financial duties must be separated for ' . $operation . '.');
        }
    }

    public function assertRefundWorkflow(string $requester, string $reviewer, string $executor): void
    {
        $this->assertDistinct([$requester, $reviewer, $executor], 'refund workflow');
    }

    public function assertHighRiskOperation(string $requester, string $approver, string $executor): void
    {
        $this->assertDistinct([$requester, $approver, $executor], 'high-risk financial operation');
    }

    /** @param list<FinancialCapability> $capabilities */
    public function assertNoToxicCombination(array $capabilities): void
    {
        $values = array_map(static fn (FinancialCapability $capability): string => $capability->value, $capabilities);
        $sets = [
            [FinancialCapability::MANAGE_PRICING->value, FinancialCapability::APPROVE_PRICING->value],
            [FinancialCapability::REVIEW_REFUNDS->value, FinancialCapability::EXECUTE_REFUNDS->value],
            [FinancialCapability::OPERATE_PAYMENTS->value, FinancialCapability::VIEW_AUDIT->value],
            [FinancialCapability::MANAGE_PROVIDER_KEYS->value, FinancialCapability::RECONCILE->value],
        ];

        foreach ($sets as [$left, $right]) {
            if (in_array($left, $values, true) && in_array($right, $values, true)) {
                throw new InvariantViolation('Toxic financial capability combination is prohibited.');
            }
        }
    }
}
