<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Domain\ProviderEvidence;

interface DonationPaymentProvider
{
    public function providerCode():string;
    public function createHostedDonationCheckout(DonationIntentDraft $intent):HostedCheckoutReference;
    public function resumeHostedDonationCheckout(string $providerSessionReference):HostedCheckoutReference;
    public function queryDonationEvidence(string $providerPaymentReference):ProviderEvidence;
}
