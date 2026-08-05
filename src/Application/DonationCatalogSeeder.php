<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Support\InvariantViolation;

final class DonationCatalogSeeder
{
    /** @return list<string> */
    public function seed(QueryableFinancialRepository $repository, DateTimeImmutable $at): array
    {
        $definitions = [
            'donation.one_time' => BillingType::VOLUNTARY->value,
            'donation.monthly' => BillingType::VOLUNTARY->value,
        ];
        $seeded = [];
        foreach ($definitions as $productId => $billingType) {
            $expected = [
                'product_id' => $productId,
                'kind' => ProductKind::DONATION->value,
                'billing_type' => $billingType,
                'owner' => 'CF-03',
                'entitlement_mapping' => null,
                'lifecycle_state' => 'active',
                'policy_version' => PlatformFinancialPolicy::DECISION_ID,
                'approval_ref' => PlatformFinancialPolicy::DECISION_ID,
                'staged_by' => 'system:cf03-migration',
                'approved_by' => 'founder:policy-decision',
                'effective_at' => new DateTimeImmutable(PlatformFinancialPolicy::EFFECTIVE_AT),
                'record_version' => 1,
                'created_at' => $at,
                'updated_at' => $at,
            ];
            $existing = $repository->get('products', $productId);
            if ($existing === null) {
                $repository->insert('products', $productId, $expected);
                $seeded[] = $productId;
                continue;
            }
            foreach (['kind','billing_type','owner','lifecycle_state','policy_version','approval_ref'] as $field) {
                if (($existing[$field] ?? null) !== $expected[$field]) {
                    throw new InvariantViolation('Canonical donation product conflicts with the governing financial policy: '.$productId.'.');
                }
            }
            if (($existing['entitlement_mapping'] ?? null) !== null) {
                throw new InvariantViolation('Canonical donation product cannot contain an entitlement mapping.');
            }
            $seeded[] = $productId;
        }
        return $seeded;
    }
}
