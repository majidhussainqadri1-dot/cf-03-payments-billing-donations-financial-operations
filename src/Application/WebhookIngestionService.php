<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PaymentIntentTransition;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProviderEventStateMapper;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Support\InvariantViolation;

final class WebhookIngestionService
{
    public function __construct(
        private readonly RuntimeConfiguration $configuration,
        private readonly ProviderRegistry $providers,
        private readonly QueryableFinancialRepository $repository,
        private readonly ProviderEventStateMapper $mapper = new ProviderEventStateMapper(),
        private readonly PaymentIntentTransition $transitions = new PaymentIntentTransition()
    ) {}

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function ingest(string $providerCode, string $rawBody, array $headers, int $receivedAt): array
    {
        $this->configuration->assertWebhookReady();
        if ($providerCode !== $this->configuration->providerCode()) {
            throw new InvariantViolation('Webhook provider is not the approved runtime provider.');
        }

        $evidence = $this->providers->get($providerCode)->verifyWebhook($rawBody, $headers, $receivedAt);
        if ($evidence->providerCode() !== $providerCode) {
            throw new InvariantViolation('Verified webhook provider identity mismatch.');
        }

        $duplicates = $this->repository->find('provider_events', [
            'provider' => $providerCode,
            'provider_event_id' => $evidence->providerEventId(),
        ], 2);
        if (count($duplicates) > 1) {
            throw new InvariantViolation('Provider event identity is not unique.');
        }
        if ($duplicates !== []) {
            $this->assertDuplicateParity($duplicates[0], $evidence);
            return [
                'status' => 'duplicate_acknowledged',
                'provider_event_id' => $evidence->providerEventId(),
            ];
        }

        $mapped = $this->mapper->mapTrusted($evidence);
        $intent = $this->repository->get('intents', $evidence->paymentIntentId());
        $traceId = 'trace.'.substr(hash('sha256', $providerCode.'|'.$evidence->providerEventId()), 0, 40);

        if ($intent === null) {
            $this->recordEvent($evidence, $mapped, 'quarantined_missing_intent', $traceId, null);
            return [
                'status' => 'quarantined',
                'reason' => 'missing_intent',
                'provider_event_id' => $evidence->providerEventId(),
            ];
        }

        // A signed provider event can never revive the superseded recurring,
        // subscription or paid-core models. Legacy intents are preserved as evidence
        // but are quarantined for manual/provider reconciliation without money-state mutation.
        if (($intent['product_id'] ?? null) !== 'donation.one_time') {
            $this->recordEvent(
                $evidence,
                $mapped,
                'quarantined_retired_financial_product',
                $traceId,
                (string)($intent['intent_id'] ?? '')
            );
            return [
                'status' => 'quarantined',
                'reason' => 'retired_financial_product',
                'provider_event_id' => $evidence->providerEventId(),
            ];
        }

        $this->assertChronology($intent, $evidence);
        $version = (int)($intent['version'] ?? $intent['record_version'] ?? 0);
        if ($version < 1) {
            throw new InvariantViolation('Payment intent version is missing.');
        }

        if ($mapped === PaymentIntentState::REFUNDED) {
            $this->assertRefundEvidence($intent, $evidence);
            $this->repository->transaction(function () use ($intent, $evidence, $mapped, $traceId, $version): void {
                $this->recordEvent($evidence, $mapped, 'accepted', $traceId, (string)$intent['intent_id']);
                $this->postRefund($intent, $evidence, $traceId, $version);
                $this->markEventProcessed($evidence);
            });
            return [
                'status' => 'processed',
                'intent_id' => $evidence->paymentIntentId(),
                'mapped_state' => $mapped->value,
                'payment_intent_state_unchanged' => true,
                'provider_event_id' => $evidence->providerEventId(),
            ];
        }

        $expectedAmount = new Money((int)$intent['amount_minor'], (string)$intent['currency']);
        $evidence->assertMatches((string)$intent['provider'], (string)$intent['intent_id'], $expectedAmount);
        $from = PaymentIntentState::from((string)$intent['state']);

        if ($from === $mapped) {
            $this->repository->transaction(function () use ($intent, $evidence, $mapped, $traceId): void {
                $this->recordEvent($evidence, $mapped, 'duplicate_semantic', $traceId, (string)$intent['intent_id']);
                $this->markEventProcessed($evidence);
            });
            return [
                'status' => 'duplicate_semantic_acknowledged',
                'intent_id' => $evidence->paymentIntentId(),
                'mapped_state' => $mapped->value,
                'provider_event_id' => $evidence->providerEventId(),
            ];
        }

        $this->transitions->assertAllowed($from, $mapped);
        $this->repository->transaction(function () use ($evidence, $mapped, $traceId, $intent, $version): void {
            $this->recordEvent($evidence, $mapped, 'accepted', $traceId, (string)$intent['intent_id']);
            $this->repository->compareAndSwap(
                'intents',
                (string)$intent['intent_id'],
                $version,
                static function (array $current) use ($mapped, $evidence): array {
                    $current['state'] = $mapped->value;
                    $current['failure_code'] = $mapped === PaymentIntentState::FAILED ? 'provider_failed' : null;
                    $current['updated_at'] = $evidence->receivedAt();
                    return $current;
                }
            );

            if ($mapped === PaymentIntentState::SETTLED) {
                $this->postSettlement($intent, $evidence, $traceId, $version + 1);
            } elseif ($mapped === PaymentIntentState::FAILED) {
                $this->postSafeStatusFact('PaymentFailed', $intent, $evidence, $traceId, $version + 1);
            } elseif ($mapped === PaymentIntentState::CANCELLED) {
                $this->postSafeStatusFact('PaymentCancelled', $intent, $evidence, $traceId, $version + 1);
            } elseif ($mapped === PaymentIntentState::DISPUTED) {
                $this->postSafeStatusFact('PaymentDisputed', $intent, $evidence, $traceId, $version + 1);
            }
            $this->markEventProcessed($evidence);
        });

        return [
            'status' => 'processed',
            'intent_id' => $evidence->paymentIntentId(),
            'mapped_state' => $mapped->value,
            'provider_event_id' => $evidence->providerEventId(),
        ];
    }

