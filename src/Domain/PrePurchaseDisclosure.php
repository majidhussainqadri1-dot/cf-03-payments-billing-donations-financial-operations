<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PrePurchaseDisclosure
{
    public function __construct(
        private readonly string $productId,
        private readonly string $priceVersionId,
        private readonly Money $total,
        private readonly bool $recurring,
        private readonly ?string $interval,
        private readonly ?string $renewalDate,
        private readonly string $taxMode,
        private readonly string $refundPolicyVersion,
        private readonly string $cancellationPolicyVersion,
        private readonly string $provider,
        private readonly string $dataUseNotice,
        private readonly ?Money $providerFee = null,
        private readonly ?Money $taxAmount = null,
        private readonly string $supportPath = '/support',
        private readonly string $cancellationPath = '/billing',
        private readonly ?string $termsSha256 = null,
        private readonly ?string $dataRegion = null
    ) {
        foreach ([$productId, $priceVersionId, $refundPolicyVersion, $cancellationPolicyVersion, $provider, $dataUseNotice] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Disclosure fields must not be empty.');
            }
        }
        if ($recurring && ($interval === null || trim($interval) === '' || $renewalDate === null || trim($renewalDate) === '')) {
            throw new InvalidArgumentException('Recurring products require a billing interval and renewal date.');
        }
        if (! $recurring && ($interval !== null || $renewalDate !== null)) {
            throw new InvalidArgumentException('One-time products cannot claim renewal data.');
        }
        foreach ([$supportPath, $cancellationPath] as $path) {
            if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
                throw new InvalidArgumentException('Disclosure support and cancellation paths must be safe same-origin paths.');
            }
        }
        foreach ([$providerFee, $taxAmount] as $component) {
            if ($component !== null && $component->currency() !== $total->currency()) {
                throw new InvariantViolation('Disclosure totals, provider fees and tax require matching currency.');
            }
        }
        if ($termsSha256 !== null && preg_match('/^[a-f0-9]{64}$/', $termsSha256) !== 1) {
            throw new InvalidArgumentException('Disclosure terms hash is invalid.');
        }
        if ($dataRegion !== null && preg_match('/^[A-Z0-9][A-Z0-9_-]{1,31}$/', $dataRegion) !== 1) {
            throw new InvalidArgumentException('Disclosure data region is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'price_version_id' => $this->priceVersionId,
            'total_minor' => $this->total->minorUnits(),
            'currency' => $this->total->currency(),
            'recurring' => $this->recurring,
            'interval' => $this->interval,
            'renewal_date' => $this->renewalDate,
            'tax_mode' => $this->taxMode,
            'tax_amount_minor' => $this->taxAmount?->minorUnits(),
            'provider_fee_minor' => $this->providerFee?->minorUnits(),
            'refund_policy_version' => $this->refundPolicyVersion,
            'cancellation_policy_version' => $this->cancellationPolicyVersion,
            'provider' => $this->provider,
            'data_use_notice' => $this->dataUseNotice,
            'data_region' => $this->dataRegion,
            'support_path' => $this->supportPath,
            'cancellation_path' => $this->cancellationPath,
            'terms_sha256' => $this->termsSha256,
        ];
    }
}
