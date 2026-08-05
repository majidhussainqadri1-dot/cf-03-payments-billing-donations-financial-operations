<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

interface QueryableFinancialRepository extends FinancialRepository
{
    public function transaction(callable $work): mixed;

    /** @param array<string,mixed> $criteria @return list<array<string,mixed>> */
    public function find(string $collection, array $criteria, int $limit = 100): array;

    /** @param array<string,mixed> $criteria @param array<string,mixed> $changes */
    public function updateWhere(string $collection, array $criteria, array $changes): int;

    /** @param array<string,mixed> $criteria */
    public function deleteWhere(string $collection, array $criteria): int;
}
