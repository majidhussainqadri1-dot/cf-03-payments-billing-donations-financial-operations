<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\PriceVersion;
use Sabri\CF03\Support\InvariantViolation;

final class CheckoutCommand
{
    public function __construct(
        private readonly string $paymentIntentId,
        private readonly string $userReference,
        private readonly FinancialProduct $product,
        private readonly PriceVersion $priceVersion,
        private readonly Money $amount,
        private readonly string $idempotencyKey,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly string $returnPath,
        ?PlatformFinancialPolicy $platformPolicy = null
    ) {
        self::assertReference($paymentIntentId, 'Payment intent ID');
        self::assertReference($userReference, 'User reference');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Checkout idempotency key is invalid.');
        }

        if ($expiresAt <= $createdAt || $expiresAt > $createdAt->modify('+24 hours')) {
            throw new InvalidArgumentException('Checkout expiry must be after creation and no more than 24 hours later.');
        }

        if (! str_starts_with($returnPath, '/')
            || str_starts_with($returnPath, '//')
            || str_contains($returnPath, '\\')
            || str_contains($returnPath, "\r")
            || str_contains($returnPath, "\n")
        ) {
            throw new InvalidArgumentException('Checkout return destination must be a safe same-origin path.');
        }

        ($platformPolicy ?? new PlatformFinancialPolicy())->assertCollectibleProduct($product);

        if (! $product->isCheckoutEligible()) {
            throw new InvariantViolation('Checkout requires an approved and available product.');
        }

        $priceVersion->assertForProduct($product);
        if (! $priceVersion->isEffectiveAt($createdAt)) {
            throw new InvariantViolation('Checkout requires an approved price version effective at creation time.');
        }

        if (! $priceVersion->amount()->equals($amount)) {
            throw new InvariantViolation('Checkout amount must equal the server-resolved price snapshot.');
        }
    }

    /** @return array<string,mixed> */
    public function toProviderRequest(): array
    {
        return [
            'payment_intent_id' => $this->paymentIntentId,
            'product_id' => $this->product->productId(),
            'price_version_id' => $this->priceVersion->versionId(),
            'price_snapshot_hash' => $this->priceVersion->snapshotHash(),
            'amount_minor_units' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'idempotency_key' => $this->idempotencyKey,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'return_path' => $this->returnPath,
        ];
    }

    public function paymentIntentId(): string { return $this->paymentIntentId; }
    public function amount(): Money { return $this->amount; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }

    private static function assertReference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
