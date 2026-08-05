<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\FinancialDownloadGrant;
use Sabri\CF03\Domain\SecureExportJob;
use Sabri\CF03\Support\InvariantViolation;

final class SecureExportService
{
    /** @var list<string> */
    private const COLLECTIONS = ['ledger_entries', 'invoices', 'refunds', 'settlements', 'reconciliation_exceptions'];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly SecureArtifactStore $store,
        private readonly RuntimeConfiguration $configuration,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function request(
        string $jobId,
        string $requesterReference,
        array $fields,
        array $filters,
        int $maximumRows,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $requestedAt
    ): array {
        if ($expiresAt <= $requestedAt || $expiresAt > $requestedAt->modify('+7 days')) {
            throw new InvalidArgumentException('Finance export expiry must be after request and within seven days.');
        }
        $job = new SecureExportJob($jobId, $requesterReference, $fields, $filters, $maximumRows, $expiresAt);
        $specification = $job->specification();
        $specificationHash = hash('sha256', self::canonicalJson($specification));
        $record = [
            'job_id' => $jobId,
            'requester_ref' => $requesterReference,
            'specification_hash' => $specificationHash,
            'specification_json' => $specification,
            'maximum_rows' => $maximumRows,
            'state' => 'queued',
            'encrypted_object_ref' => null,
            'manifest_hash' => null,
            'expires_at' => $expiresAt,
            'record_version' => 1,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ];
        $existing = $this->repository->get('exports', $jobId);
        if ($existing !== null) {
            if (($existing['requester_ref'] ?? null) === $requesterReference
                && ($existing['specification_hash'] ?? null) === $specificationHash
            ) {
                return self::safe($existing) + ['reused' => true];
            }
            throw new InvariantViolation('Finance export identifier already exists with different scope.');
        }
        $this->repository->insert('exports', $jobId, $record);
        return self::safe($record) + ['reused' => false];
    }

    /** @return array<string,mixed> */
    public function process(string $jobId, string $operatorReference, int $expectedVersion, DateTimeImmutable $now): array
    {
        $record = $this->repository->get('exports', $jobId);
        if ($record === null || (int)($record['version'] ?? 0) !== $expectedVersion) {
            throw new InvariantViolation('Finance export job is missing or stale.');
        }
        $job = $this->hydrate($record);
        $job->start($expectedVersion);
        $running = $this->repository->compareAndSwap('exports', $jobId, $expectedVersion, static function (array $current) use ($now): array {
            if (($current['state'] ?? null) !== 'queued') {
                throw new InvariantViolation('Only queued finance exports may start.');
            }
            $current['state'] = 'running';
            $current['updated_at'] = $now;
            return $current;
        });

        try {
            $specification = (array)$record['specification_json'];
            $csv = $this->buildCsv($specification);
            $filename = 'cf03-financial-export-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $jobId).'.csv';
            $stored = $this->store->put($filename, 'text/csv', $csv, new DateTimeImmutable((string)$record['expires_at']));
            if (!isset($stored['object_ref'], $stored['sha256'], $stored['size_bytes'])
                || preg_match('/^[a-f0-9]{64}$/', (string)$stored['sha256']) !== 1
                || (int)$stored['size_bytes'] !== strlen($csv)
                || !hash_equals(hash('sha256', $csv), (string)$stored['sha256'])
            ) {
                throw new InvariantViolation('Secure artifact store returned inconsistent financial export evidence.');
            }
            $job->complete((string)$stored['sha256'], (string)$stored['object_ref'], $expectedVersion + 1);
            $ready = $this->repository->compareAndSwap('exports', $jobId, $expectedVersion + 1, static function (array $current) use ($stored, $now): array {
                if (($current['state'] ?? null) !== 'running') {
                    throw new InvariantViolation('Finance export is not running.');
                }
                $current['state'] = 'ready';
                $current['encrypted_object_ref'] = $stored['object_ref'];
                $current['manifest_hash'] = $stored['sha256'];
                $current['updated_at'] = $now;
                return $current;
            });
            return self::safe($ready) + ['size_bytes' => (int)$stored['size_bytes']];
        } catch (\Throwable $error) {
            $this->repository->updateWhere('exports', ['job_id' => $jobId, 'state' => 'running'], ['state' => 'failed', 'updated_at' => $now]);
            throw $error;
        }
    }