    /** @param array<string,mixed> $existing */
    private function assertDuplicateParity(array $existing, ProviderEvidence $evidence): void
    {
        if (($existing['provider'] ?? null) !== $evidence->providerCode()
            || ($existing['provider_event_id'] ?? null) !== $evidence->providerEventId()
            || ($existing['event_type'] ?? null) !== $evidence->eventType()
            || ($existing['raw_body_hash'] ?? null) !== $evidence->rawBodySha256()
            || (($existing['intent_id'] ?? null) !== null
                && ($existing['intent_id'] ?? null) !== $evidence->paymentIntentId())
        ) {
            throw new InvariantViolation('Provider event ID was reused with different signed evidence.');
        }
    }

    /** @param array<string,mixed> $intent */
    private function assertChronology(array $intent, ProviderEvidence $evidence): void
    {
        $createdAt = self::date($intent['created_at'] ?? null, 'Payment intent creation time');
        if ($evidence->occurredAt() < $createdAt) {
            throw new InvariantViolation('Provider event predates the canonical payment intent.');
        }
        $expiresAt = self::date($intent['expires_at'] ?? null, 'Payment intent expiry');
        if ($evidence->eventType() === 'payment.settled'
            && $evidence->occurredAt() > $expiresAt->modify('+24 hours')
        ) {
            throw new InvariantViolation('Settlement evidence is implausibly later than the hosted intent expiry.');
        }
    }

