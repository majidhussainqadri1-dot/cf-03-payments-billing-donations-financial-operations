<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeInterface;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class MemoryFinancialRepository implements QueryableFinancialRepository
{
    /** @var list<string> */
    private const IMMUTABLE_COLLECTIONS = ['ledger_transactions', 'ledger_entries', 'audit'];

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $data = [];
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $transactionSnapshot = [];
    private int $transactionDepth = 0;
    private bool $rollbackOnly = false;

    public function __construct(private readonly bool $normalizeDates = false) {}

    public function insert(string $collection, string $id, array $record): void
    {
        self::assertCollection($collection);
        self::assertIdentifier($id);
        if (isset($this->data[$collection][$id])) {
            throw new InvariantViolation('Duplicate financial record.');
        }
        $record['version'] = (int)($record['version'] ?? $record['record_version'] ?? 1);
        if ($record['version'] < 1) {
            throw new InvalidArgumentException('Financial record version must be positive.');
        }
        if (array_key_exists('record_version', $record)) {
            $record['record_version'] = $record['version'];
        }
        $record = $this->normalize($record);
        if ($collection === 'audit') {
            foreach ($this->data['audit'] ?? [] as $existing) {
                if (($existing['entry_hash'] ?? null) === ($record['entry_hash'] ?? null)
                    || ($existing['previous_hash'] ?? null) === ($record['previous_hash'] ?? null)
                ) {
                    throw new InvariantViolation('Financial audit chain fork or duplicate hash was rejected.');
                }
            }
        }
        $this->data[$collection][$id] = $record;
    }

    public function get(string $collection, string $id): ?array
    {
        self::assertCollection($collection);
        self::assertIdentifier($id);
        return $this->data[$collection][$id] ?? null;
    }

    public function compareAndSwap(string $collection, string $id, int $expectedVersion, callable $mutator): array
    {
        $this->assertMutable($collection);
        self::assertIdentifier($id);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Expected financial record version must be positive.');
        }
        $current = $this->get($collection, $id);
        if ($current === null) {
            throw new InvariantViolation('Financial record not found.');
        }
        if ((int)($current['version'] ?? 0) !== $expectedVersion) {
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
        $next = $this->normalize($next);
        $this->data[$collection][$id] = $next;
        return $next;
    }

    public function all(string $collection): array
    {
        self::assertCollection($collection);
        return array_values($this->data[$collection] ?? []);
    }

    public function transaction(callable $work): mixed
    {
        $outermost = $this->transactionDepth === 0;
        if ($outermost) {
            $this->transactionSnapshot = $this->data;
            $this->rollbackOnly = false;
        }
        $this->transactionDepth++;
        try {
            $result = $work();
        } catch (Throwable $error) {
            $this->transactionDepth--;
            $this->rollbackOnly = true;
            if ($outermost) {
                $this->data = $this->transactionSnapshot;
                $this->transactionSnapshot = [];
                $this->rollbackOnly = false;
            }
            throw $error;
        }

        $this->transactionDepth--;
        if (!$outermost) {
            return $result;
        }
        if ($this->rollbackOnly) {
            $this->data = $this->transactionSnapshot;
            $this->transactionSnapshot = [];
            $this->rollbackOnly = false;
            throw new InvariantViolation('Financial transaction was marked rollback-only by a nested failure.');
        }
        $this->transactionSnapshot = [];
        return $result;
    }

    public function find(string $collection, array $criteria, int $limit = 100): array
    {
        return $this->page($collection, $criteria, $limit, 0);
    }

    public function page(string $collection, array $criteria, int $limit, int $offset): array
    {
        self::assertCollection($collection);
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Financial page limit must be between 1 and 500.');
        }
        if ($offset < 0 || $offset > 100000000) {
            throw new InvalidArgumentException('Financial page offset is invalid.');
        }
        $criteria = $this->normalize($criteria);
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
        $this->assertMutable($collection);
        if ($criteria === [] || $changes === [] || array_key_exists('id', $criteria) || array_key_exists('id', $changes)) {
            throw new InvalidArgumentException('Financial update requires bounded canonical criteria and changes.');
        }
        $criteria = $this->normalize($criteria);
        $changes = $this->normalize($changes);
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
            $this->data[$collection][$id] = $record;
            $updated++;
        }
        return $updated;
    }

    public function deleteWhere(string $collection, array $criteria): int
    {
        $this->assertMutable($collection);
        if ($criteria === [] || array_key_exists('id', $criteria)) {
            throw new InvalidArgumentException('Financial deletion requires bounded canonical criteria.');
        }
        $criteria = $this->normalize($criteria);
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

    private function assertMutable(string $collection): void
    {
        self::assertCollection($collection);
        if (in_array($collection, self::IMMUTABLE_COLLECTIONS, true)) {
            throw new InvariantViolation('Immutable financial evidence cannot be updated or deleted through the generic repository.');
        }
    }

    private static function assertCollection(string $collection): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $collection) !== 1) {
            throw new InvalidArgumentException('Financial collection identifier is invalid.');
        }
    }

    private static function assertIdentifier(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $id) !== 1) {
            throw new InvalidArgumentException('Financial record identifier is invalid.');
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function normalize(array $record): array
    {
        if (!$this->normalizeDates) {
            return $record;
        }
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if ($value instanceof DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $value[$key] = $normalize($child);
                }
            }
            return $value;
        };
        return $normalize($record);
    }
}
