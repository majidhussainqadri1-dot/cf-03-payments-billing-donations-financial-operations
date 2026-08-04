<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum DonationFinancialFactType: string
{
    case ONE_TIME_COMPLETED = 'donation.settled';
    case MONTHLY_STARTED = 'donation.monthly_started';
    case MONTHLY_CANCELLED = 'donation.monthly_cancelled';
    case REFUNDED = 'donation.refunded';
    case CHARGEDBACK = 'donation.chargedback';
}
