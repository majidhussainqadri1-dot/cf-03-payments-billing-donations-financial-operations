<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\PaidCapabilityAuthorization;
use Sabri\CF03\Support\InvariantViolation;

final class NullPaidCapabilityAuthorization implements PaidCapabilityAuthorization
{
    public function assertAuthorized(string $capability, string $decisionReference): void
    {
        throw new InvariantViolation(
            'Paid '.$capability.' remains dormant and fail closed under the current Founder donation-only policy.'
        );
    }
}
