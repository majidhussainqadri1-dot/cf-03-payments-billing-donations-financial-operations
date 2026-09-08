<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum DonationFrequencyPreference: string
{
    case NONE = 'none';
    case ONE_TIME = 'one_time';
    case MONTHLY_ACTIVE = 'monthly_active';
    case MONTHLY_CANCELLED = 'monthly_cancelled';
}
