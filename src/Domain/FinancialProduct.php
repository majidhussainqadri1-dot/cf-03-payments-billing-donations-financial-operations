<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialProduct
{
    public function __construct(
        private readonly string $productId,
        private readonly ProductKind $kind,
        private readonly BillingType $billingType,
        private readonly string $owner,
        private readonly ?string $entitlementCode,
        private readonly string $refundPolicyVersion,
        private readonly string $cancellationPolicyVersion,
        private readonly bool $available,
        private readonly bool $approved,
        private readonly string $approvalReference
    ) {
        self::assertIdentifier($productId, 'Product ID');
        self::assertIdentifier($owner, 'Product owner');
        self::assertIdentifier($refundPolicyVersion, 'Refund policy version');
        self::assertIdentifier($cancellationPolicyVersion, 'Cancellation policy version');

        if ($approved) {
            self::assertIdentifier($approvalReference, 'Product approval reference');
        }

        if ($kind !== ProductKind::DONATION) {
            throw new InvariantViolation(
                'Paid core, education, AI and other non-donation financial products are retired under the current CF-03 constitution.'
            );
        }
        if ($billingType !== BillingType::VOLUNTARY) {
            throw new InvariantViolation('The active donation product must use voluntary one-time financial semantics only.');
        }
        if ($entitlementCode !== null) {
            throw new InvariantViolation('Donation must not map to an entitlement or access advantage.');
        }
    }

    public function productId(): string { return $this->productId; }
    public function kind(): ProductKind { return $this->kind; }
    public function billingType(): BillingType { return $this->billingType; }
    public function owner(): string { return $this->owner; }
    public function entitlementCode(): ?string { return $this->entitlementCode; }
    public function refundPolicyVersion(): string { return $this->refundPolicyVersion; }
    public function cancellationPolicyVersion(): string { return $this->cancellationPolicyVersion; }
    public function approvalReference(): string { return $this->approvalReference; }

    public function isCheckoutEligible(): bool
    {
        return $this->available && $this->approved;
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