    /** @param array<string,mixed> $intent */
    private function assertRefundEvidence(array $intent, ProviderEvidence $evidence): void
    {
        if (($intent['product_id'] ?? null) !== 'donation.one_time') {
            throw new InvariantViolation('Refund evidence for a retired financial product requires manual reconciliation.');
        }
        if ($evidence->providerCode() !== (string)$intent['provider']
            || $evidence->paymentIntentId() !== (string)$intent['intent_id']
        ) {
            throw new InvariantViolation('Refund evidence does not match the canonical provider and intent.');
        }
        if (!in_array((string)$intent['state'], [PaymentIntentState::CAPTURED->value, PaymentIntentState::SETTLED->value], true)) {
            throw new InvariantViolation('Refund evidence requires a captured or settled payment intent.');
        }
        $original = new Money((int)$intent['amount_minor'], (string)$intent['currency']);
        if ($evidence->amount()->minorUnits() <= 0
            || $evidence->amount()->currency() !== $original->currency()
            || $evidence->amount()->minorUnits() > $original->minorUnits()
        ) {
            throw new InvariantViolation('Refund evidence is zero, exceeds the original payment or changes currency.');
        }
    }

    private function recordEvent(
        ProviderEvidence $evidence,
        PaymentIntentState $mapped,
        string $status,
        string $traceId,
        ?string $intentId
    ): void {
        $this->repository->insert('provider_events', $evidence->providerEventId(), [
            'provider' => $evidence->providerCode(),
            'provider_event_id' => $evidence->providerEventId(),
            'event_type' => $evidence->eventType(),
            'raw_body_hash' => $evidence->rawBodySha256(),
            'signature_key_version' => $evidence->signatureKeyVersion(),
            'signature_timestamp' => $evidence->signatureTimestamp(),
            'received_at' => $evidence->receivedAt(),
            'mapped_state' => $mapped->value,
            'status' => $status,
            'intent_id' => $intentId,
            'trace_id' => $traceId,
            'processed_at' => null,
        ]);
    }

    private function markEventProcessed(ProviderEvidence $evidence): void
    {
        $updated = $this->repository->updateWhere('provider_events', [
            'provider' => $evidence->providerCode(),
            'provider_event_id' => $evidence->providerEventId(),
        ], [
            'status' => 'processed',
            'processed_at' => $evidence->receivedAt(),
        ]);
        if ($updated !== 1) {
            throw new InvariantViolation('Verified provider event could not be marked processed exactly once.');
        }
    }

