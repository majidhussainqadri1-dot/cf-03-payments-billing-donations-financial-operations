<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressFinancialRepository implements QueryableFinancialRepository
{
    /** @var array<string,array{table:string,id:string}> */
    private const COLLECTIONS = [
        'products'=>['table'=>'sabri_cf03_products','id'=>'product_id'],
        'prices'=>['table'=>'sabri_cf03_price_versions','id'=>'price_version_id'],
        'customer_refs'=>['table'=>'sabri_cf03_payment_customer_refs','id'=>'provider_customer_ref'],
        'intents'=>['table'=>'sabri_cf03_payment_intents','id'=>'intent_id'],
        'provider_events'=>['table'=>'sabri_cf03_provider_events','id'=>'provider_event_id'],
        'ledger_transactions'=>['table'=>'sabri_cf03_ledger_transactions','id'=>'transaction_id'],
        'ledger_entries'=>['table'=>'sabri_cf03_ledger_entries','id'=>'source_ref'],
        'recurring_consents'=>['table'=>'sabri_cf03_recurring_consents','id'=>'consent_id'],
        'subscriptions'=>['table'=>'sabri_cf03_subscriptions','id'=>'subscription_id'],
        'usage_authorizations'=>['table'=>'sabri_cf03_usage_authorizations','id'=>'authorization_id'],
        'usage_facts'=>['table'=>'sabri_cf03_usage_facts','id'=>'usage_id'],
        'invoices'=>['table'=>'sabri_cf03_invoices','id'=>'invoice_id'],
        'refunds'=>['table'=>'sabri_cf03_refunds','id'=>'refund_id'],
        'chargebacks'=>['table'=>'sabri_cf03_chargebacks','id'=>'case_id'],
        'donations'=>['table'=>'sabri_cf03_donations','id'=>'donation_id'],
        'settlements'=>['table'=>'sabri_cf03_settlement_batches','id'=>'batch_id'],
        'settlement_lines'=>['table'=>'sabri_cf03_settlement_lines','id'=>'line_ref'],
        'reconciliation_exceptions'=>['table'=>'sabri_cf03_reconciliation_exceptions','id'=>'exception_id'],
        'finance_periods'=>['table'=>'sabri_cf03_finance_periods','id'=>'period_id'],
        'adjustments'=>['table'=>'sabri_cf03_adjustments','id'=>'adjustment_id'],
        'fraud_reviews'=>['table'=>'sabri_cf03_fraud_reviews','id'=>'review_id'],
        'exports'=>['table'=>'sabri_cf03_export_jobs','id'=>'job_id'],
        'idempotency'=>['table'=>'sabri_cf03_idempotency','id'=>'idempotency_key'],
        'outbox'=>['table'=>'sabri_cf03_outbox','id'=>'event_id'],
        'audit'=>['table'=>'sabri_cf03_audit_events','id'=>'audit_id'],
        'retention_ledger'=>['table'=>'sabri_cf03_retention_ledger','id'=>'record_ref'],
        'provider_registry'=>['table'=>'sabri_cf03_provider_registry','id'=>'provider_id'],
        'migrations'=>['table'=>'sabri_cf03_migrations','id'=>'migration_id'],
        'expenses'=>['table'=>'sabri_cf03_expenses','id'=>'expense_id'],
        'transparency_snapshots'=>['table'=>'sabri_cf03_transparency_snapshots','id'=>'snapshot_id'],
        'donor_acknowledgments'=>['table'=>'sabri_cf03_donor_acknowledgments','id'=>'acknowledgment_id'],
    ];

    /** @var array<string,list<string>> */
    private array $columnCache = [];
    private int $transactionDepth = 0;
    private bool $rollbackOnly = false;

    public function __construct(private readonly object $wpdb, private readonly string $prefix)
    {
        foreach (['prepare','get_row','get_results','get_col','insert','update','delete','query'] as $method) {
            if (!method_exists($wpdb, $method)) {
                throw new RuntimeException('WordPress database adapter lacks '.$method.'.');
            }
        }
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException('WordPress database prefix is invalid.');
        }
    }

    public static function fromWordPress(): self
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix) || !is_string($wpdb->prefix)) {
            throw new RuntimeException('WordPress database connection is unavailable.');
        }
        return new self($wpdb, $wpdb->prefix);
    }

    public function insert(string $collection, string $id, array $record): void
    {
        [$table, $idField] = $this->spec($collection);
        if (isset($record[$idField]) && (string)$record[$idField] !== $id) {
            throw new InvariantViolation('Financial record identifier mismatch.');
        }
        $record[$idField] = $id;
        $data = $this->normalize($table, $record);
        unset($data['id']);
        $result = $this->wpdb->insert($table, $data);
        if ($result !== 1) {
            throw new InvariantViolation('Financial record insert failed or violated uniqueness.');
        }
    }

    public function get(string $collection, string $id): ?array
    {
        [$table, $idField] = $this->spec($collection);
        $sql = $this->wpdb->prepare("SELECT * FROM {$table} WHERE {$idField} = %s LIMIT 2", $id);
        $rows = $this->wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new InvariantViolation('Canonical financial identifier is not unique.');
        }
        return $this->hydrate($rows[0]);
    }

    public function compareAndSwap(string $collection, string $id, int $expectedVersion, callable $mutator): array
    {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Expected financial record version must be positive.');
        }
        [$table, $idField] = $this->spec($collection);
        if (!in_array('record_version', $this->columns($table), true)) {
            throw new InvariantViolation('Collection does not support compare-and-swap updates.');
        }
        $current = $this->get($collection, $id);
        if ($current === null) {
            throw new InvariantViolation('Financial record not found.');
        }
        if ((int)($current['record_version'] ?? 0) !== $expectedVersion) {
            throw new InvariantViolation('Stale financial record version.');
        }
        $next = $mutator($current);
        if (!is_array($next)) {
            throw new InvariantViolation('Financial record mutator must return an array.');
        }
        if (isset($next[$idField]) && (string)$next[$idField] !== $id) {
            throw new InvariantViolation('Financial record identifier is immutable.');
        }
        $next[$idField] = $id;
        $next['record_version'] = $expectedVersion + 1;
        unset($next['version'], $next['id']);
        $data = $this->normalize($table, $next);
        unset($data[$idField]);
        $affected = $this->wpdb->update($table, $data, [
            $idField => $id,
            'record_version' => $expectedVersion,
        ]);
        if ($affected !== 1) {
            throw new InvariantViolation('Financial compare-and-swap update lost a concurrent race.');
        }
        $updated = $this->get($collection, $id);
        if ($updated === null) {
            throw new InvariantViolation('Updated financial record disappeared.');
        }
        return $updated;
    }

    public function all(string $collection): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $this->page($collection, [], 500, $offset);
            $rows = array_merge($rows, $page);
            $offset += count($page);
            if ($offset > 100000) {
                throw new InvariantViolation('Unbounded financial all-record scan was refused; use a filtered export or aggregate query.');
            }
        } while (count($page) === 500);
        return $rows;
    }

    public function transaction(callable $work): mixed
    {
        $outermost = $this->transactionDepth === 0;
        if ($outermost) {
            $this->rollbackOnly = false;
            if ($this->wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException('Financial database transaction could not start.');
            }
        }

        $this->transactionDepth++;
        try {
            $result = $work();
        } catch (Throwable $error) {
            $this->transactionDepth--;
            $this->rollbackOnly = true;
            if ($outermost) {
                $rollbackResult = $this->wpdb->query('ROLLBACK');
                $this->rollbackOnly = false;
                if ($rollbackResult === false) {
                    throw new RuntimeException('Financial transaction failed and rollback could not be confirmed.', 0, $error);
                }
            }
            throw $error;
        }

        $this->transactionDepth--;
        if (!$outermost) {
            return $result;
        }
        if ($this->rollbackOnly) {
            $rollbackResult = $this->wpdb->query('ROLLBACK');
            $this->rollbackOnly = false;
            if ($rollbackResult === false) {
                throw new RuntimeException('Nested financial failure required rollback, but rollback could not be confirmed.');
            }
            throw new InvariantViolation('Financial transaction was marked rollback-only by a nested failure.');
        }
        if ($this->wpdb->query('COMMIT') === false) {
            $this->wpdb->query('ROLLBACK');
            throw new RuntimeException('Financial database transaction could not commit safely.');
        }
        return $result;
    }

    public function find(string $collection, array $criteria, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Financial query limit must be between 1 and 500.');
        }
        [$table] = $this->spec($collection);
        [$where, $values] = $this->where($table, $criteria);
        $sql = "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT ".(int)$limit;
        if ($values !== []) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }
        $rows = $this->wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row): array => $this->hydrate($row), $rows));
    }

    public function page(string $collection, array $criteria, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Financial page limit must be between 1 and 500.');
        }
        if ($offset < 0 || $offset > 100000000) {
            throw new InvalidArgumentException('Financial page offset is invalid.');
        }
        [$table] = $this->spec($collection);
        [$where, $values] = $this->where($table, $criteria);
        $sql = "SELECT * FROM {$table}{$where} ORDER BY id ASC LIMIT ".(int)$limit.' OFFSET '.(int)$offset;
        if ($values !== []) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }
        $rows = $this->wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row): array => $this->hydrate($row), $rows));
    }

    public function updateWhere(string $collection, array $criteria, array $changes): int
    {
        if ($criteria === [] || $changes === [] || array_key_exists('id', $criteria) || array_key_exists('id', $changes)) {
            throw new InvalidArgumentException('Financial update requires bounded canonical criteria and changes.');
        }
        [$table] = $this->spec($collection);
        $data = $this->normalize($table, $changes);
        $where = $this->normalize($table, $criteria);
        unset($data['id'], $where['id']);
        if ($data === [] || $where === []) {
            throw new InvalidArgumentException('Financial update became unbounded after schema normalization.');
        }
        $affected = $this->wpdb->update($table, $data, $where);
        if ($affected === false) {
            throw new InvariantViolation('Financial bounded update failed.');
        }
        return (int)$affected;
    }

    public function deleteWhere(string $collection, array $criteria): int
    {
        if ($criteria === [] || array_key_exists('id', $criteria)) {
            throw new InvalidArgumentException('Financial deletion requires bounded canonical criteria.');
        }
        [$table] = $this->spec($collection);
        $where = $this->normalize($table, $criteria);
        unset($where['id']);
        if ($where === []) {
            throw new InvalidArgumentException('Financial deletion became unbounded after schema normalization.');
        }
        $affected = $this->wpdb->delete($table, $where);
        if ($affected === false) {
            throw new InvariantViolation('Financial bounded deletion failed.');
        }
        return (int)$affected;
    }

    /** @return array{0:string,1:string} */
    private function spec(string $collection): array
    {
        $spec = self::COLLECTIONS[$collection] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException('Unknown canonical financial collection.');
        }
        return [$this->prefix.$spec['table'], $spec['id']];
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $columns = $this->wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || $columns === []) {
            throw new RuntimeException('Canonical financial table is unavailable: '.$table.'.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1) {
                throw new RuntimeException('Canonical financial table exposed an invalid column.');
            }
        }
        return $this->columnCache[$table] = array_values($columns);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function normalize(string $table, array $record): array
    {
        $columns = $this->columns($table);
        if (array_key_exists('version', $record) && !array_key_exists('record_version', $record)) {
            $record['record_version'] = $record['version'];
        }
        unset($record['version']);
        $normalized = [];
        foreach ($record as $column => $value) {
            if (!is_string($column) || !in_array($column, $columns, true)) {
                throw new InvalidArgumentException('Financial record contains a non-schema field: '.(string)$column.'.');
            }
            $normalized[$column] = $this->normalizeValue($column, $value);
        }
        return $normalized;
    }

    private function normalizeValue(string $column, mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_array($value) && str_ends_with($column, '_json')) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $error) {
                throw new InvalidArgumentException('Financial JSON field is not serializable.', 0, $error);
            }
        }
        throw new InvalidArgumentException('Financial records reject floats, resources and unsupported objects.');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($column) && str_ends_with($column, '_json') && is_string($value) && $value !== '') {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $row[$column] = $decoded;
                    }
                } catch (JsonException) {
                    throw new InvariantViolation('Stored financial JSON is corrupt.');
                }
            }
        }
        if (isset($row['record_version'])) {
            $row['record_version'] = (int)$row['record_version'];
            $row['version'] = $row['record_version'];
        }
        return $row;
    }

    /** @param array<string,mixed> $criteria @return array{0:string,1:list<mixed>} */
    private function where(string $table, array $criteria): array
    {
        if ($criteria === []) {
            return ['', []];
        }
        $columns = $this->columns($table);
        $clauses = [];
        $values = [];
        foreach ($criteria as $column => $value) {
            if (!is_string($column) || !in_array($column, $columns, true)) {
                throw new InvalidArgumentException('Unknown financial query field.');
            }
            if ($value === null) {
                $clauses[] = $column.' IS NULL';
                continue;
            }
            $clauses[] = $column.' = %s';
            $values[] = $this->normalizeValue($column, $value);
        }
        return [' WHERE '.implode(' AND ', $clauses), $values];
    }
}
