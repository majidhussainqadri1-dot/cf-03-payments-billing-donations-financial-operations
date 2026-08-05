<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\DonationExpenseCategory;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Support\InvariantViolation;

final class SettlementOperationsService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RuntimeConfiguration $configuration,
        private readonly FinancialAuditService $audit,
        private readonly ReconciliationEngine $reconciliation = new ReconciliationEngine()
    ) {}

    /**
     * @param list<array{reference:string,type:string,amount_minor:int,currency:string}> $internalLines
     * @param array<string,int> $materialityByCurrency
     * @return array<string,mixed>
     */
    public function importAndReconcile(
        SettlementBatch $batch,
        array $internalLines,
        array $materialityByCurrency,
        string $operatorReference,
        DateTimeImmutable $importedAt
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::assertReference($operatorReference, 'Settlement operator reference');
        if ($this->repository->get('settlements', $batch->batchId()) !== null) {
            throw new InvariantViolation('Settlement batch has already been imported.');
        }

        $result = $this->reconciliation->reconcile($batch, $internalLines, $materialityByCurrency);
        $status = $result->count() === 0 ? 'reconciled' : 'exception_review';
        $this->repository->transaction(function () use ($batch, $result, $status, $operatorReference, $importedAt): void {
            $this->repository->insert('settlements', $batch->batchId(), [
                'batch_id' => $batch->batchId(),
                'provider' => $batch->providerCode(),
                'gross_minor' => $batch->grossAmount()->minorUnits(),
                'fee_minor' => $batch->providerFee()->minorUnits(),
                'refund_minor' => $batch->refundAmount()->minorUnits(),
                'net_minor' => $batch->netAmount()->minorUnits(),
                'currency' => $batch->currency(),
                'source_hash' => $batch->sourceHash(),
                'settled_at' => $batch->settledAt(),
                'imported_at' => $importedAt,
                'status' => $status,
            ]);
            foreach ($batch->lines() as $line) {
                $this->repository->insert('settlement_lines', $line['reference'], [
                    'batch_id' => $batch->batchId(),
                    'line_ref' => $line['reference'],
                    'line_type' => $line['type'],
                    'amount_minor' => $line['amount_minor'],
                    'currency' => $line['currency'],
                ]);
            }
            foreach ($result->exceptions() as $exception) {
                $exceptionId = 'recon.'.substr(hash('sha256', implode('|', [
                    $batch->batchId(), $exception['type'], $exception['reference'], $exception['currency'],
                ])), 0, 40);
                $this->repository->insert('reconciliation_exceptions', $exceptionId, [
                    'exception_id' => $exceptionId,
                    'batch_id' => $batch->batchId(),
                    'exception_type' => $exception['type'],
                    'source_ref' => $exception['reference'],
                    'expected_minor' => $exception['expected'],
                    'actual_minor' => $exception['actual'],
                    'currency' => $exception['currency'],
                    'material' => $exception['material'],
                    'state' => 'open',
                    'owner_ref' => null,
                    'accepted_risk_ref' => null,
                    'resolution_ref' => null,
                    'created_at' => $importedAt,
                    'resolved_at' => null,
                ]);
            }
            if ($result->count() === 0) {
                $this->postProviderSettlement($batch, $operatorReference, $importedAt);
            }
            $this->audit->append(new AuditEnvelope(
                'audit:settlement:'.substr(hash('sha256', $batch->batchId().'|'.$importedAt->format(DATE_ATOM)), 0, 32),
                $operatorReference,
                'settlement_imported',
                'settlement_batch',
                $batch->batchId(),
                'provider_reconciliation',
                AuditOutcome::SUCCEEDED,
                $importedAt,
                'trace:settlement:'.substr(hash('sha256', $batch->batchId()), 0, 24),
                ['status' => $status, 'exception_count' => $result->count(), 'source_hash' => $batch->sourceHash()]
            ));
        });

        return [
            'batch_id' => $batch->batchId(),
            'status' => $status,
            'exception_count' => $result->count(),
            'may_close' => $result->mayClose(),
            'currency' => $batch->currency(),
            'gross_minor' => $batch->grossAmount()->minorUnits(),
            'refund_minor' => $batch->refundAmount()->minorUnits(),
            'fee_minor' => $batch->providerFee()->minorUnits(),
            'net_minor' => $batch->netAmount()->minorUnits(),
        ];
    }

    /** @return array<string,mixed> */
    public function resolveException(
        string $exceptionId,
        string $ownerReference,
        string $resolutionReference,
        bool $acceptedRisk,
        DateTimeImmutable $resolvedAt
    ): array {
        self::assertReference($ownerReference, 'Reconciliation owner reference');
        self::assertReference($resolutionReference, 'Reconciliation resolution reference');
        $exception = $this->repository->get('reconciliation_exceptions', $exceptionId);
        if ($exception === null || ($exception['state'] ?? null) !== 'open') {
            throw new InvariantViolation('Reconciliation exception is missing or no longer open.');
        }
        if ((bool)$exception['material'] && $acceptedRisk) {
            throw new InvariantViolation('Material reconciliation exceptions cannot be closed through accepted risk.');
        }
        $updated = $this->repository->updateWhere('reconciliation_exceptions', [
            'exception_id' => $exceptionId,
            'state' => 'open',
        ], [
            'state' => 'resolved',
            'owner_ref' => $ownerReference,
            'accepted_risk_ref' => $acceptedRisk ? $resolutionReference : null,
            'resolution_ref' => $resolutionReference,
            'resolved_at' => $resolvedAt,
        ]);
        if ($updated !== 1) {
            throw new InvariantViolation('Reconciliation exception resolution lost a concurrent race.');
        }

        $remaining = $this->repository->find('reconciliation_exceptions', [
            'batch_id' => (string)$exception['batch_id'],
            'state' => 'open',
        ], 500);
        if ($remaining === []) {
            $this->repository->updateWhere('settlements', [
                'batch_id' => (string)$exception['batch_id'],
            ], ['status' => 'reconciled_pending_posting']);
        }
        return [
            'exception_id' => $exceptionId,
            'state' => 'resolved',
            'accepted_risk' => $acceptedRisk,
            'resolution_reference' => $resolutionReference,
            'remaining_open' => count($remaining),
        ];
    }

    /** @return array<string,mixed> */
    public function postResolvedBatch(string $batchId, string $operatorReference, DateTimeImmutable $postedAt): array
    {
        $this->configuration->assertFinancialMutationReady();
        self::assertReference($operatorReference, 'Settlement posting operator');
        $record = $this->repository->get('settlements', $batchId);
        if ($record === null || !in_array((string)$record['status'], ['reconciled', 'reconciled_pending_posting'], true)) {
            throw new InvariantViolation('Settlement batch is not ready for posting.');
        }
        if ($this->repository->find('reconciliation_exceptions', ['batch_id' => $batchId, 'state' => 'open'], 1) !== []) {
            throw new InvariantViolation('Open reconciliation exceptions block settlement posting.');
        }
        $batch = $this->hydrateBatch($record);
        $this->repository->transaction(function () use ($batch, $operatorReference, $postedAt): void {
            $this->postProviderSettlement($batch, $operatorReference, $postedAt);
            $this->repository->updateWhere('settlements', ['batch_id' => $batch->batchId()], ['status' => 'posted']);
        });
        return ['batch_id' => $batchId, 'status' => 'posted', 'posted_at' => $postedAt->format(DATE_ATOM)];
    }

    /** @return array<string,mixed> */
    public function closePeriod(
        string $periodId,
        string $reviewerReference,
        string $approverReference,
        DateTimeImmutable $closedAt
    ): array {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodId) !== 1) {
            throw new InvalidArgumentException('Finance period identifier is invalid.');
        }
        self::assertReference($reviewerReference, 'Finance reviewer');
        self::assertReference($approverReference, 'Finance approver');
        if ($reviewerReference === $approverReference) {
            throw new InvariantViolation('Finance close requires separate reviewer and approver identities.');
        }

        $batches = $this->batchesForPeriod($periodId);
        if ($batches === []) {
            throw new InvariantViolation('Finance period cannot close without imported settlement evidence.');
        }
        foreach ($batches as $batch) {
            if (($batch['status'] ?? null) !== 'posted') {
                throw new InvariantViolation('Every settlement batch must be posted before finance period close.');
            }
            foreach ($this->repository->find('reconciliation_exceptions', ['batch_id' => $batch['batch_id'], 'state' => 'open'], 500) as $exception) {
                if ((bool)$exception['material']) {
                    throw new InvariantViolation('Material reconciliation exception blocks finance period close.');
                }
                throw new InvariantViolation('Open reconciliation exception blocks finance period close.');
            }
        }

        $existing = $this->repository->get('finance_periods', $periodId);
        if ($existing !== null && ($existing['state'] ?? null) === 'locked') {
            return $existing + ['reused' => true];
        }
        $record = [
            'period_id' => $periodId,
            'state' => 'locked',
            'reviewed_by' => $reviewerReference,
            'approved_by' => $approverReference,
            'accepted_risk_ref' => null,
            'closed_at' => $closedAt,
            'record_version' => 1,
        ];
        if ($existing === null) {
            $this->repository->insert('finance_periods', $periodId, $record);
        } else {
            $this->repository->compareAndSwap('finance_periods', $periodId, (int)$existing['version'], static fn (array $current): array => array_replace($current, $record));
        }
        return $record + ['reused' => false];
    }

    /** @return array<string,mixed> */
    public function reopenPeriod(
        string $periodId,
        string $requesterReference,
        string $approverReference,
        string $reasonReference
    ): array {
        self::assertReference($requesterReference, 'Finance reopen requester');
        self::assertReference($approverReference, 'Finance reopen approver');
        self::assertReference($reasonReference, 'Finance reopen reason reference');
        if ($requesterReference === $approverReference) {
            throw new InvariantViolation('Finance period reopen requires dual control.');
        }
        $period = $this->repository->get('finance_periods', $periodId);
        if ($period === null || ($period['state'] ?? null) !== 'locked') {
            throw new InvariantViolation('Only a locked finance period may be reopened.');
        }
        $updated = $this->repository->compareAndSwap('finance_periods', $periodId, (int)$period['version'], static function (array $current) use ($reasonReference): array {
            $current['state'] = 'exception_review';
            $current['accepted_risk_ref'] = $reasonReference;
            $current['closed_at'] = null;
            return $current;
        });
        return ['period_id' => $periodId, 'state' => $updated['state'], 'version' => $updated['version']];
    }

    private function postProviderSettlement(SettlementBatch $batch, string $operatorReference, DateTimeImmutable $postedAt): void
    {
        $transactionId = 'txn.settlement.'.substr(hash('sha256', $batch->providerCode().'|'.$batch->batchId()), 0, 32);
        if ($this->repository->get('ledger_transactions', $transactionId) !== null) {
            return;
        }
        $this->repository->insert('ledger_transactions', $transactionId, [
            'transaction_id' => $transactionId,
            'source_type' => 'provider_settlement_batch',
            'source_ref' => $batch->providerCode().':'.$batch->batchId(),
            'effective_at' => $batch->settledAt(),
            'recorded_at' => $postedAt,
            'actor_ref' => $operatorReference,
            'reason' => 'provider_settlement_posting',
            'period_id' => $batch->settledAt()->format('Y-m'),
            'reversal_of' => null,
            'trace_id' => 'trace:settlement:'.substr(hash('sha256', $batch->batchId()), 0, 24),
        ]);
        if ($batch->netAmount()->minorUnits() > 0) {
            $this->entry($transactionId, $batch->batchId().':bank', 'asset.bank_receivable', 'debit', $batch->netAmount());
            $this->entry($transactionId, $batch->batchId().':clearing-net', 'asset.provider_clearing', 'credit', $batch->netAmount());
        }
        if ($batch->providerFee()->minorUnits() > 0) {
            $this->entry($transactionId, $batch->batchId().':fee', 'expense.payment_processing', 'debit', $batch->providerFee());
            $this->entry($transactionId, $batch->batchId().':clearing-fee', 'asset.provider_clearing', 'credit', $batch->providerFee());
            $expense = new DonationExpense(
                'expense.fee.'.substr(hash('sha256', $batch->batchId()), 0, 32),
                $batch->settledAt(),
                $batch->providerFee(),
                DonationExpenseCategory::ADMINISTRATION_PAYMENT_CHARGES,
                'Payment provider settlement fee',
                'provider:'.$batch->providerCode(),
                'settlement:'.$batch->batchId(),
                'verified',
                false,
                'administration_payment_charges'
            );
            if ($this->repository->get('expenses', $expense->expenseId()) === null) {
                $this->repository->insert('expenses', $expense->expenseId(), [
                    'expense_id' => $expense->expenseId(),
                    'occurred_at' => $expense->occurredAt(),
                    'amount_minor' => $expense->amount()->minorUnits(),
                    'currency' => $expense->amount()->currency(),
                    'category' => $expense->category(),
                    'purpose' => $expense->purpose(),
                    'payee_ref' => $expense->payeeReference(),
                    'approval_ref' => $expense->approvalReference(),
                    'receipt_status' => $expense->receiptStatus(),
                    'founder_related' => $expense->founderRelated(),
                    'public_disclosure_category' => $expense->publicDisclosureCategory(),
                    'source_transaction_id' => $transactionId,
                    'record_version' => 1,
                    'created_at' => $postedAt,
                    'updated_at' => $postedAt,
                ]);
            }
        }
    }

    private function entry(string $transactionId, string $sourceRef, string $account, string $direction, Money $amount): void
    {
        $this->repository->insert('ledger_entries', $sourceRef, [
            'transaction_id' => $transactionId,
            'account' => $account,
            'direction' => $direction,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $sourceRef,
        ]);
    }

    /** @param array<string,mixed> $record */
    private function hydrateBatch(array $record): SettlementBatch
    {
        $lines = [];
        foreach ($this->repository->find('settlement_lines', ['batch_id' => $record['batch_id']], 500) as $line) {
            $lines[] = [
                'reference' => (string)$line['line_ref'],
                'type' => (string)$line['line_type'],
                'amount_minor' => (int)$line['amount_minor'],
                'currency' => (string)$line['currency'],
            ];
        }
        return new SettlementBatch(
            (string)$record['batch_id'],
            (string)$record['provider'],
            new DateTimeImmutable((string)$record['settled_at']),
            (string)$record['currency'],
            (int)$record['gross_minor'],
            (int)$record['refund_minor'],
            (int)$record['fee_minor'],
            (int)$record['net_minor'],
            (string)$record['source_hash'],
            $lines
        );
    }

    /** @return list<array<string,mixed>> */
    private function batchesForPeriod(string $periodId): array
    {
        $result = [];
        foreach ($this->repository->all('settlements') as $batch) {
            $date = $batch['settled_at'] instanceof DateTimeImmutable
                ? $batch['settled_at']
                : new DateTimeImmutable((string)$batch['settled_at']);
            if ($date->format('Y-m') === $periodId) {
                $result[] = $batch;
            }
        }
        return $result;
    }

    private static function assertReference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
