<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Manual/provider settlement imports are reconciled only against canonical CF-03
 * records. API callers never get to supply the internal side of reconciliation.
 */
final class CanonicalSettlementImportService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RuntimeConfiguration $configuration,
        private readonly SettlementOperationsService $settlements
    ) {}

    /** @param array<string,int> $materialityByCurrency @return array<string,mixed> */
    public function import(
        SettlementBatch $batch,
        array $materialityByCurrency,
        string $operatorReference,
        DateTimeImmutable $importedAt
    ): array {
        $this->configuration->assertFinancialMutationReady();
        if (!hash_equals($this->configuration->providerCode(), $batch->providerCode())) {
            throw new InvariantViolation('Settlement import provider is not the approved runtime provider.');
        }
        return $this->settlements->importAndReconcile(
            $batch,
            $this->canonicalInternalLines($batch),
            $materialityByCurrency,
            $operatorReference,
            $importedAt
        );
    }

    /** @return list<array{reference:string,type:string,amount_minor:int,currency:string}> */
    public function canonicalInternalLines(SettlementBatch $batch): array
    {
        $internal = [];
        foreach ($batch->lines() as $line) {
            $reference = (string)$line['reference'];
            $type = (string)$line['type'];
            $currency = (string)$line['currency'];
            $record = match ($type) {
                'payment' => $this->first('intents', [
                    'provider_ref' => $reference,
                    'provider' => $batch->providerCode(),
                ]),
                'refund' => $this->refundForProvider($reference, $batch->providerCode()),
                'chargeback' => $this->first('chargebacks', [
                    'provider_case_ref' => $reference,
                    'provider' => $batch->providerCode(),
                ]),
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
            if ($amount < 0 || !hash_equals($recordCurrency, $currency)) {
                throw new InvariantViolation('Canonical reconciliation evidence has an invalid amount or currency.');
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

    /** @return array<string,mixed>|null */
    private function refundForProvider(string $providerReference, string $providerCode): ?array
    {
        $matches = [];
        $offset = 0;
        do {
            $page = $this->repository->page('refunds', ['provider_ref' => $providerReference], 100, $offset);
            foreach ($page as $refund) {
                $intentId = (string)($refund['intent_id'] ?? '');
                if ($intentId === '') {
                    throw new InvariantViolation('Refund reconciliation evidence is missing its canonical payment intent.');
                }
                $intent = $this->repository->get('intents', $intentId);
                if ($intent === null) {
                    throw new InvariantViolation('Refund reconciliation evidence has no canonical payment intent.');
                }
                if (($intent['provider'] ?? null) === $providerCode) {
                    $matches[] = $refund;
                    if (count($matches) > 1) {
                        throw new InvariantViolation('Canonical refund reference is ambiguous within one provider.');
                    }
                }
            }
            $offset += count($page);
        } while (count($page) === 100);
        return $matches[0] ?? null;
    }

    /** @param array<string,mixed> $criteria @return array<string,mixed>|null */
    private function first(string $collection, array $criteria): ?array
    {
        $records = $this->repository->find($collection, $criteria, 2);
        if (count($records) > 1) {
            throw new InvariantViolation('Canonical settlement reconciliation found ambiguous internal evidence.');
        }
        return $records[0] ?? null;
    }
}
