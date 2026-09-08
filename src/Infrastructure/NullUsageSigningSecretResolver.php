<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\UsageSigningSecretResolver;
use Sabri\CF03\Support\InvariantViolation;

final class NullUsageSigningSecretResolver implements UsageSigningSecretResolver
{
    public function resolve(string $producerReference, string $keyVersion): string
    {
        throw new InvariantViolation('No approved AI usage signing key resolver is configured.');
    }
}
