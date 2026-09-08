<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum DonationPromptStatus: string
{
    case NEVER_SEEN = 'never_seen';
    case SHOWN = 'shown';
    case SNOOZED = 'snoozed';
    case NOT_NOW = 'not_now';
    case CLOSED = 'closed';
    case DONATION_COMPLETED = 'donation_completed';
    case MONTHLY_ACTIVE = 'monthly_active';
}
