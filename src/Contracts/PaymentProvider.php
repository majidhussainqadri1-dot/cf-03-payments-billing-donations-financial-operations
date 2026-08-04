<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;

interface PaymentProvider
{
    public function providerId(): string;
    /** @return list<string> */
    public function currencies(): array;
    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference;
    public function verifyWebhook(string $rawBody, array $headers, int $receivedAt): ProviderEvidence;
    public function refund(string $providerPaymentReference, Money $amount, string $idempotencyKey): string;
    /** @return iterable<array<string,mixed>> */
    public function settlements(string $fromDate, string $toDate): iterable;
    public function health(): string;
}
