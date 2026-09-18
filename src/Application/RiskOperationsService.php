<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationExpenseCategory;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class RiskOperationsService
{
    private const PAGE_SIZE = 200;
    private const MAX_CHARGEBACKS_PER_INTENT = 10000;

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function openFraudReview(FraudReviewCase $case, DateTimeImmutable $createdAt): array
    {
        $record = [
            'review_id' => $case->reviewId(),
            'subject_ref' => $case->subjectReference(),
            'risk_score' => $case->riskScore(),
            'signals_json' => $case->signals(),
            'hold_until' => $case->holdUntil(),
            'state' => $case->state(),
            'reviewer_ref' => $case->reviewerReference(),
            'decision_reason' => $case->decisionReason(),
            'record_version' => $case->recordVersion(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $this->repository->insert('fraud_reviews', $case->reviewId(), $record);
        return $record;
    }

    /** @return array<string,mixed> */
    public function decideFraudReview(string $reviewId, bool $approved, string $reviewer, string $reason, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('fraud_reviews', $reviewId, $expectedVersion);
        $case = $this->fraud($record);
        $case->decide($approved, $reviewer, $reason, $at, $expectedVersion);
        return $this->updateFraud($reviewId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function appealFraudReview(string $reviewId, string $subjectReference, string $reason, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('fraud_reviews', $reviewId, $expectedVersion);
        if ((string)$record['subject_ref'] !== $subjectReference) {
            throw new InvariantViolation('Fraud appeal is outside subject scope.');
        }
        $case = $this->fraud($record);
        $case->appeal($reason, $expectedVersion);
        return $this->updateFraud($reviewId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function closeFraudReview(string $reviewId, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('fraud_reviews', $reviewId, $expectedVersion);
        $case = $this->fraud($record);
        $case->close($expectedVersion);
        return $this->updateFraud($reviewId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function openChargeback(ChargebackCase $case, DateTimeImmutable $createdAt): array
    {
        $this->configuration->assertFinancialMutationReady();

        return $this->repository->transaction(function () use ($case, $createdAt): array {
            $intent = $this->repository->get('intents', $case->paymentIntentId());
            if ($intent === null || !in_array((string)($intent['state'] ?? ''), ['captured', 'settled', 'disputed'], true)) {
                throw new InvariantViolation('Chargeback requires a captured, settled or disputed canonical payment intent.');
            }
            if ((string)($intent['provider'] ?? '') !== $case->providerCode()) {
                throw new InvariantViolation('Chargeback provider does not match the canonical payment provider.');
            }
            if ((string)($intent['currency'] ?? '') !== $case->disputedAmount()->currency()
                || (int)($intent['amount_minor'] ?? 0) < $case->disputedAmount()->minorUnits()
            ) {
                throw new InvariantViolation('Chargeback amount exceeds the canonical payment or changes currency.');
            }

            // Refund and chargeback reservations share one monetary ceiling. Fence both
            // domains on the canonical intent version so concurrent reservations cannot
            // independently consume the same remaining payment balance.
            $intentVersion = (int)($intent['version'] ?? $intent['record_version'] ?? 0);
            if ($intentVersion < 1) {
                throw new InvariantViolation('Chargeback payment intent version is missing.');
            }
            $this->repository->compareAndSwap(
                'intents',
                $case->paymentIntentId(),
                $intentVersion,
                static function (array $current) use ($case): array {
                    if (!in_array((string)($current['state'] ?? ''), ['captured','settled','disputed'], true)
                        || (string)($current['provider'] ?? '') !== $case->providerCode()
                    ) {
                        throw new InvariantViolation('Chargeback payment changed before exposure reservation.');
                    }
                    return $current;
                }
            );

            $providerMatches = $this->repository->find('chargebacks', [
                'provider' => $case->providerCode(),
                'provider_case_ref' => $case->providerCaseReference(),
            ], 2);
            if (count($providerMatches) > 1) {
                throw new InvariantViolation('Provider chargeback case identity is not unique.');
            }
            if ($providerMatches !== []) {
                $existing = $providerMatches[0];
                if (($existing['case_id'] ?? null) === $case->caseId()
                    && ($existing['intent_id'] ?? null) === $case->paymentIntentId()
                    && (int)($existing['amount_minor'] ?? -1) === $case->disputedAmount()->minorUnits()
                    && ($existing['currency'] ?? null) === $case->disputedAmount()->currency()
                    && ($existing['reason_code'] ?? null) === $case->reasonCode()
                ) {
                    return $existing + ['reused' => true];
                }
                throw new InvariantViolation('Provider chargeback case reference was reused with different canonical terms.');
            }

            $committed = (new PaymentExposureService($this->repository))
                ->combinedExposure($case->paymentIntentId(), $case->disputedAmount()->currency());
            $paid = (int)$intent['amount_minor'];
            if ($committed > $paid || $case->disputedAmount()->minorUnits() > $paid - $committed) {
                throw new InvariantViolation('Combined refund/chargeback exposure exceeds the canonical payment amount.');
            }

            $record = [
                'case_id' => $case->caseId(),
                'provider' => $case->providerCode(),
                'provider_case_ref' => $case->providerCaseReference(),
                'intent_id' => $case->paymentIntentId(),
                'amount_minor' => $case->disputedAmount()->minorUnits(),
                'currency' => $case->disputedAmount()->currency(),
                'reason_code' => $case->reasonCode(),
                'response_deadline' => $case->responseDeadline(),
                'evidence_hash' => $case->evidenceSha256(),
                'provider_fee_minor' => $case->providerFee()?->minorUnits(),
                'state' => $case->state(),
                'record_version' => $case->recordVersion(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $this->repository->insert('chargebacks', $case->caseId(), $record);
            return $record + ['reused' => false];
        });
    }

    /** @return array<string,mixed> */
    public function requireChargebackEvidence(string $caseId, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        $case->requireEvidence($at, $expectedVersion);
        return $this->updateChargeback($caseId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function submitChargebackEvidence(string $caseId, string $sha256, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        $case->submitEvidence($sha256, $at, $expectedVersion);
        return $this->updateChargeback($caseId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function acceptChargebackEvidence(string $caseId, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        $case->recordProviderAcceptance($expectedVersion);
        return $this->updateChargeback($caseId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function recordChargebackOutcome(string $caseId, bool $won, Money $providerFee, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        $case->recordOutcome($won, $providerFee, $expectedVersion);
        return $this->updateChargeback($caseId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    public function adjustChargebackLedger(string $caseId, string $operatorReference, DateTimeImmutable $at, int $expectedVersion): array
    {
        $this->configuration->assertFinancialMutationReady();
        self::reference($operatorReference);
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        if (!in_array($case->state(), ['won', 'lost'], true)) {
            throw new InvariantViolation('Chargeback outcome is not final.');
        }

        $fee = $case->providerFee() ?? Money::zero($case->disputedAmount()->currency());
        $requiresLedgerTransaction = $case->state() === 'lost' || $fee->minorUnits() > 0;
        $transactionId = $requiresLedgerTransaction
            ? 'txn.chargeback.'.substr(hash('sha256', $caseId), 0, 32)
            : null;

        $this->repository->transaction(function () use (
            $case,
            $record,
            $transactionId,
            $requiresLedgerTransaction,
            $fee,
            $operatorReference,
            $at,
            $expectedVersion,
            $caseId
        ): void {
            if ($requiresLedgerTransaction) {
                if ($transactionId === null) {
                    throw new InvariantViolation('Chargeback ledger transaction identity is unavailable.');
                }
                if ($this->repository->get('ledger_transactions', $transactionId) !== null) {
                    throw new InvariantViolation('Chargeback ledger transaction already exists before canonical ledger-adjusted state.');
                }
                $this->repository->insert('ledger_transactions', $transactionId, [
                    'transaction_id' => $transactionId,
                    'source_type' => 'chargeback_outcome',
                    'source_ref' => $case->providerCode().':'.$case->providerCaseReference(),
                    'effective_at' => $at,
                    'recorded_at' => $at,
                    'actor_ref' => $operatorReference,
                    'reason' => $case->state() === 'won' ? 'chargeback_won' : 'chargeback_lost',
                    'period_id' => $at->format('Y-m'),
                    'reversal_of' => null,
                    'trace_id' => 'trace:chargeback:'.substr(hash('sha256', $caseId), 0, 24),
                ]);
                if ($case->state() === 'lost') {
                    $this->entry($transactionId, $caseId.':loss', 'contra_income.chargeback', 'debit', $case->disputedAmount());
                    $this->entry($transactionId, $caseId.':clearing-loss', 'asset.provider_clearing', 'credit', $case->disputedAmount());
                }
                if ($fee->minorUnits() > 0) {
                    $this->entry($transactionId, $caseId.':fee', 'expense.payment_processing', 'debit', $fee);
                    $this->entry($transactionId, $caseId.':clearing-fee', 'asset.provider_clearing', 'credit', $fee);
                    $expenseId = 'expense.chargeback.'.substr(hash('sha256', $caseId), 0, 28);
                    $this->repository->insert('expenses', $expenseId, [
                        'expense_id' => $expenseId,
                        'occurred_at' => $at,
                        'amount_minor' => $fee->minorUnits(),
                        'currency' => $fee->currency(),
                        'category' => DonationExpenseCategory::ADMINISTRATION_PAYMENT_CHARGES,
                        'purpose' => 'Chargeback provider fee',
                        'payee_ref' => 'provider:'.$case->providerCode(),
                        'approval_ref' => 'chargeback:'.$caseId,
                        'receipt_status' => 'verified',
                        'founder_related' => false,
                        'public_disclosure_category' => 'administration_payment_charges',
                        'source_transaction_id' => $transactionId,
                        'record_version' => 1,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }
            }
            $case->markLedgerAdjusted($expectedVersion);
            $this->updateChargeback($caseId, $record, $case, $at);
        });

        return [
            'case_id' => $caseId,
            'state' => 'ledger_adjusted',
            'transaction_id' => $transactionId,
            'record_version' => $expectedVersion + 1,
        ];
    }

    /** @return array<string,mixed> */
    public function closeChargeback(string $caseId, DateTimeImmutable $at, int $expectedVersion): array
    {
        $record = $this->require('chargebacks', $caseId, $expectedVersion);
        $case = $this->chargeback($record);
        $case->close($expectedVersion);
        return $this->updateChargeback($caseId, $record, $case, $at);
    }

    /** @return array<string,mixed> */
    private function require(string $collection, string $id, int $version): array
    {
        $record = $this->repository->get($collection, $id);
        if ($record === null || (int)($record['version'] ?? 0) !== $version) {
            throw new InvariantViolation('Risk record is missing or stale.');
        }
        return $record;
    }

    private function fraud(array $record): FraudReviewCase
    {
        return new FraudReviewCase(
            (string)$record['review_id'],
            (string)$record['subject_ref'],
            (array)$record['signals_json'],
            new DateTimeImmutable((string)$record['created_at']),
            new DateTimeImmutable((string)$record['hold_until']),
            (string)$record['state'],
            (int)$record['version'],
            is_string($record['reviewer_ref'] ?? null) ? $record['reviewer_ref'] : null,
            is_string($record['decision_reason'] ?? null) ? $record['decision_reason'] : null
        );
    }

    private function chargeback(array $record): ChargebackCase
    {
        return new ChargebackCase(
            (string)$record['case_id'],
            (string)$record['provider'],
            (string)$record['provider_case_ref'],
            (string)$record['intent_id'],
            new Money((int)$record['amount_minor'], (string)$record['currency']),
            (string)$record['reason_code'],
            new DateTimeImmutable((string)$record['created_at']),
            new DateTimeImmutable((string)$record['response_deadline']),
            (string)$record['state'],
            (int)$record['version'],
            is_string($record['evidence_hash'] ?? null) ? $record['evidence_hash'] : null,
            isset($record['provider_fee_minor']) && $record['provider_fee_minor'] !== null
                ? new Money((int)$record['provider_fee_minor'], (string)$record['currency'])
                : null
        );
    }

    /** @return list<array<string,mixed>> */
    private function chargebacksForIntent(string $intentId): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $this->repository->page(
                'chargebacks',
                ['intent_id' => $intentId],
                self::PAGE_SIZE,
                $offset
            );
            foreach ($page as $record) {
                $rows[] = $record;
            }
            $offset += count($page);
            if ($offset > self::MAX_CHARGEBACKS_PER_INTENT) {
                throw new InvariantViolation('Chargeback history exceeds the safe automatic dispute-balance bound.');
            }
        } while (count($page) === self::PAGE_SIZE);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function updateFraud(string $id, array $record, FraudReviewCase $case, DateTimeImmutable $at): array
    {
        return $this->repository->compareAndSwap(
            'fraud_reviews',
            $id,
            (int)$record['version'],
            static function (array $current) use ($case, $at): array {
                $current['state'] = $case->state();
                $current['reviewer_ref'] = $case->reviewerReference();
                $current['decision_reason'] = $case->decisionReason();
                $current['updated_at'] = $at;
                return $current;
            }
        );
    }

    /** @return array<string,mixed> */
    private function updateChargeback(string $id, array $record, ChargebackCase $case, DateTimeImmutable $at): array
    {
        return $this->repository->compareAndSwap(
            'chargebacks',
            $id,
            (int)$record['version'],
            static function (array $current) use ($case, $at): array {
                $current['state'] = $case->state();
                $current['evidence_hash'] = $case->evidenceSha256();
                $current['provider_fee_minor'] = $case->providerFee()?->minorUnits();
                $current['updated_at'] = $at;
                return $current;
            }
        );
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

    private static function reference(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException('Risk operator reference is invalid.');
        }
    }
}