    /** @param array<string,mixed> $intent */
    private function postSettlement(array $intent, ProviderEvidence $evidence, string $traceId, int $aggregateVersion): void
    {
        if (($intent['product_id'] ?? null) !== 'donation.one_time') {
            throw new InvariantViolation('Only the one-time donation product may settle through active CF-03.');
        }
        $amount = $evidence->amount();
        $now = $evidence->receivedAt();
        $intentId = (string)$intent['intent_id'];
        $donationId = DonationCheckoutService::donationIdForIntent($intentId);
        $donation = $this->repository->get('donations', $donationId);
        if ($donation === null
            || ($donation['donor_ref'] ?? null) !== ($intent['actor_ref'] ?? null)
            || (int)($donation['amount_minor'] ?? -1) !== $amount->minorUnits()
            || ($donation['currency'] ?? null) !== $amount->currency()
            || ($donation['provider_ref'] ?? null) !== ($intent['provider_ref'] ?? null)
            || ($donation['state'] ?? null) !== 'provider_pending'
            || (bool)($donation['recurring'] ?? false) !== false
            || ($donation['recurring_consent_id'] ?? null) !== null
        ) {
            throw new InvariantViolation('Canonical one-time donation aggregate is missing or inconsistent with settlement evidence.');
        }

        $transactionId = 'txn.'.substr(hash('sha256', 'settled|'.$evidence->providerCode().'|'.$evidence->providerEventId()), 0, 40);
        if ($this->repository->get('ledger_transactions', $transactionId) !== null) {
            throw new InvariantViolation('Settlement ledger transaction already exists without a processed provider event.');
        }
        $periodId = $evidence->occurredAt()->format('Y-m');
        $this->repository->insert('ledger_transactions', $transactionId, [
            'transaction_id' => $transactionId,
            'source_type' => 'provider_settlement',
            'source_ref' => $evidence->providerCode().':'.$evidence->providerEventId(),
            'effective_at' => $evidence->occurredAt(),
            'recorded_at' => $now,
            'actor_ref' => 'system:provider',
            'reason' => 'trusted_provider_settlement',
            'period_id' => $periodId,
            'reversal_of' => null,
            'trace_id' => $traceId,
        ]);
        $this->repository->insert('ledger_entries', $intentId.':asset', [
            'transaction_id' => $transactionId,
            'account' => 'asset.provider_clearing',
            'direction' => 'debit',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $intentId.':asset',
        ]);
        $this->repository->insert('ledger_entries', $intentId.':income', [
            'transaction_id' => $transactionId,
            'account' => 'income.donation',
            'direction' => 'credit',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $intentId.':income',
        ]);

        $invoiceId = 'invoice.'.substr(hash('sha256', $intentId), 0, 40);
        $snapshot = [
            'kind' => 'donation_receipt',
            'donation_type' => 'one_time',
            'recurring' => false,
            'intent_id' => $intentId,
            'product_id' => 'donation.one_time',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'settled_at' => $evidence->occurredAt()->format(DATE_ATOM),
            'policy' => PlatformFinancialPolicy::DECISION_ID,
            'governing_plan' => PlatformFinancialPolicy::GOVERNING_CF03_PLAN,
            'no_privilege' => true,
        ];
        try {
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Receipt snapshot could not be encoded.', 0, $error);
        }
        $this->repository->insert('invoices', $invoiceId, [
            'invoice_id' => $invoiceId,
            'invoice_number' => 'DON-'.strtoupper(substr(hash('sha256', $intentId), 0, 12)),
            'actor_ref' => $intent['actor_ref'],
            'status' => 'paid',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'snapshot_hash' => hash('sha256', $encoded),
            'snapshot_json' => $snapshot,
            'issued_at' => $now,
            'voided_at' => null,
        ]);

        $this->repository->compareAndSwap(
            'donations',
            $donationId,
            (int)$donation['version'],
            static function (array $current) use ($invoiceId, $now): array {
                $current['state'] = 'settled';
                $current['receipt_ref'] = $invoiceId;
                $current['recurring'] = false;
                $current['recurring_consent_id'] = null;
                $current['updated_at'] = $now;
                return $current;
            }
        );

        $this->outbox('DonationSettled', $intentId, $aggregateVersion, $traceId, [
            'actor_ref' => $intent['actor_ref'],
            'intent_id' => $intentId,
            'donation_id' => $donationId,
            'receipt_id' => $invoiceId,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'donation_type' => 'one_time',
            'recurring' => false,
            'no_access_event' => true,
            'occurred_at' => $evidence->occurredAt()->format(DATE_ATOM),
        ], $now);
    }