    public function grant(
        string $jobId,
        string $requesterReference,
        bool $financeOverride,
        DateTimeImmutable $now,
        DateTimeImmutable $grantExpiresAt
    ): FinancialDownloadGrant {
        $this->configuration->assertDownloadDeliveryReady();
        $record = $this->repository->get('exports', $jobId);
        if ($record === null) {
            throw new InvariantViolation('Finance export job was not found.');
        }
        if (!$financeOverride && (string)$record['requester_ref'] !== $requesterReference) {
            throw new InvariantViolation('Finance export does not belong to the requester.');
        }
        $job = $this->hydrate($record);
        $job->assertDownloadable($now);
        $jobExpiry = new DateTimeImmutable((string)$record['expires_at']);
        $expires = $grantExpiresAt < $jobExpiry ? $grantExpiresAt : $jobExpiry;
        if ($expires <= $now || $expires > $now->modify('+30 minutes')) {
            throw new InvariantViolation('Finance export download grant expiry is invalid.');
        }
        return new FinancialDownloadGrant(
            'grant.export.'.substr(hash('sha256', $jobId.'|'.$requesterReference.'|'.$now->format(DATE_ATOM)), 0, 32),
            'finance_export',
            $jobId,
            $financeOverride ? 'finance:authorized' : $requesterReference,
            'cf03-financial-export-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $jobId).'.csv',
            'text/csv',
            (string)$record['manifest_hash'],
            $now,
            $expires,
            true,
            (string)$record['encrypted_object_ref']
        );
    }

    /** @return array<string,mixed> */
    public function revoke(string $jobId, string $requesterReference, bool $financeOverride, int $expectedVersion, DateTimeImmutable $now): array
    {
        $record = $this->repository->get('exports', $jobId);
        if ($record === null || (int)($record['version'] ?? 0) !== $expectedVersion) {
            throw new InvariantViolation('Finance export job is missing or stale.');
        }
        if (!$financeOverride && (string)$record['requester_ref'] !== $requesterReference) {
            throw new InvariantViolation('Finance export does not belong to the requester.');
        }
        if (is_string($record['encrypted_object_ref'] ?? null) && $record['encrypted_object_ref'] !== '') {
            $this->store->delete((string)$record['encrypted_object_ref']);
        }
        $updated = $this->repository->compareAndSwap('exports', $jobId, $expectedVersion, static function (array $current) use ($now): array {
            $current['state'] = 'revoked';
            $current['encrypted_object_ref'] = null;
            $current['updated_at'] = $now;
            return $current;
        });
        return self::safe($updated);
    }

