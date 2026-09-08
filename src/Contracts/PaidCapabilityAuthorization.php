<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface PaidCapabilityAuthorization
{
    public function assertAuthorized(string $capability, string $decisionReference): void;
}
