<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Contracts\PaymentProvider;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Support\InvariantViolation;

final class NullPaymentProvider implements PaymentProvider
{
    public function providerId(): string { return 'provider.unconfigured'; }
    public function currencies(): array { return []; }

    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference
    {
        throw new InvariantViolation('No approved payment provider is configured; checkout remains fail closed.');
    }

    public function verifyWebhook(string $rawBody, array $headers, int $receivedAt): ProviderEvidence
    {
        throw new InvariantViolation('No approved payment provider is configured; webhook verification is unavailable.');
    }

    public function refund(string $providerPaymentReference, Money $amount, string $idempotencyKey): string
    {
        throw new InvariantViolation('No approved payment provider is configured; refund execution is unavailable.');
    }

    public function settlements(string $fromDate, string $toDate): iterable
    {
        if (false) { yield []; }
        return;
    }

    public function health(): string { return 'unconfigured_fail_closed'; }
}
