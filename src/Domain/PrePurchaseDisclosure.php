<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

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
        private readonly string $dataUseNotice
    ) {
        foreach ([$productId, $priceVersionId, $refundPolicyVersion, $cancellationPolicyVersion, $provider, $dataUseNotice] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Disclosure fields must not be empty.');
            }
        }
        if ($recurring && ($interval === null || trim($interval) === '')) {
            throw new InvalidArgumentException('Recurring products require a billing interval.');
        }
        if (! $recurring && ($interval !== null || $renewalDate !== null)) {
            throw new InvalidArgumentException('One-time products cannot claim renewal data.');
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
            'refund_policy_version' => $this->refundPolicyVersion,
            'cancellation_policy_version' => $this->cancellationPolicyVersion,
            'provider' => $this->provider,
            'data_use_notice' => $this->dataUseNotice,
        ];
    }
}
