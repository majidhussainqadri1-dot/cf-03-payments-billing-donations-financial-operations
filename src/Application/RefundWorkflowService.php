<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class RefundWorkflowService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function request(string $refundId, string $intentId, string $requesterReference, Money $amount, string $reasonCode, DateTimeImmutable $now): array
    {
        self::reference($refundId, 'Refund ID'); self::reference($intentId, 'Intent ID'); self::reference($requesterReference, 'Requester reference');
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $reasonCode) !== 1) { throw new InvalidArgumentException('Refund reason code is invalid.'); }
        $intent = $this->repository->get('intents', $intentId);
        if ($intent === null || (string)($intent['actor_ref'] ?? '') !== $requesterReference) { throw new InvariantViolation('Refundable payment was not found in requester scope.'); }
        if (!in_array((string)($intent['state'] ?? ''), ['captured','settled'], true)) { throw new InvariantViolation('Payment is not in a refundable state.'); }
        $paid = new Money((int)$intent['amount_minor'], (string)$intent['currency']);
        if ($amount->currency() !== $paid->currency() || $amount->minorUnits() > $paid->minorUnits()) { throw new InvariantViolation('Refund exceeds the remaining payment balance or currency.'); }
        $record = [
            'refund_id'=>$refundId,'intent_id'=>$intentId,'amount_minor'=>$amount->minorUnits(),'currency'=>$amount->currency(),
            'refundable_balance_minor'=>$paid->minorUnits(),'requester_ref'=>$requesterReference,'reviewer_ref'=>null,'executor_ref'=>null,
            'reason'=>$reasonCode,'decision_reason'=>null,'policy_version'=>'refund.current.v1','state'=>'requested','provider_ref'=>null,
            'record_version'=>1,'requested_at'=>$now,'updated_at'=>$now,
        ];
        $this->repository->insert('refunds', $refundId, $record);
        return $this->safe($record);
    }

    /** @return array<string,mixed> */
    public function review(string $refundId, string $reviewerReference, bool $approve, string $decisionReason, int $expectedVersion, DateTimeImmutable $now): array
    {
        self::reference($reviewerReference, 'Reviewer reference');
        if (trim($decisionReason) === '' || strlen($decisionReason) > 255) { throw new InvalidArgumentException('Refund decision reason is required and bounded.'); }
        $updated = $this->repository->compareAndSwap('refunds', $refundId, $expectedVersion, static function(array $current) use ($reviewerReference,$approve,$decisionReason,$now): array {
            if (($current['state'] ?? null) !== 'requested') { throw new InvariantViolation('Only requested refunds may be reviewed.'); }
            if (($current['requester_ref'] ?? null) === $reviewerReference) { throw new InvariantViolation('Refund requester cannot review the same refund.'); }
            $current['reviewer_ref']=$reviewerReference; $current['decision_reason']=$decisionReason;
            $current['state']=$approve?'approved':'denied'; $current['updated_at']=$now; return $current;
        });
        return $this->safe($updated);
    }

    /** @return array<string,mixed> */
    public function execute(string $refundId, string $executorReference, int $expectedVersion, string $idempotencyKey, DateTimeImmutable $now): array
    {
        $this->configuration->assertFinancialMutationReady(); self::reference($executorReference, 'Executor reference');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $idempotencyKey) !== 1) { throw new InvalidArgumentException('Refund idempotency key is invalid.'); }
        $refund = $this->repository->get('refunds', $refundId);
        if ($refund === null || (int)($refund['version'] ?? 0) !== $expectedVersion || ($refund['state'] ?? null) !== 'approved') {
            throw new InvariantViolation('Refund approval is missing, stale or no longer executable.');
        }
        if (in_array($executorReference, [(string)$refund['requester_ref'],(string)$refund['reviewer_ref']], true)) {
            throw new InvariantViolation('Refund executor must be distinct from requester and reviewer.');
        }
        $intent = $this->repository->get('intents', (string)$refund['intent_id']);
        if ($intent === null || !is_string($intent['provider_ref'] ?? null) || $intent['provider_ref'] === '') { throw new InvariantViolation('Refund provider payment reference is unavailable.'); }
        $provider = $this->providers->get((string)$intent['provider']);
        $providerRefundReference = $provider->refund((string)$intent['provider_ref'], new Money((int)$refund['amount_minor'], (string)$refund['currency']), $idempotencyKey);
        $updated = $this->repository->compareAndSwap('refunds', $refundId, $expectedVersion, static function(array $current) use ($executorReference,$providerRefundReference,$now): array {
            $current['executor_ref']=$executorReference; $current['provider_ref']=$providerRefundReference;
            $current['state']='provider_pending'; $current['updated_at']=$now; return $current;
        });
        return $this->safe($updated);
    }

    /** @return array<string,mixed> */
    public function settle(string $refundId, int $expectedVersion, bool $reconciled, DateTimeImmutable $now): array
    {
        $updated = $this->repository->compareAndSwap('refunds', $refundId, $expectedVersion, static function(array $current) use ($reconciled,$now): array {
            if (!in_array((string)($current['state'] ?? ''), ['provider_pending','uncertain','succeeded'], true)) { throw new InvariantViolation('Refund is not awaiting provider settlement.'); }
            $current['state']=$reconciled?'closed':'succeeded'; $current['updated_at']=$now; return $current;
        });
        return $this->safe($updated);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function safe(array $record): array
    {
        return array_intersect_key($record, array_flip(['refund_id','intent_id','amount_minor','currency','reason','decision_reason','state','requested_at','updated_at','version','record_version']));
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/', $value) !== 1) { throw new InvalidArgumentException($label.' is invalid.'); }
    }
}
