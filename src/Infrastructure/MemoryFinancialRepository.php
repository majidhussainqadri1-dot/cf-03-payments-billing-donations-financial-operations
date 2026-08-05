<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class MemoryFinancialRepository implements QueryableFinancialRepository
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $data = [];

    public function insert(string $collection, string $id, array $record): void
    {
        if (isset($this->data[$collection][$id])) {
            throw new InvariantViolation('Duplicate financial record.');
        }
        $record['version'] = (int)($record['version'] ?? $record['record_version'] ?? 1);
        if (array_key_exists('record_version', $record)) {
            $record['record_version'] = $record['version'];
        }
        $this->data[$collection][$id] = $record;
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->data[$collection][$id] ?? null;
    }

    public function compareAndSwap(string $collection, string $id, int $expectedVersion, callable $mutator): array
    {
        $current = $this->get($collection, $id);
        if ($current === null) {
            throw new InvariantViolation('Financial record not found.');
        }
        if (($current['version'] ?? null) !== $expectedVersion) {
            throw new InvariantViolation('Stale financial record version.');
        }
        $next = $mutator($current);
        if (!is_array($next)) {
            throw new InvariantViolation('Financial record mutator must return an array.');
        }
        $next['version'] = $expectedVersion + 1;
        if (array_key_exists('record_version', $current) || array_key_exists('record_version', $next)) {
            $next['record_version'] = $expectedVersion + 1;
        }
        $this->data[$collection][$id] = $next;
        return $next;
    }

    public function all(string $collection): array
    {
        return array_values($this->data[$collection] ?? []);
    }

    public function transaction(callable $work): mixed
    {
        $snapshot = $this->data;
        try {
            return $work();
        } catch (Throwable $error) {
            $this->data = $snapshot;
            throw $error;
        }
    }

    public function find(string $collection, array $criteria, int $limit = 100): array
    {
        return $this->page($collection, $criteria, $limit, 0);
    }

    public function page(string $collection, array $criteria, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Financial page limit must be between 1 and 500.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Financial page offset cannot be negative.');
        }

        $matches = [];
        foreach ($this->data[$collection] ?? [] as $record) {
            foreach ($criteria as $field => $value) {
                if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                    continue 2;
                }
            }
            $matches[] = $record;
        }
        return array_values(array_slice($matches, $offset, $limit));
    }

    public function updateWhere(string $collection, array $criteria, array $changes): int
    {
        if ($criteria === [] || $changes === []) {
            throw new InvalidArgumentException('Financial update requires criteria and changes.');
        }
        $updated = 0;
        foreach ($this->data[$collection] ?? [] as $id => $record) {
            foreach ($criteria as $field => $value) {
                if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                    continue 2;
                }
            }
            foreach ($changes as $field => $value) {
                $record[$field] = $value;
            }
            if (isset($record['version'])) {
                $record['version'] = (int)$record['version'] + 1;
                if (array_key_exists('record_version', $record)) {
                    $record['record_version'] = $record['version'];
                }
            }
            $this->data[$collection][$id] = $record;
            $updated++;
        }
        return $updated;
    }

    public function deleteWhere(string $collection, array $criteria): int
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('Financial deletion requires bounded criteria.');
        }
        $deleted = 0;
        foreach ($this->data[$collection] ?? [] as $id => $record) {
            foreach ($criteria as $field => $value) {
                if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                    continue 2;
                }
            }
            unset($this->data[$collection][$id]);
            $deleted++;
        }
        return $deleted;
    }
}
