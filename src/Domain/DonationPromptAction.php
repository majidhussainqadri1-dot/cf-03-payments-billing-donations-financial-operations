<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum DonationPromptAction: string
{
    case SHOWN = 'shown';
    case REMIND_LATER = 'remind_later';
    case NOT_NOW = 'not_now';
    case CLOSE = 'close';
    case DONATION_COMPLETED_ONE_TIME = 'donation_completed_one_time';
    case DONATION_COMPLETED_MONTHLY = 'donation_completed_monthly';
    case MONTHLY_CANCELLED = 'monthly_cancelled';
}
