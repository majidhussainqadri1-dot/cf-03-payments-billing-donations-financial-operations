<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Domain\AuditEnvelope;

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
        if (! in_array($type, self::TYPES, true)) { throw new InvalidArgumentException('Unknown financial event type.'); }
        if ($type === 'DonationPrivilegeGranted') { throw new InvalidArgumentException('Donation privilege event is forbidden.'); }
        new AuditEnvelope($eventId, 'system', 'financial_event', $type, \Sabri\CF03\Domain\AuditOutcome::SUCCESS, $payload);
    }

    public function type(): string { return $this->type; }
    /** @return array<string,scalar|null> */ public function payload(): array { return $this->payload; }
}
