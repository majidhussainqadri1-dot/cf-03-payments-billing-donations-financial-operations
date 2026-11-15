<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

/**
 * Enforce CF03-FR-001/007: a payment provider must never receive a checkout
 * request for a product absent from the currently approved canonical catalogue.
 * This is a read-only prerequisite, not a product auto-activation mechanism.
 */
final class ApprovedDonationCatalogGate
{
    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    public function assertReady(DateTimeImmutable $at): void
    {
        $product = $this->repository->get('products', 'donation.one_time');
        if ($product === null
            || ($product['product_id'] ?? null) !== 'donation.one_time'
            || ($product['kind'] ?? null) !== ProductKind::DONATION->value
            || ($product['billing_type'] ?? null) !== BillingType::VOLUNTARY->value
            || ($product['owner'] ?? null) !== 'CF-03'
            || ($product['lifecycle_state'] ?? null) !== 'active'
            || ($product['policy_version'] ?? null) !== PlatformFinancialPolicy::DECISION_ID
            || ($product['entitlement_mapping'] ?? null) !== null
            // Compare immutable governing markers, not arbitrary nonempty text.
            // These source markers DO NOT substitute for independent Founder
            // release authorization or the live/staging approval evidence.
            || ($product['approval_ref'] ?? null) !== PlatformFinancialPolicy::GOVERNING_CF03_PLAN
            || ($product['approved_by'] ?? null) !== 'founder:governing-plan'
            || ($product['staged_by'] ?? null) !== 'system:cf03-migration'
        ) {
            throw new InvariantViolation('No approved active one-time donation catalogue snapshot is available.');
        }

        $effective = $product['effective_at'] ?? null;
        try {
            if (is_string($effective) && $effective !== '') {
                $effective = new DateTimeImmutable($effective);
            }
        } catch (Throwable) {
            $effective = null;
        }
        if (!$effective instanceof DateTimeImmutable || $effective > $at) {
            throw new InvariantViolation('One-time donation catalogue approval is not effective.');
        }
    }
}
