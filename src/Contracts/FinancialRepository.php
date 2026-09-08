<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface FinancialRepository
{
    /** @param array<string,mixed> $record */
    public function insert(string $collection, string $id, array $record): void;
    /** @return array<string,mixed>|null */
    public function get(string $collection, string $id): ?array;
    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    public function compareAndSwap(string $collection, string $id, int $expectedVersion, callable $mutator): array;
    /** @return list<array<string,mixed>> */
    public function all(string $collection): array;
}
