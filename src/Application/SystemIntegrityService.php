<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeInterface;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Support\InvariantViolation;

final class SystemIntegrityService
{
    private const PAGE_SIZE = 500;
    private const MAX_INTEGRITY_ROWS = 1000000;
    /** @return list<string> */
    private static function criticalCollections(): array
    {
        // Backup truth must track every active canonical table. A hand-maintained
        // subset previously omitted idempotency, provider registry, adjustments,
        // fraud reviews and other state needed for safe restore/replay.
        $collections = array_keys(CompleteSchema::tables(''));
        sort($collections, SORT_STRING);
        return $collections;
    }

    /** @param array<string,mixed> $configurationSnapshot */
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly FinancialAuditService $audit,
        private readonly array $configurationSnapshot = []
    ) {}

    /** @return array<string,array{count:int,hash:string}> */
    public function buildBackupManifest(): array
    {
        $datasets = [];
        foreach (self::criticalCollections() as $collection) {
            $records = $this->paged($collection, []);
            usort($records, static fn (array $left, array $right): int => strcmp(
                self::recordIdentity($left),
                self::recordIdentity($right)
            ));
            $datasets[$collection] = [
                'count' => count($records),
                'hash' => hash('sha256', self::canonicalJson($records)),
            ];
        }
        if ($this->configurationSnapshot !== []) {
            $datasets['runtime_configuration'] = [
                'count' => count($this->configurationSnapshot),
                'hash' => hash('sha256', self::canonicalJson($this->configurationSnapshot)),
            ];
        }
        return (new BackupManifest($datasets))->components();
    }

    /**
     * @param array<string,array{count:int,hash:string}> $expected
     * @param array<string,array{count:int,hash:string}> $restored
     * @param list<string> $providerEventIds
     * @param list<string> $postedProviderEventIds
     * @return array<string,mixed>
     */
    public function verifyRestore(
        array $expected,
        array $restored,
        array $providerEventIds,
        array $postedProviderEventIds
    ): array {
        $expectedManifest = new BackupManifest($expected);
        $restoredManifest = new BackupManifest($restored);
        $expectedManifest->assertMatches($restoredManifest);
        $result = (new RestoreReconciliation())->verify(
            $expectedManifest->components(),
            $restoredManifest->components(),
            $providerEventIds,
            $postedProviderEventIds
        );
        return ['accepted' => true] + $result;
    }

    /** @return array<string,array{debit:int,credit:int,balanced:bool}> */
    public function ledgerBalance(): array
    {
        $transactionIds = [];
        foreach ($this->paged('ledger_transactions', []) as $transaction) {
            $id = (string)($transaction['transaction_id'] ?? '');
            if ($id === '' || isset($transactionIds[$id])) {
                throw new InvariantViolation('Canonical ledger transaction identity is missing or duplicated.');
            }
            $transactionIds[$id] = true;
        }

        $totals = [];
        $transactionsWithEntries = [];
        $sources = [];
        foreach ($this->paged('ledger_entries', []) as $entry) {
            $transactionId = (string)($entry['transaction_id'] ?? '');
            if ($transactionId === '' || !isset($transactionIds[$transactionId])) {
                throw new InvariantViolation('Ledger entry references a missing canonical transaction.');
            }
            $source = (string)($entry['source_ref'] ?? '');
            if ($source === '' || isset($sources[$source])) {
                throw new InvariantViolation('Duplicate or missing immutable ledger source reference detected.');
            }
            $sources[$source] = true;
            $currency = (string)($entry['currency'] ?? '');
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvariantViolation('Ledger entry currency is invalid.');
            }
            $key = $transactionId.'|'.$currency;
            $totals[$key] ??= ['debit' => 0, 'credit' => 0, 'balanced' => false];
            $direction = (string)($entry['direction'] ?? '');
            if (!in_array($direction, ['debit', 'credit'], true)) {
                throw new InvariantViolation('Ledger entry direction is invalid.');
            }
            $amount = $entry['amount_minor'] ?? null;
            if (!is_int($amount) && !(is_string($amount) && preg_match('/^[1-9][0-9]*$/', $amount) === 1)) {
                throw new InvariantViolation('Ledger entry amount is invalid.');
            }
            $amount = (int)$amount;
            if ($amount <= 0 || $amount > PHP_INT_MAX - $totals[$key][$direction]) {
                throw new InvariantViolation('Ledger entry amount is zero, negative or exceeds the supported integer range.');
            }
            $totals[$key][$direction] += $amount;
            $transactionsWithEntries[$transactionId] = true;
        }

        foreach (array_keys($transactionIds) as $transactionId) {
            if (!isset($transactionsWithEntries[$transactionId])) {
                throw new InvariantViolation('Canonical ledger transaction has no immutable entries.');
            }
        }
        foreach ($totals as &$total) {
            $total['balanced'] = $total['debit'] === $total['credit'];
            if (!$total['balanced']) {
                throw new InvariantViolation('Unbalanced financial transaction detected.');
            }
        }
        unset($total);
        return $totals;
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $balances = $this->ledgerBalance();
        $auditValid = $this->audit->verifyChain();
        $deadLetters = $this->countPaged('outbox', ['state' => 'dead_letter']);
        $openExceptions = $this->paged('reconciliation_exceptions', ['state' => 'open']);
        $material = 0;
        foreach ($openExceptions as $exception) {
            if ((bool)$exception['material']) {
                $material++;
            }
        }
        return [
            'ledger_balanced' => true,
            'balanced_transaction_currency_pairs' => count($balances),
            'audit_chain_valid' => $auditValid,
            'dead_letter_count' => $deadLetters,
            'open_reconciliation_exceptions' => count($openExceptions),
            'open_material_exceptions' => $material,
            'backup_manifest' => $this->buildBackupManifest(),
        ];
    }

    public function assertOperationalIntegrity(): void
    {
        $health = $this->health();
        if ($health['dead_letter_count'] > 0 || $health['open_material_exceptions'] > 0) {
            throw new InvariantViolation('Operational integrity gate is blocked by dead letters or material reconciliation exceptions.');
        }
    }

    /** @param array<string,mixed> $criteria @return list<array<string,mixed>> */
    private function paged(string $collection, array $criteria): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $this->repository->page($collection, $criteria, self::PAGE_SIZE, $offset);
            foreach ($page as $record) { $rows[] = $record; }
            $offset += count($page);
            if ($offset > self::MAX_INTEGRITY_ROWS) {
                throw new InvariantViolation('Integrity scan exceeds the safe automatic row bound.');
            }
        } while (count($page) === self::PAGE_SIZE);
        return $rows;
    }

    /** @param array<string,mixed> $criteria */
    private function countPaged(string $collection, array $criteria): int
    {
        $count = 0;
        $offset = 0;
        do {
            $page = $this->repository->page($collection, $criteria, self::PAGE_SIZE, $offset);
            $count += count($page);
            $offset += count($page);
            if ($offset > self::MAX_INTEGRITY_ROWS) {
                throw new InvariantViolation('Integrity count exceeds the safe automatic row bound.');
            }
        } while (count($page) === self::PAGE_SIZE);
        return $count;
    }

    /** @param array<string,mixed> $record */
    private static function recordIdentity(array $record): string
    {
        foreach ([
            'transaction_id','source_ref','intent_id','provider_event_id','invoice_id','refund_id',
            'case_id','donation_id','batch_id','exception_id','period_id','expense_id','snapshot_id',
            'audit_id','event_id','record_ref','migration_id',
        ] as $field) {
            if (isset($record[$field])) {
                return $field.':'.(string)$record[$field];
            }
        }
        return hash('sha256', self::canonicalJson($record));
    }

    private static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if ($item instanceof DateTimeInterface) {
                return $item->format(DATE_ATOM);
            }
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Integrity evidence cannot be canonically encoded.', 0, $error);
        }
    }
}
