<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Contracts\DonationPaymentProvider;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Support\InvariantViolation;

final class NullDonationPaymentProvider implements DonationPaymentProvider
{
    public function providerCode(): string { return 'provider.unconfigured'; }

    public function createHostedDonationCheckout(DonationIntentDraft $intent): HostedCheckoutReference
    {
        throw new InvariantViolation('No approved hosted donation provider is configured; collection remains fail closed.');
    }

    public function queryDonationEvidence(string $providerPaymentReference): ProviderEvidence
    {
        throw new InvariantViolation('No approved hosted donation provider is configured; provider evidence is unavailable.');
    }
}
