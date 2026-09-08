<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Domain\Money;

interface RecurringDonationProvider extends DonationPaymentProvider
{
    public function cancelRecurringDonation(string $providerReference,string $idempotencyKey): string;
    public function changeRecurringDonationAmount(string $providerReference,Money $amount,string $idempotencyKey): string;
}
