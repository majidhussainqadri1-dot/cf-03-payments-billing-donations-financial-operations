<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Domain\ProviderEvidence;

interface HostedPaymentProvider
{
    public function providerCode(): string;

    public function supportsCurrency(string $currency): bool;

    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference;

    public function queryPaymentEvidence(string $providerPaymentReference): ProviderEvidence;
}