    /** @param array<string,mixed> $intent */
    private function postRefund(array $intent, ProviderEvidence $evidence, string $traceId, int $aggregateVersion): void
    {
        if (($intent['product_id'] ?? null) !== 'donation.one_time') {
            throw new InvariantViolation('Only one-time donation refunds may post through active CF-03.');
        }
        $intentId = (string)$intent['intent_id'];
        $now = $evidence->receivedAt();
        $amount = $evidence->amount();
        $originalAmount = (int)$intent['amount_minor'];

        $refunds = $this->repository->find('refunds', ['intent_id' => $intentId], 500);
        $alreadyRefunded = 0;
        $matchingOpen = [];
        foreach ($refunds as $refund) {
            if ((string)($refund['currency'] ?? '') !== $amount->currency()) { continue; }
            $state = (string)($refund['state'] ?? '');
            if (in_array($state, ['succeeded', 'closed'], true)) {
                $value = (int)($refund['amount_minor'] ?? 0);
                if ($value <= 0 || $value > PHP_INT_MAX - $alreadyRefunded) {
                    throw new InvariantViolation('Historical refund balance evidence is invalid.');
                }
                $alreadyRefunded += $value;
            }
            if ((int)($refund['amount_minor'] ?? 0) === $amount->minorUnits()
                && in_array($state, ['approved', 'provider_pending', 'uncertain'], true)
            ) {
                $matchingOpen[] = $refund;
            }
        }
        if (count($matchingOpen) > 1) {
            throw new InvariantViolation('Refund evidence matches multiple open refund requests and requires manual reconciliation.');
        }
        if ($alreadyRefunded + $amount->minorUnits() > $originalAmount) {
            throw new InvariantViolation('Cumulative provider refunds exceed the original payment.');
        }

        $refundId = 'refund.external.'.substr(hash('sha256', $evidence->providerCode().'|'.$evidence->providerEventId()), 0, 32);
        if ($matchingOpen !== []) {
            $match = $matchingOpen[0];
            $refundId = (string)$match['refund_id'];
            $this->repository->compareAndSwap(
                'refunds',
                $refundId,
                (int)$match['version'],
                static function (array $current) use ($evidence, $now): array {
                    $current['state'] = 'closed';
                    $current['provider_ref'] = $evidence->providerEventId();
                    $current['updated_at'] = $now;
                    return $current;
                }
            );
        } else {
            $this->repository->insert('refunds', $refundId, [
                'refund_id' => $refundId,
                'intent_id' => $intentId,
                'amount_minor' => $amount->minorUnits(),
                'currency' => $amount->currency(),
                'refundable_balance_minor' => max(0, $originalAmount - $alreadyRefunded - $amount->minorUnits()),
                'requester_ref' => 'system:provider',
                'reviewer_ref' => 'system:provider_evidence',
                'executor_ref' => 'system:provider',
                'reason' => 'provider_initiated_refund',
                'decision_reason' => 'trusted_provider_refund_evidence',
                'policy_version' => PlatformFinancialPolicy::DECISION_ID,
                'state' => 'closed',
                'provider_ref' => $evidence->providerEventId(),
                'record_version' => 1,
                'requested_at' => $evidence->occurredAt(),
                'updated_at' => $now,
            ]);
        }

        $originalSettlementEntries = $this->repository->find('ledger_entries', ['source_ref' => $intentId.':asset'], 2);
        if (count($originalSettlementEntries) !== 1 || !is_string($originalSettlementEntries[0]['transaction_id'] ?? null)) {
            throw new InvariantViolation('Refund cannot be posted without exactly one canonical settlement transaction.');
        }
        $reversalOf = (string)$originalSettlementEntries[0]['transaction_id'];
        $transactionId = 'txn.'.substr(hash('sha256', 'refund|'.$evidence->providerCode().'|'.$evidence->providerEventId()), 0, 40);
        $this->repository->insert('ledger_transactions', $transactionId, [
            'transaction_id' => $transactionId,
            'source_type' => 'provider_refund',
            'source_ref' => $evidence->providerCode().':'.$evidence->providerEventId(),
            'effective_at' => $evidence->occurredAt(),
            'recorded_at' => $now,
            'actor_ref' => 'system:provider',
            'reason' => 'trusted_provider_refund',
            'period_id' => $evidence->occurredAt()->format('Y-m'),
            'reversal_of' => $reversalOf,
            'trace_id' => $traceId,
        ]);
        $refundSource = $intentId.':refund:'.$evidence->providerEventId();
        $this->repository->insert('ledger_entries', $refundSource.':contra_income', [
            'transaction_id' => $transactionId,
            'account' => 'contra_income.donation_refund',
            'direction' => 'debit',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $refundSource.':contra_income',
        ]);
        $this->repository->insert('ledger_entries', $refundSource.':asset', [
            'transaction_id' => $transactionId,
            'account' => 'asset.provider_clearing',
            'direction' => 'credit',
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'source_ref' => $refundSource.':asset',
        ]);

        $cumulativeRefunded = $alreadyRefunded + $amount->minorUnits();
        $fullRefund = $cumulativeRefunded === $originalAmount;
        $donationId = DonationCheckoutService::donationIdForIntent($intentId);
        $donation = $this->repository->get('donations', $donationId);
        if ($donation === null
            || (bool)($donation['recurring'] ?? false) !== false
            || !in_array((string)($donation['state'] ?? ''), ['settled', 'partially_refunded'], true)
        ) {
            throw new InvariantViolation('Canonical one-time donation aggregate is unavailable for refund posting.');
        }
        $this->repository->compareAndSwap(
            'donations',
            $donationId,
            (int)$donation['version'],
            static function (array $current) use ($fullRefund, $now): array {
                $current['state'] = $fullRefund ? 'refunded' : 'partially_refunded';
                $current['updated_at'] = $now;
                return $current;
            }
        );

        $this->outbox('DonationRefunded', $intentId, $aggregateVersion, $traceId, [
            'actor_ref' => $intent['actor_ref'],
            'intent_id' => $intentId,
            'donation_id' => $donationId,
            'refund_id' => $refundId,
            'amount_minor' => $amount->minorUnits(),
            'cumulative_refunded_minor' => $cumulativeRefunded,
            'full_refund' => $fullRefund,
            'currency' => $amount->currency(),
            'donation_type' => 'one_time',
            'recurring' => false,
            'no_access_event' => true,
            'occurred_at' => $evidence->occurredAt()->format(DATE_ATOM),
        ], $now);
    }

