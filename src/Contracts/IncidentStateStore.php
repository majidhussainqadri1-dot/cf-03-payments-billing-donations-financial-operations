<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface IncidentStateStore
{
    /** @return array<string,mixed> */
    public function get(): array;

    /** @param array<string,mixed> $state */
    public function save(array $state): void;
}
