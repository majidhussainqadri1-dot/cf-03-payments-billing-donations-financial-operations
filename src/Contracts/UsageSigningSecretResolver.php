<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface UsageSigningSecretResolver
{
    public function resolve(string $producerReference, string $keyVersion): string;
}
