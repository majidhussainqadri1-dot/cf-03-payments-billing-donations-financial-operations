<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\FinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class MemoryFinancialRepository implements FinancialRepository
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $data = [];

    public function insert(string $collection, string $id, array $record): void
    {
        if (isset($this->data[$collection][$id])) { throw new InvariantViolation('Duplicate financial record.'); }
        $record['version'] = (int) ($record['version'] ?? 1);
        $this->data[$collection][$id] = $record;
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->data[$collection][$id] ?? null;
    }

    public function compareAndSwap(string $collection, string $id, int $expectedVersion, callable $mutator): array
    {
        $current = $this->get($collection, $id);
        if ($current === null) { throw new InvariantViolation('Financial record not found.'); }
        if (($current['version'] ?? null) !== $expectedVersion) { throw new InvariantViolation('Stale financial record version.'); }
        $next = $mutator($current);
        $next['version'] = $expectedVersion + 1;
        $this->data[$collection][$id] = $next;
        return $next;
    }

    public function all(string $collection): array
    {
        return array_values($this->data[$collection] ?? []);
    }
}
