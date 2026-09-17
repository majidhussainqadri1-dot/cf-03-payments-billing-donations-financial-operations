<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\PaymentIntent;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Support\InvariantViolation;

final class PaymentConfirmationService
{
    /**
     * @param callable(callable():FinancialEvent):FinancialEvent $transactional
     * @param callable(LedgerTransaction):void $ledgerWriter
     * @param callable(FinancialEvent):void $eventWriter
     */
    public function __construct(
        private readonly mixed $transactional,
        private readonly mixed $ledgerWriter,
        private readonly mixed $eventWriter
    ) {
        if (! is_callable($transactional) || ! is_callable($ledgerWriter) || ! is_callable($eventWriter)) {
            throw new InvalidArgumentException('Payment confirmation callbacks must be callable.');
        }
    }

    public function confirmSettled(
        PaymentIntent $intent,
        ProviderEvidence $evidence,
        LedgerTransaction $ledgerTransaction,
        int $expectedVersion,
        DateTimeImmutable $at,
        string $eventId
    ): FinancialEvent {
        $intent->assertProviderEvidence($evidence);
        if ($evidence->eventType() !== 'payment.settled') {
            throw new InvariantViolation('Payment settlement requires a normalized trusted payment.settled provider event.');
        }
        // Hosted checkout expiry stops new user interaction, but an already-authorized
        // provider payment can settle asynchronously. Keep this legacy confirmation
        // path identical to WebhookIngestionService: reject pre-intent evidence and
        // settlements more than 24 hours after hosted-intent expiry.
        if ($evidence->occurredAt() < $intent->createdAt()
            || $evidence->occurredAt() > $intent->expiresAt()->modify('+24 hours')
        ) {
            throw new InvariantViolation('Provider settlement is outside the trusted payment-intent settlement window.');
        }
        if ($at < $evidence->occurredAt()) {
            throw new InvariantViolation('Settlement recording time cannot precede the provider event occurrence time.');
        }
        $ledgerTransaction->assertBalanced();
        $ledgerTransaction->assertRepresents($intent->amount());

        return ($this->transactional)(function () use ($intent, $ledgerTransaction, $expectedVersion, $at, $eventId): FinancialEvent {
            $intent->transition(PaymentIntentState::SETTLED, $expectedVersion, $at);
            ($this->ledgerWriter)($ledgerTransaction);

            $event = new FinancialEvent(
                $eventId,
                'PaymentSettled',
                $intent->intentId(),
                [
                    'amount_minor' => $intent->amount()->minorUnits(),
                    'currency' => $intent->amount()->currency(),
                    'provider' => $intent->providerCode(),
                    'ledger_transaction_id' => $ledgerTransaction->transactionId(),
                    'record_version' => $intent->recordVersion(),
                ],
                $at->getTimestamp()
            );
            ($this->eventWriter)($event);

            return $event;
        });
    }
}
