<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\ProductLifecycle;
use Sabri\CF03\Support\InvariantViolation;

final class CatalogDisclosureService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function publicCatalog(DateTimeImmutable $at): array
    {
        $policy = new PlatformFinancialPolicy();
        $donations = [];
        foreach ($this->repository->find('products', [
            'kind' => ProductKind::DONATION->value,
            'lifecycle_state' => 'active',
        ], 50) as $record) {
            $donations[] = [
                'product_id' => $record['product_id'],
                'billing_type' => $record['billing_type'],
                'owner' => $record['owner'],
                'policy_version' => $record['policy_version'],
                'effective_at' => self::dateString($record['effective_at'] ?? null),
            ];
        }
        return [
            'as_of' => $at->format(DATE_ATOM),
            'core_services' => [
                'registration' => 'free',
                'membership' => 'free',
                'education' => 'free',
                'ai' => 'free',
                'profile' => 'free',
                'verification' => 'free',
                'listing' => 'free',
                'publishing' => 'free',
            ],
            'fixed_fees_prohibited' => true,
            'clinic_marketplace_commission_basis_points' => 0,
            'collectible_products' => $donations,
            'donation' => [
                'optional' => true,
                'default_amount_minor' => null,
                'default_recurring' => false,
                'suggested_amounts' => array_map(
                    static fn ($money): array => [
                        'minor_units' => $money->minorUnits(),
                        'currency' => $money->currency(),
                    ],
                    $policy->suggestedDonationAmounts()
                ),
                'positive_custom_usd' => true,
                'privilege' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function registerApprovedDonationProduct(
        FinancialProduct $product,
        string $stagedBy,
        string $approvedBy,
        string $activatedBy,
        DateTimeImmutable $at
    ): array {
        (new PlatformFinancialPolicy())->assertCollectibleProduct($product);
        if ($product->kind() !== ProductKind::DONATION || !$product->isCheckoutEligible()) {
            throw new InvariantViolation('Only an approved and available donation product may become active.');
        }
        $lifecycle = new ProductLifecycle($product);
        $lifecycle->stage($stagedBy, $at);
        $lifecycle->approve($approvedBy, $at, 2);
        $lifecycle->activate($activatedBy, $at, 3);

        $record = [
            'product_id' => $product->productId(),
            'kind' => $product->kind()->value,
            'billing_type' => $product->billingType()->value,
            'owner' => $product->ownerReference(),
            'entitlement_mapping' => null,
            'lifecycle_state' => 'active',
            'policy_version' => PlatformFinancialPolicy::DECISION_ID,
            'approval_ref' => $product->approvalReference(),
            'staged_by' => $stagedBy,
            'approved_by' => $approvedBy,
            'effective_at' => $at,
            'record_version' => 4,
            'created_at' => $at,
            'updated_at' => $at,
        ];
        $existing = $this->repository->get('products', $product->productId());
        if ($existing !== null) {
            if (($existing['lifecycle_state'] ?? null) === 'active'
                && ($existing['policy_version'] ?? null) === PlatformFinancialPolicy::DECISION_ID
            ) {
                return $existing + ['reused' => true];
            }
            throw new InvariantViolation('Donation product identifier already exists with a conflicting definition.');
        }

        $this->repository->transaction(function () use ($record, $product, $activatedBy, $at): void {
            $this->repository->insert('products', $product->productId(), $record);
            $this->audit->append(new AuditEnvelope(
                'audit:product:'.substr(hash('sha256', $product->productId().'|'.$at->format(DATE_ATOM)), 0, 32),
                $activatedBy,
                'product_activated',
                'financial_product',
                $product->productId(),
                'approved_donation_catalog',
                AuditOutcome::SUCCEEDED,
                $at,
                'trace:product:'.substr(hash('sha256', $product->productId()), 0, 24),
                [
                    'kind' => ProductKind::DONATION->value,
                    'policy_version' => PlatformFinancialPolicy::DECISION_ID,
                ]
            ));
        });
        return $record + ['reused' => false];
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format(DATE_ATOM);
        }
        return is_string($value) && $value !== '' ? $value : null;
    }
}
