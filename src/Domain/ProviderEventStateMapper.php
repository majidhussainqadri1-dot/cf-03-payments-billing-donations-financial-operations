<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

final class ProviderEventStateMapper
{
    /** @var array<string,PaymentIntentState> */
    private const MAP = [
        'payment.pending' => PaymentIntentState::PROVIDER_PENDING,
        'payment.authorized' => PaymentIntentState::AUTHORIZED,
        'payment.captured' => PaymentIntentState::CAPTURED,
        'payment.settled' => PaymentIntentState::SETTLED,
        'payment.failed' => PaymentIntentState::FAILED,
        'payment.cancelled' => PaymentIntentState::CANCELLED,
        'payment.expired' => PaymentIntentState::EXPIRED,
        'payment.refunded' => PaymentIntentState::REFUNDED,
        'payment.disputed' => PaymentIntentState::DISPUTED,
    ];

    public function mapTrusted(ProviderEvidence $evidence, int $replayWindowSeconds = 300): PaymentIntentState
    {
        $evidence->assertTrusted($replayWindowSeconds);
        return self::MAP[$evidence->eventType()] ?? PaymentIntentState::QUARANTINED;
    }

    /**
     * Webhook ingestion owns durable event-id dedupe in provider_events. Exact signed
     * retries must therefore remain mappable even when an adapter reports the event
     * ID as previously seen; signature/replay authenticity still remains mandatory.
     */
    public function mapAuthenticForIdempotentIngestion(
        ProviderEvidence $evidence,
        int $replayWindowSeconds = 300
    ): PaymentIntentState {
        $evidence->assertAuthentic($replayWindowSeconds);
        return self::MAP[$evidence->eventType()] ?? PaymentIntentState::QUARANTINED;
    }
}
