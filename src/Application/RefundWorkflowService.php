<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class RefundWorkflowService
{
    /** @var list<string> */
    private const BALANCE_COMMITTING_STATES = [
        'requested', 'approved', 'provider_pending', 'uncertain', 'succeeded', 'closed',
    ];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function request(
        string $refundId,
        string $intentId,
        string $requesterReference,
        Money $amount,
        string $reasonCode,
        DateTimeImmutable $now
    ): array {
        self::reference($refundId, 'Refund ID');
        self::reference($intentId, 'Intent ID');
        self::reference($requesterReference, 'Requester reference');
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Refund reason code is invalid.');
        }
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        $existing = $this->repository->get('refunds', $refundId);
        if ($existing !== null) {
            if (($existing['intent_id'] ?? null) === $intentId
                && ($existing['requester_ref'] ?? null) === $requesterReference
                && (int)($existing['amount_minor'] ?? -1) === $amount->minorUnits()
                && ($existing['currency'] ?? null) === $amount->currency()
                && ($existing['reason'] ?? null) === $reasonCode
            ) {
                return $this->safe($existing) + ['reused' => true];
            }
            throw new InvariantViolation('Refund identifier already exists with different terms.');
        }

        $intent = $this->repository->get('intents', $intentId);
        if ($intent === null || (string)($intent['actor_ref'] ?? '') !== $requesterReference) {
            throw new InvariantViolation('Refundable payment was not found in requester scope.');
        }
        if (!in_array((string)($intent['state'] ?? ''), ['captured', 'settled'], true)) {
            throw new InvariantViolation('Payment is not in a refundable state.');
        }
        $paid = new Money((int)$intent['amount_minor'], (string)$intent['currency']);
        if ($amount->currency() !== $paid->currency()) {
            throw new InvariantViolation('Refund currency does not match the payment.');
        }

        $committed = 0;
        foreach ($this->repository->find('refunds', ['intent_id' => $intentId], 500) as $prior) {
            if (!in_array((string)($prior['state'] ?? ''), self::BALANCE_COMMITTING_STATES, true)) {
                continue;
            }
            if (($prior['currency'] ?? null) !== $paid->currency()) {
                throw new InvariantViolation('Existing refund records contain a conflicting currency.');
            }
            $priorAmount = (int)($prior['amount_minor'] ?? -1);
            if ($priorAmount <= 0 || $priorAmount > PHP_INT_MAX - $committed) {
                throw new InvariantViolation('Existing refund balance evidence is invalid.');
            }
            $committed += $priorAmount;
        }
        if ($committed > $paid->minorUnits()) {
            throw new InvariantViolation('Committed refunds already exceed the original payment.');
        }
        $remaining = $paid->minorUnits() - $committed;
        if ($amount->minorUnits() > $remaining) {
            throw new InvariantViolation('Refund exceeds the remaining payment balance.');
        }

        $record = [
            'refund_id' => $refundId,
            'intent_id' => $intentId,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'refundable_balance_minor' => $remaining,
            'requester_ref' => $requesterReference,
            'reviewer_ref' => null,
            'executor_ref' => null,
            'reason' => $reasonCode,
            'decision_reason' => null,
            'policy_version' => 'refund.current.v1',
            'state' => 'requested',
            'provider_ref' => null,
            'record_version' => 1,
            'requested_at' => $now,
            'updated_at' => $now,
        ];
        $this->repository->insert('refunds', $refundId, $record);
        return $this->safe($record) + ['reused' => false];
    }

    /** @return array<string,mixed> */
    public function review(
        string $refundId,
        string $reviewerReference,
        bool $approve,
        string $decisionReason,
        int $expectedVersion,
        DateTimeImmutable $now
    ): array {
        self::reference($refundId, 'Refund ID');
        self::reference($reviewerReference, 'Reviewer reference');
        if (trim($decisionReason) === '' || strlen($decisionReason) > 255) {
            throw new InvalidArgumentException('Refund decision reason is required and bounded.');
        }
        $updated = $this->repository->compareAndSwap(
            'refunds',
            $refundId,
            $expectedVersion,
            static function (array $current) use ($reviewerReference, $approve, $decisionReason, $now): array {
                if (($current['state'] ?? null) !== 'requested') {
                    throw new InvariantViolation('Only requested refunds may be reviewed.');
                }
                if (($current['requester_ref'] ?? null) === $reviewerReference) {
                    throw new InvariantViolation('Refund requester cannot review the same refund.');
                }
                $current['reviewer_ref'] = $reviewerReference;
                $current['decision_reason'] = $decisionReason;
                $current['state'] = $approve ? 'approved' : 'denied';
                $current['updated_at'] = $now;
                return $current;
            }
        );
        return $this->safe($updated);
    }

    /** @return array<string,mixed> */
    public function execute(
        string $refundId,
        string $executorReference,
        int $expectedVersion,
        string $idempotencyKey,
        DateTimeImmutable $now
    ): array {
        $this->configuration->assertFinancialMutationReady();
        self::reference($refundId, 'Refund ID');
        self::reference($executorReference, 'Executor reference');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Refund idempotency key is invalid.');
        }
        $refund = $this->repository->get('refunds', $refundId);
        if ($refund === null
            || (int)($refund['version'] ?? 0) !== $expectedVersion
            || ($refund['state'] ?? null) !== 'approved'
        ) {
            throw new InvariantViolation('Refund approval is missing, stale or no longer executable.');
        }
        if (in_array($executorReference, [(string)$refund['requester_ref'], (string)$refund['reviewer_ref']], true)) {
            throw new InvariantViolation('Refund executor must be distinct from requester and reviewer.');
        }
        $intent = $this->repository->get('intents', (string)$refund['intent_id']);
        if ($intent === null || !is_string($intent['provider_ref'] ?? null) || $intent['provider_ref'] === '') {
            throw new InvariantViolation('Refund provider payment reference is unavailable.');
        }
        if (($intent['currency'] ?? null) !== ($refund['currency'] ?? null)
            || (int)($refund['amount_minor'] ?? 0) <= 0
        ) {
            throw new InvariantViolation('Refund execution terms do not match the canonical payment.');
        }

        $claimReference = 'pending:'.substr(hash('sha256', $refundId.'|'.$idempotencyKey), 0, 40);
        $claimed = $this->repository->compareAndSwap(
            'refunds',
            $refundId,
            $expectedVersion,
            static function (array $current) use ($executorReference, $claimReference, $now): array {
                if (($current['state'] ?? null) !== 'approved') {
                    throw new InvariantViolation('Refund is no longer approved for execution.');
                }
                $current['executor_ref'] = $executorReference;
                $current['provider_ref'] = $claimReference;
                $current['state'] = 'provider_pending';
                $current['updated_at'] = $now;
                return $current;
            }
        );
        $claimedVersion = (int)$claimed['version'];

        try {
            $provider = $this->providers->get((string)$intent['provider']);
            $providerRefundReference = $provider->refund(
                (string)$intent['provider_ref'],
                new Money((int)$refund['amount_minor'], (string)$refund['currency']),
                $idempotencyKey
            );
            self::reference($providerRefundReference, 'Provider refund reference');
            if (hash_equals($claimReference, $providerRefundReference)) {
                throw new InvariantViolation('Provider refund reference cannot equal the internal execution checkpoint.');
            }
            $confirmed = $this->repository->updateWhere('refunds', [
                'refund_id' => $refundId,
                'state' => 'provider_pending',
                'provider_ref' => $claimReference,
                'record_version' => $claimedVersion,
            ], [
                'provider_ref' => $providerRefundReference,
                'updated_at' => $now,
            ]);
            if ($confirmed !== 1) {
                throw new InvariantViolation('Refund execution checkpoint changed before provider confirmation.');
            }
            $updated = $this->repository->get('refunds', $refundId);
            if ($updated === null) {
                throw new InvariantViolation('Confirmed refund record disappeared.');
            }
            return $this->safe($updated);
        } catch (Throwable $error) {
            try {
                $this->repository->updateWhere('refunds', [
                    'refund_id' => $refundId,
                    'state' => 'provider_pending',
                    'provider_ref' => $claimReference,
                    'record_version' => $claimedVersion,
                ], [
                    'state' => 'uncertain',
                    'updated_at' => $now,
                ]);
            } catch (Throwable) {
                // Preserve the original provider/confirmation failure; reconciliation must inspect the durable checkpoint.
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function settle(string $refundId, int $expectedVersion, bool $reconciled, DateTimeImmutable $now): array
    {
        self::reference($refundId, 'Refund ID');
        $updated = $this->repository->compareAndSwap(
            'refunds',
            $refundId,
            $expectedVersion,
            static function (array $current) use ($reconciled, $now): array {
                if (!in_array((string)($current['state'] ?? ''), ['provider_pending', 'uncertain', 'succeeded'], true)) {
                    throw new InvariantViolation('Refund is not awaiting provider settlement.');
                }
                $current['state'] = $reconciled ? 'closed' : 'succeeded';
                $current['updated_at'] = $now;
                return $current;
            }
        );
        return $this->safe($updated);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function safe(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'refund_id', 'intent_id', 'amount_minor', 'currency', 'refundable_balance_minor',
            'reason', 'decision_reason', 'state', 'requested_at', 'updated_at', 'version', 'record_version',
        ]));
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
