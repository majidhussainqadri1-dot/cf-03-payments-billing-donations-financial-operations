<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Support\InvariantViolation;

final class DailyReconciliationService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration,
        private readonly SettlementOperationsService $settlements
    ) {}

    /**
     * @param array<string,int> $materialityByCurrency
     * @return array<string,mixed>
     */
    public function run(
        string $fromDate,
        string $toDate,
        string $operatorReference,
        array $materialityByCurrency
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::date($fromDate);
        self::date($toDate);
        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('Daily reconciliation date range is reversed.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $operatorReference) !== 1) {
            throw new InvalidArgumentException('Daily reconciliation operator reference is invalid.');
        }
        foreach ($materialityByCurrency as $currency => $minor) {
            if (preg_match('/^[A-Z]{3}$/', (string)$currency) !== 1 || !is_int($minor) || $minor < 0) {
                throw new InvalidArgumentException('Daily reconciliation materiality map is invalid.');
            }
        }

        $provider = $this->providers->get($this->configuration->providerCode());
        if ($provider->providerId() !== $this->configuration->providerCode()
            || $provider->health() !== 'healthy'
        ) {
            throw new InvariantViolation('Approved payment provider is unavailable for daily reconciliation.');
        }

        $imported = 0;
        $duplicates = 0;
        $exceptionCount = 0;
        $batches = [];
        foreach ($provider->settlements($fromDate, $toDate) as $row) {
            if (!is_array($row)) {
                throw new InvariantViolation('Provider settlement feed returned a non-record value.');
            }
            $batch = $this->batch($provider->providerId(), $row, $fromDate, $toDate);
            if ($this->repository->get('settlements', $batch->batchId()) !== null) {
                $duplicates++;
                continue;
            }
            $internalLines = $this->internalLines($batch);
            $result = $this->settlements->importAndReconcile(
                $batch,
                $internalLines,
                $materialityByCurrency,
                $operatorReference,
                new DateTimeImmutable('now')
            );
            $imported++;
            $exceptionCount += (int)$result['exception_count'];
            $batches[] = [
                'batch_id' => $batch->batchId(),
                'status' => $result['status'],
                'exception_count' => $result['exception_count'],
            ];
        }

        return [
            'provider' => $provider->providerId(),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'imported_batches' => $imported,
            'duplicate_batches' => $duplicates,
            'exceptions_created' => $exceptionCount,
            'batches' => $batches,
        ];
    }

    /** @param array<string,mixed> $row */
    private function batch(string $providerCode, array $row, string $fromDate, string $toDate): SettlementBatch
    {
        $required = [
            'batch_id','gross_minor','fee_minor','refund_minor','net_minor','currency',
            'settled_at','source_sha256','lines',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvariantViolation('Provider settlement feed is missing '.$field.'.');
            }
        }
        $currency = (string)$row['currency'];
        foreach (['gross_minor','fee_minor','refund_minor','net_minor'] as $field) {
            if (!is_int($row[$field]) || $row[$field] < 0) {
                throw new InvariantViolation('Provider settlement amount is not a non-negative integer.');
            }
        }
        if (!is_array($row['lines'])) {
            throw new InvariantViolation('Provider settlement lines are invalid.');
        }
        $settledAt = new DateTimeImmutable((string)$row['settled_at']);
        $settledDate = $settledAt->format('Y-m-d');
        if ($settledDate < $fromDate || $settledDate > $toDate) {
            throw new InvariantViolation('Provider settlement lies outside the requested reconciliation window.');
        }

        return new SettlementBatch(
            (string)$row['batch_id'],
            $providerCode,
            new Money($row['gross_minor'], $currency),
            new Money($row['fee_minor'], $currency),
            new Money($row['refund_minor'], $currency),
            new Money($row['net_minor'], $currency),
            $settledAt,
            (string)$row['source_sha256'],
            array_values($row['lines'])
        );
    }

    /** @return list<array{reference:string,type:string,amount_minor:int,currency:string}> */
    private function internalLines(SettlementBatch $batch): array
    {
        $internal = [];
        foreach ($batch->lines() as $line) {
            $reference = $line['reference'];
            $type = $line['type'];
            $currency = $line['currency'];
            $record = match ($type) {
                'payment' => $this->first('intents', ['provider_ref' => $reference, 'provider' => $batch->providerCode()]),
                'refund' => $this->first('refunds', ['provider_ref' => $reference]),
                'chargeback' => $this->first('chargebacks', ['provider_case_ref' => $reference]),
                'fee' => $this->first('expenses', ['approval_ref' => 'settlement:'.$batch->batchId()]),
                default => null,
            };
            if ($type === 'payout') {
                $internal[] = [
                    'reference' => $reference,
                    'type' => 'payout',
                    'amount_minor' => $batch->net()->minorUnits(),
                    'currency' => $batch->net()->currency(),
                ];
                continue;
            }
            if ($record === null) {
                continue;
            }
            $amount = (int)($record['amount_minor'] ?? 0);
            $recordCurrency = (string)($record['currency'] ?? '');
            if ($amount < 0 || $recordCurrency !== $currency) {
                throw new InvariantViolation('Internal reconciliation record amount or currency is invalid.');
            }
            $internal[] = [
                'reference' => $reference,
                'type' => $type,
                'amount_minor' => $amount,
                'currency' => $recordCurrency,
            ];
        }
        return $internal;
    }

    /** @param array<string,mixed> $criteria @return array<string,mixed>|null */
    private function first(string $collection, array $criteria): ?array
    {
        $records = $this->repository->find($collection, $criteria, 2);
        if (count($records) > 1) {
            throw new InvariantViolation('Daily reconciliation found ambiguous internal evidence.');
        }
        return $records[0] ?? null;
    }

    private static function date(string $value): void
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])$/', $value) !== 1
            || (new DateTimeImmutable($value))->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Daily reconciliation date is invalid.');
        }
    }
}
