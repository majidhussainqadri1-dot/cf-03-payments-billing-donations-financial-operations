<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeInterface;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class SystemIntegrityService
{
    /** @var list<string> */
    private const CRITICAL_COLLECTIONS = [
        'intents','provider_events','ledger_transactions','ledger_entries','invoices','refunds',
        'chargebacks','donations','settlements','settlement_lines','reconciliation_exceptions',
        'finance_periods','expenses','transparency_snapshots','audit','outbox','retention_ledger','migrations',
    ];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,array{count:int,hash:string}> */
    public function buildBackupManifest(): array
    {
        $datasets = [];
        foreach (self::CRITICAL_COLLECTIONS as $collection) {
            $records = $this->repository->all($collection);
            usort($records, static fn (array $left, array $right): int => strcmp(
                self::recordIdentity($left),
                self::recordIdentity($right)
            ));
            $datasets[$collection] = [
                'count' => count($records),
                'hash' => hash('sha256', self::canonicalJson($records)),
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
        $totals = [];
        $sources = [];
        foreach ($this->repository->all('ledger_entries') as $entry) {
            $source = (string)$entry['source_ref'];
            if (isset($sources[$source])) {
                throw new InvariantViolation('Duplicate immutable ledger source reference detected.');
            }
            $sources[$source] = true;
            $key = (string)$entry['transaction_id'].'|'.(string)$entry['currency'];
            $totals[$key] ??= ['debit' => 0, 'credit' => 0, 'balanced' => false];
            $direction = (string)$entry['direction'];
            if (!in_array($direction, ['debit', 'credit'], true)) {
                throw new InvariantViolation('Ledger entry direction is invalid.');
            }
            $totals[$key][$direction] += (int)$entry['amount_minor'];
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
        $deadLetters = count($this->repository->find('outbox', ['state' => 'dead_letter'], 500));
        $openExceptions = $this->repository->find('reconciliation_exceptions', ['state' => 'open'], 500);
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
