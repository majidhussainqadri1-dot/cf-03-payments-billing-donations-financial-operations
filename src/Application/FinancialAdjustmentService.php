<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\FinancialAdjustment;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialAdjustmentService
{
    /** @var list<string> */
    private const ACCOUNTS = [
        'asset.provider_clearing',
        'asset.bank_receivable',
        'liability.refund_payable',
        'income.donation',
        'contra_income.donation_refund',
        'expense.payment_processing',
        'expense.financial_adjustment',
        'equity.founder_adjustment',
    ];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RuntimeConfiguration $configuration,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function request(
        string $adjustmentId,
        string $sourceTransactionId,
        Money $amount,
        string $debitAccount,
        string $creditAccount,
        string $reasonCode,
        string $evidenceSha256,
        string $requesterReference,
        DateTimeImmutable $requestedAt
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::assertAccounts($debitAccount, $creditAccount);
        if ($this->repository->get('ledger_transactions', $sourceTransactionId) === null) {
            throw new InvariantViolation('Financial adjustment source transaction was not found.');
        }
        $adjustment = new FinancialAdjustment(
            $adjustmentId,
            $sourceTransactionId,
            $amount,
            $reasonCode,
            $evidenceSha256,
            $requesterReference,
            $requestedAt
        );
        $existing = $this->repository->get('adjustments', $adjustmentId);
        if ($existing !== null) {
            if (($existing['source_transaction_id'] ?? null) === $sourceTransactionId
                && (int)($existing['amount_minor'] ?? -1) === $amount->minorUnits()
                && ($existing['currency'] ?? null) === $amount->currency()
                && ($existing['evidence_hash'] ?? null) === $evidenceSha256
            ) {
                return self::safe($existing) + ['reused' => true];
            }
            throw new InvariantViolation('Financial adjustment identifier already exists with different evidence.');
        }

        $record = [
            'adjustment_id' => $adjustment->adjustmentId(),
            'source_transaction_id' => $adjustment->sourceTransactionId(),
            'amount_minor' => $adjustment->amount()->minorUnits(),
            'currency' => $adjustment->amount()->currency(),
            'debit_account' => $debitAccount,
            'credit_account' => $creditAccount,
            'reason_code' => $adjustment->reasonCode(),
            'evidence_hash' => $adjustment->evidenceSha256(),
            'requester_ref' => $adjustment->requesterReference(),
            'approver_ref' => null,
            'executor_ref' => null,
            'state' => $adjustment->state(),
            'record_version' => 1,
            'requested_at' => $requestedAt,
            'executed_at' => null,
        ];
        $this->repository->insert('adjustments', $adjustmentId, $record);
        return self::safe($record) + ['version' => 1, 'reused' => false];
    }

    /** @return array<string,mixed> */
    public function decide(
        string $adjustmentId,
        bool $approve,
        string $reviewerReference,
        int $expectedVersion,
        DateTimeImmutable $decidedAt
    ): array {
        $record = $this->require($adjustmentId, $expectedVersion);
        $adjustment = $this->hydrate($record);
        if ($approve) {
            $adjustment->approve($reviewerReference, $expectedVersion);
        } else {
            $adjustment->reject($reviewerReference, $expectedVersion);
        }
        $updated = $this->repository->compareAndSwap(
            'adjustments',
            $adjustmentId,
            $expectedVersion,
            static function (array $current) use ($adjustment): array {
                $current['state'] = $adjustment->state();
                $current['approver_ref'] = $adjustment->approverReference();
                return $current;
            }
        );
        $this->audit->append(new AuditEnvelope(
            'audit:adjustment-decision:'.substr(hash('sha256', $adjustmentId.'|'.$decidedAt->format(DATE_ATOM)), 0, 32),
            $reviewerReference,
            $approve ? 'adjustment_approved' : 'adjustment_rejected',
            'financial_adjustment',
            $adjustmentId,
            'financial_correction_control',
            AuditOutcome::SUCCEEDED,
            $decidedAt,
            'trace:adjustment:'.substr(hash('sha256', $adjustmentId), 0, 24),
            ['evidence_sha256' => $adjustment->evidenceSha256(), 'reason_code' => $adjustment->reasonCode()]
        ));
        return self::safe($updated);
    }

    /** @return array<string,mixed> */
    public function execute(
        string $adjustmentId,
        string $executorReference,
        int $expectedVersion,
        DateTimeImmutable $executedAt
    ): array {
        $this->configuration->assertFinancialMutationReady();
        $record = $this->require($adjustmentId, $expectedVersion);
        if (($record['state'] ?? null) === 'executed') {
            return self::safe($record) + ['reused' => true];
        }
        $adjustment = $this->hydrate($record);
        $adjustment->execute($executorReference, $expectedVersion);

        $source = $this->repository->get('ledger_transactions', $adjustment->sourceTransactionId());
        if ($source === null) {
            throw new InvariantViolation('Financial adjustment source transaction disappeared.');
        }
        $sourcePeriod = (string)($source['period_id'] ?? '');
        $targetPeriod = $sourcePeriod;
        $sourcePeriodRecord = $this->repository->get('finance_periods', $sourcePeriod);
        if (($sourcePeriodRecord['state'] ?? null) === 'locked') {
            $targetPeriod = $executedAt->format('Y-m');
        }
        $targetPeriodRecord = $this->repository->get('finance_periods', $targetPeriod);
        if (($targetPeriodRecord['state'] ?? null) === 'locked') {
            throw new InvariantViolation('Financial adjustment cannot post into a locked period.');
        }

        $transactionId = 'txn.adjustment.'.substr(hash('sha256', $adjustmentId), 0, 32);
        $traceId = 'trace:adjustment:'.substr(hash('sha256', $adjustmentId), 0, 24);
        $this->repository->transaction(function () use (
            $record,
            $adjustment,
            $executorReference,
            $expectedVersion,
            $executedAt,
            $transactionId,
            $targetPeriod,
            $traceId
        ): void {
            if ($this->repository->get('ledger_transactions', $transactionId) !== null) {
                throw new InvariantViolation('Financial adjustment ledger transaction already exists before execution state.');
            }
            $this->repository->insert('ledger_transactions', $transactionId, [
                'transaction_id' => $transactionId,
                'source_type' => 'financial_adjustment',
                'source_ref' => $adjustment->adjustmentId(),
                'effective_at' => $executedAt,
                'recorded_at' => $executedAt,
                'actor_ref' => $executorReference,
                'reason' => $adjustment->reasonCode(),
                'period_id' => $targetPeriod,
                'reversal_of' => $adjustment->sourceTransactionId(),
                'trace_id' => $traceId,
            ]);
            $this->entry(
                $transactionId,
                $adjustmentId.':debit',
                (string)$record['debit_account'],
                'debit',
                $adjustment->amount()
            );
            $this->entry(
                $transactionId,
                $adjustmentId.':credit',
                (string)$record['credit_account'],
                'credit',
                $adjustment->amount()
            );
            $this->repository->compareAndSwap(
                'adjustments',
                $adjustmentId,
                $expectedVersion,
                static function (array $current) use ($adjustment, $executorReference, $executedAt): array {
                    $current['state'] = $adjustment->state();
                    $current['executor_ref'] = $executorReference;
                    $current['executed_at'] = $executedAt;
                    return $current;
                }
            );
            $this->outbox($adjustment, $transactionId, $traceId, $executedAt);
        });

        $this->audit->append(new AuditEnvelope(
            'audit:adjustment-execution:'.substr(hash('sha256', $adjustmentId.'|'.$executedAt->format(DATE_ATOM)), 0, 32),
            $executorReference,
            'adjustment_executed',
            'financial_adjustment',
            $adjustmentId,
            'immutable_ledger_correction',
            AuditOutcome::SUCCEEDED,
            $executedAt,
            $traceId,
            [
                'source_transaction_id' => $adjustment->sourceTransactionId(),
                'transaction_id' => $transactionId,
                'evidence_sha256' => $adjustment->evidenceSha256(),
            ]
        ));

        $updated = $this->repository->get('adjustments', $adjustmentId);
        if ($updated === null) {
            throw new InvariantViolation('Executed financial adjustment disappeared.');
        }
        return self::safe($updated) + [
            'transaction_id' => $transactionId,
            'period_id' => $targetPeriod,
            'reused' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function require(string $adjustmentId, int $expectedVersion): array
    {
        $record = $this->repository->get('adjustments', $adjustmentId);
        if ($record === null || (int)($record['version'] ?? 0) !== $expectedVersion) {
            throw new InvariantViolation('Financial adjustment is missing or stale.');
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function hydrate(array $record): FinancialAdjustment
    {
        return new FinancialAdjustment(
            (string)$record['adjustment_id'],
            (string)$record['source_transaction_id'],
            new Money((int)$record['amount_minor'], (string)$record['currency']),
            (string)$record['reason_code'],
            (string)$record['evidence_hash'],
            (string)$record['requester_ref'],
            self::date($record['requested_at']),
            (string)$record['state'],
            is_string($record['approver_ref'] ?? null) ? $record['approver_ref'] : null,
            is_string($record['executor_ref'] ?? null) ? $record['executor_ref'] : null,
            (int)$record['version']
        );
    }

    private function entry(
        string $transactionId,
        string $sourceReference,
        string $account,
        string $direction,
        Money $amount
    ): void {
        $this->repository->insert('ledger_entries', $sourceReference, [
            'transaction_id' => $transactionId,
            'account' => $account,
            'direction' => $direction,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $sourceReference,
        ]);
    }

    private function outbox(
        FinancialAdjustment $adjustment,
        string $transactionId,
        string $traceId,
        DateTimeImmutable $at
    ): void {
        $payload = [
            'adjustment_id' => $adjustment->adjustmentId(),
            'source_transaction_id' => $adjustment->sourceTransactionId(),
            'transaction_id' => $transactionId,
            'amount_minor' => $adjustment->amount()->minorUnits(),
            'currency' => $adjustment->amount()->currency(),
            'reason_code' => $adjustment->reasonCode(),
            'occurred_at' => $at->format(DATE_ATOM),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new InvariantViolation('Financial adjustment event could not be encoded.');
        }
        $eventId = 'event.adjustment.'.substr(hash('sha256', $encoded), 0, 32);
        $this->repository->insert('outbox', $eventId, [
            'event_id' => $eventId,
            'event_type' => 'FinancialAdjustmentExecuted',
            'aggregate_id' => $adjustment->adjustmentId(),
            'aggregate_version' => (string)$adjustment->recordVersion(),
            'schema_version' => '1.0',
            'trace_id' => $traceId,
            'payload_json' => $payload,
            'payload_hash' => hash('sha256', $encoded),
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => $at,
            'leased_until' => null,
            'last_error_code' => null,
            'created_at' => $at,
            'delivered_at' => null,
        ]);
    }

    private static function assertAccounts(string $debit, string $credit): void
    {
        if ($debit === $credit
            || !in_array($debit, self::ACCOUNTS, true)
            || !in_array($credit, self::ACCOUNTS, true)
        ) {
            throw new InvalidArgumentException('Financial adjustment accounts are invalid or identical.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('Financial adjustment timestamp is missing.');
        }
        return new DateTimeImmutable($value);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function safe(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'adjustment_id',
            'source_transaction_id',
            'amount_minor',
            'currency',
            'debit_account',
            'credit_account',
            'reason_code',
            'requester_ref',
            'approver_ref',
            'executor_ref',
            'state',
            'record_version',
            'version',
            'requested_at',
            'executed_at',
        ]));
    }
}
