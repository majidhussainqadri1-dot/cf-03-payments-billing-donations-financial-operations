<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum DonationServiceState: string
{
    case PREPARING = 'preparing';
    case SANDBOX = 'sandbox';
    case LIVE = 'live';
}
