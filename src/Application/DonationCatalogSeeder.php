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
        $productId = 'donation.one_time';
        $expected = [
            'product_id' => $productId,
            'kind' => ProductKind::DONATION->value,
            'billing_type' => BillingType::VOLUNTARY->value,
            'owner' => 'CF-03',
            'entitlement_mapping' => null,
            'lifecycle_state' => 'active',
            'policy_version' => PlatformFinancialPolicy::DECISION_ID,
            'approval_ref' => PlatformFinancialPolicy::GOVERNING_CF03_PLAN,
            'staged_by' => 'system:cf03-migration',
            'approved_by' => 'founder:governing-plan',
            'effective_at' => new DateTimeImmutable(PlatformFinancialPolicy::EFFECTIVE_AT),
            'record_version' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ];

        $existing = $repository->get('products', $productId);
        if ($existing === null) {
            $repository->insert('products', $productId, $expected);
        } else {
            foreach (['kind','billing_type','owner','lifecycle_state'] as $field) {
                if (($existing[$field] ?? null) !== $expected[$field]) {
                    throw new InvariantViolation('Canonical one-time donation product conflicts with the governing financial policy.');
                }
            }
            if (($existing['entitlement_mapping'] ?? null) !== null) {
                throw new InvariantViolation('Canonical donation product cannot contain an entitlement mapping.');
            }
        }

        // Explicitly retire the former recurring product if an upgraded database still has it.
        $legacy = $repository->get('products', 'donation.monthly');
        if ($legacy !== null) {
            $updated = $repository->updateWhere('products', [
                'product_id' => 'donation.monthly',
            ], [
                'lifecycle_state' => 'retired',
                'policy_version' => PlatformFinancialPolicy::DECISION_ID,
                'approval_ref' => PlatformFinancialPolicy::GOVERNING_CF03_PLAN,
                'updated_at' => $at,
            ]);
            if ($updated !== 1) {
                throw new InvariantViolation('Legacy recurring donation product could not be retired exactly once.');
            }
        }

        return [$productId];
    }
}
