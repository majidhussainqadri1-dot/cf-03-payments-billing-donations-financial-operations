<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Domain\IdempotencyRecord;

interface IdempotencyStore
{
    public function find(string $scope, string $key): ?IdempotencyRecord;

    /** Returns false when another writer already claimed the same scope/key. */
    public function claim(IdempotencyRecord $record): bool;

    public function save(IdempotencyRecord $record): void;
}