    /** @param array<string,mixed> $intent */
    private function postSafeStatusFact(
        string $eventType,
        array $intent,
        ProviderEvidence $evidence,
        string $traceId,
        int $aggregateVersion
    ): void {
        $this->outbox($eventType, (string)$intent['intent_id'], $aggregateVersion, $traceId, [
            'actor_ref' => $intent['actor_ref'],
            'intent_id' => $intent['intent_id'],
            'product_id' => 'donation.one_time',
            'donation_type' => 'one_time',
            'recurring' => false,
            'amount_minor' => $evidence->amount()->minorUnits(),
            'currency' => $evidence->amount()->currency(),
            'safe_reason_code' => strtolower($eventType),
            'no_access_event' => true,
            'occurred_at' => $evidence->occurredAt()->format(DATE_ATOM),
        ], $evidence->receivedAt());
    }

    /** @param array<string,mixed> $payload */
    private function outbox(
        string $type,
        string $aggregateId,
        int $version,
        string $traceId,
        array $payload,
        DateTimeImmutable $now
    ): void {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Financial outbox payload could not be encoded.', 0, $error);
        }
        $eventId = 'event.'.substr(hash('sha256', $type.'|'.$aggregateId.'|'.$version.'|'.$encoded), 0, 40);
        $this->repository->insert('outbox', $eventId, [
            'event_id' => $eventId,
            'event_type' => $type,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => (string)$version,
            'schema_version' => '1.1',
            'trace_id' => $traceId,
            'payload_json' => $payload,
            'payload_hash' => hash('sha256', $encoded),
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'leased_until' => null,
            'last_error_code' => null,
            'created_at' => $now,
            'delivered_at' => null,
        ]);
    }

    private static function date(mixed $value, string $label): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) { return $value; }
        if (!is_string($value) || $value === '') { throw new InvariantViolation($label.' is missing.'); }
        return new DateTimeImmutable($value);
    }
}
