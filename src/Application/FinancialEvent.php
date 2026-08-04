<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;

final class FinancialEvent
{
    private const TYPES = [
        'PaymentIntentCreated','PaymentAuthorized','PaymentSettled','PaymentFailed','PaymentCancelled',
        'SubscriptionActivated','SubscriptionPastDue','SubscriptionGraceStarted','SubscriptionCancelled','SubscriptionExpired',
        'InvoiceIssued','ReceiptIssued','LedgerTransactionPosted','RefundRequested','RefundApproved','RefundSucceeded','RefundFailed','RefundReconciled',
        'ChargebackOpened','ChargebackWon','ChargebackLost','SettlementImported','FinancePeriodClosed','DonationSettled','DonationRefunded',
        'ProviderEventQuarantined','PaymentProviderDegraded','FinanceReconciliationFailed'
    ];

    /** @param array<string,scalar|null> $payload */
    public function __construct(
        private readonly string $eventId,
        private readonly string $type,
        private readonly string $aggregateId,
        private readonly array $payload,
        private readonly int $occurredAt
    ) {
        if (trim($eventId) === '' || trim($aggregateId) === '' || $occurredAt <= 0) {
            throw new InvalidArgumentException('Financial event identity is invalid.');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown financial event type.');
        }
        foreach (array_keys($payload) as $key) {
            if (preg_match('/(?:pan|cvv|pin|otp|password|secret|token|raw_body|full_card|bank_credential)/i', (string) $key)) {
                throw new InvalidArgumentException('Sensitive financial event field is forbidden.');
            }
        }
    }

    public function eventId(): string { return $this->eventId; }
    public function type(): string { return $this->type; }
    public function aggregateId(): string { return $this->aggregateId; }
    /** @return array<string,scalar|null> */ public function payload(): array { return $this->payload; }
    public function occurredAt(): int { return $this->occurredAt; }
}
