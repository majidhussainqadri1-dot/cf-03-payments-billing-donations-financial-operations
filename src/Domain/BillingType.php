<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum BillingType: string
{
    case ONE_TIME = 'one_time';
    case RECURRING = 'recurring';
    case METERED = 'metered';
    case VOLUNTARY = 'voluntary';
}