    /** @param array<string,mixed> $specification */
    private function buildCsv(array $specification): string
    {
        $fields = $specification['fields'] ?? null;
        $filters = $specification['filters'] ?? null;
        $maximumRows = $specification['maximum_rows'] ?? null;
        if (!is_array($fields) || !is_array($filters) || !is_int($maximumRows)) {
            throw new InvariantViolation('Stored finance export specification is corrupt.');
        }
        $rows = [];
        $transactionMap = [];
        foreach ($this->repository->all('ledger_transactions') as $transaction) {
            $transactionMap[(string)$transaction['transaction_id']] = $transaction;
        }
        foreach (self::COLLECTIONS as $collection) {
            $offset = 0;
            do {
                $page = $this->repository->page($collection, [], 500, $offset);
                foreach ($page as $record) {
                    $normalized = $this->normalizeRecord($collection, $record, $transactionMap);
                    if ($normalized !== null && $this->matches($normalized, $filters)) {
                        $rows[] = $normalized;
                        if (count($rows) > $maximumRows) {
                            throw new InvariantViolation('Finance export row limit was exceeded; narrow the filters.');
                        }
                    }
                }
                $offset += count($page);
            } while (count($page) === 500);
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new InvariantViolation('Finance export buffer is unavailable.');
        }
        fputcsv($stream, $fields);
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $value = $row[(string)$field] ?? '';
                $values[] = SecureExportJob::neutralizeCell(is_scalar($value) || $value === null ? (string)$value : self::canonicalJson($value));
            }
            fputcsv($stream, $values);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($contents)) {
            throw new InvariantViolation('Finance export buffer could not be read.');
        }
        return $contents;
    }

    /** @param array<string,array<string,mixed>> $transactionMap @return array<string,mixed>|null */
    private function normalizeRecord(string $collection, array $record, array $transactionMap): ?array
    {
        return match ($collection) {
            'ledger_entries' => $this->ledgerRow($record, $transactionMap),
            'invoices' => [
                'transaction_id' => '', 'source_type' => 'invoice', 'source_ref' => $record['invoice_id'] ?? '',
                'product_id' => '', 'status' => $record['status'] ?? '', 'amount_minor' => $record['amount_minor'] ?? 0,
                'currency' => $record['currency'] ?? '', 'effective_at' => self::dateString($record['issued_at'] ?? null),
                'recorded_at' => self::dateString($record['issued_at'] ?? null), 'invoice_number' => $record['invoice_number'] ?? '',
                'refund_id' => '', 'settlement_batch_id' => '', 'exception_type' => '',
                'period_id' => substr(self::dateString($record['issued_at'] ?? null), 0, 7),
            ],
            'refunds' => [
                'transaction_id' => '', 'source_type' => 'refund', 'source_ref' => $record['intent_id'] ?? '',
                'product_id' => '', 'status' => $record['state'] ?? '', 'amount_minor' => $record['amount_minor'] ?? 0,
                'currency' => $record['currency'] ?? '', 'effective_at' => self::dateString($record['requested_at'] ?? null),
                'recorded_at' => self::dateString($record['updated_at'] ?? null), 'invoice_number' => '',
                'refund_id' => $record['refund_id'] ?? '', 'settlement_batch_id' => '', 'exception_type' => '',
                'period_id' => substr(self::dateString($record['requested_at'] ?? null), 0, 7),
            ],
            'settlements' => [
                'transaction_id' => '', 'source_type' => 'settlement', 'source_ref' => $record['source_hash'] ?? '',
                'product_id' => '', 'status' => $record['status'] ?? '', 'amount_minor' => $record['net_minor'] ?? 0,
                'currency' => $record['currency'] ?? '', 'effective_at' => self::dateString($record['settled_at'] ?? null),
                'recorded_at' => self::dateString($record['imported_at'] ?? null), 'invoice_number' => '',
                'refund_id' => '', 'settlement_batch_id' => $record['batch_id'] ?? '', 'exception_type' => '',
                'period_id' => substr(self::dateString($record['settled_at'] ?? null), 0, 7),
            ],
            'reconciliation_exceptions' => [
                'transaction_id' => '', 'source_type' => 'reconciliation_exception', 'source_ref' => $record['source_ref'] ?? '',
                'product_id' => '', 'status' => $record['state'] ?? '', 'amount_minor' => abs((int)($record['expected_minor'] ?? 0) - (int)($record['actual_minor'] ?? 0)),
                'currency' => $record['currency'] ?? '', 'effective_at' => self::dateString($record['created_at'] ?? null),
                'recorded_at' => self::dateString($record['resolved_at'] ?? $record['created_at'] ?? null), 'invoice_number' => '',
                'refund_id' => '', 'settlement_batch_id' => $record['batch_id'] ?? '', 'exception_type' => $record['exception_type'] ?? '',
                'period_id' => substr(self::dateString($record['created_at'] ?? null), 0, 7),
            ],
            default => null,
        };
    }

    /** @param array<string,array<string,mixed>> $transactionMap @return array<string,mixed>|null */
    private function ledgerRow(array $entry, array $transactionMap): ?array
    {
        $transaction = $transactionMap[(string)($entry['transaction_id'] ?? '')] ?? null;
        if (!is_array($transaction)) {
            return null;
        }
        return [
            'transaction_id' => $entry['transaction_id'] ?? '',
            'source_type' => $transaction['source_type'] ?? '',
            'source_ref' => $entry['source_ref'] ?? '',
            'product_id' => '',
            'status' => 'posted',
            'amount_minor' => $entry['amount_minor'] ?? 0,
            'currency' => $entry['currency'] ?? '',
            'effective_at' => self::dateString($transaction['effective_at'] ?? null),
            'recorded_at' => self::dateString($transaction['recorded_at'] ?? null),
            'invoice_number' => '',
            'refund_id' => '',
            'settlement_batch_id' => '',
            'exception_type' => '',
            'period_id' => $transaction['period_id'] ?? '',
        ];
    }

    /** @param array<string,mixed> $row @param array<string,scalar> $filters */
    private function matches(array $row, array $filters): bool
    {
        foreach ($filters as $filter => $value) {
            $string = (string)$value;
            if ($filter === 'date_from' && (string)$row['effective_at'] < $string) { return false; }
            if ($filter === 'date_to' && substr((string)$row['effective_at'], 0, 10) > $string) { return false; }
            if ($filter === 'currency' && (string)$row['currency'] !== $string) { return false; }
            if ($filter === 'status' && (string)$row['status'] !== $string) { return false; }
            if ($filter === 'period_id' && (string)$row['period_id'] !== $string) { return false; }
            if ($filter === 'product_id' && (string)$row['product_id'] !== $string) { return false; }
        }
        return true;
    }

    /** @param array<string,mixed> $record */
    private function hydrate(array $record): SecureExportJob
    {
        $spec = $record['specification_json'] ?? null;
        if (!is_array($spec)) {
            throw new InvariantViolation('Stored finance export specification is unavailable.');
        }
        return new SecureExportJob(
            (string)$record['job_id'],
            (string)$record['requester_ref'],
            (array)$spec['fields'],
            (array)$spec['filters'],
            (int)$record['maximum_rows'],
            new DateTimeImmutable((string)$record['expires_at']),
            (string)$record['state'],
            (int)($record['version'] ?? $record['record_version'] ?? 1),
            is_string($record['manifest_hash'] ?? null) ? $record['manifest_hash'] : null,
            is_string($record['encrypted_object_ref'] ?? null) ? $record['encrypted_object_ref'] : null
        );
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function safe(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'job_id', 'requester_ref', 'specification_hash', 'maximum_rows', 'state',
            'manifest_hash', 'expires_at', 'record_version', 'version', 'created_at', 'updated_at',
        ]));
    }

    private static function dateString(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format(DATE_ATOM);
        }
        return is_string($value) ? $value : '';
    }

    private static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $sort($child); }
            return $item;
        };
        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Finance export specification cannot be canonically encoded.', 0, $error);
        }
    }
}
