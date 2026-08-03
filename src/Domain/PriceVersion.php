<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PriceVersion
{
    public function __construct(
        private readonly string $productId,
        private readonly string $versionId,
        private readonly Money $amount,
        private readonly string $region,
        private readonly TaxMode $taxMode,
        private readonly DateTimeImmutable $effectiveFrom,
        private readonly ?DateTimeImmutable $effectiveUntil,
        private readonly string $refundPolicyVersion,
        private readonly string $cancellationPolicyVersion,
        private readonly bool $approved,
        private readonly string $approvalReference
    ) {
        self::assertIdentifier($productId, 'Price product ID');
        self::assertIdentifier($versionId, 'Price version ID');
        self::assertIdentifier($refundPolicyVersion, 'Refund policy version');
        self::assertIdentifier($cancellationPolicyVersion, 'Cancellation policy version');

        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Price amount must be positive.');
        }

        if (preg_match('/^(GLOBAL|[A-Z]{2})$/', $region) !== 1) {
            throw new InvalidArgumentException('Price region must be GLOBAL or a two-letter uppercase code.');
        }

        if ($effectiveUntil !== null && $effectiveUntil <= $effectiveFrom) {
            throw new InvalidArgumentException('Price effective-until must be later than effective-from.');
        }

        if ($approved) {
            self::assertIdentifier($approvalReference, 'Price approval reference');
        }
    }

    public function productId(): string { return $this->productId; }
    public function versionId(): string { return $this->versionId; }
    public function amount(): Money { return $this->amount; }
    public function region(): string { return $this->region; }
    public function taxMode(): TaxMode { return $this->taxMode; }
    public function effectiveFrom(): DateTimeImmutable { return $this->effectiveFrom; }
    public function effectiveUntil(): ?DateTimeImmutable { return $this->effectiveUntil; }
    public function approved(): bool { return $this->approved; }
    public function approvalReference(): string { return $this->approvalReference; }

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $this->approved
            && $at >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $at < $this->effectiveUntil);
    }

    public function assertForProduct(FinancialProduct $product): void
    {
        if ($product->productId() !== $this->productId) {
            throw new InvariantViolation('Price version does not belong to the supplied product.');
        }

        if ($product->kind() === ProductKind::DONATION) {
            throw new InvariantViolation('Donation amounts are donor-confirmed and must not use a fixed price version.');
        }

        if ($product->refundPolicyVersion() !== $this->refundPolicyVersion
            || $product->cancellationPolicyVersion() !== $this->cancellationPolicyVersion
        ) {
            throw new InvariantViolation('Price snapshot policy versions do not match the product registry.');
        }
    }

    public function snapshotHash(): string
    {
        return hash('sha256', implode('|', [
            $this->productId,
            $this->versionId,
            (string) $this->amount->minorUnits(),
            $this->amount->currency(),
            $this->region,
            $this->taxMode->value,
            $this->effectiveFrom->format(DATE_ATOM),
            $this->effectiveUntil?->format(DATE_ATOM) ?? '',
            $this->refundPolicyVersion,
            $this->cancellationPolicyVersion,
            $this->approved ? '1' : '0',
            $this->approvalReference,
        ]));
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
