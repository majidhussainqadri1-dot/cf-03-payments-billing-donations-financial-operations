<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class ActivationStatus
{
    /** @param list<string> $missingGates */
    public function __construct(
        private readonly bool $approved,
        private readonly array $missingGates
    ) {
    }

    public function approved(): bool
    {
        return $this->approved;
    }

    /** @return list<string> */
    public function missingGates(): array
    {
        return $this->missingGates;
    }
}
