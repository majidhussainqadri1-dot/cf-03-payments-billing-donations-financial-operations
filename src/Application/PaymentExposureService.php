<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class PaymentExposureService
{
    private const PAGE_SIZE = 200;
    private const MAX_ROWS_PER_DOMAIN = 10000;
    private const REFUND_COMMITTING_STATES = [
        'requested','approved','provider_pending','uncertain','succeeded','closed',
    ];

    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    public function refundExposure(string $intentId, string $currency): int
    {
        $total = 0;
        foreach ($this->paged('refunds', ['intent_id'=>$intentId]) as $record) {
            if (!in_array((string)($record['state'] ?? ''), self::REFUND_COMMITTING_STATES, true)) {
                continue;
            }
            $total = self::addAmount($total, $record, $currency, 'refund');
        }
        return $total;
    }

    public function chargebackExposure(string $intentId, string $currency): int
    {
        $total = 0;
        foreach ($this->paged('chargebacks', ['intent_id'=>$intentId]) as $record) {
            $state = (string)($record['state'] ?? '');
            $count = match ($state) {
                'notified','evidence_due','submitted','accepted','lost' => true,
                'won' => false,
                'ledger_adjusted','closed' => $this->completedChargebackLost($record),
                default => throw new InvariantViolation('Chargeback exposure contains an unknown state.'),
            };
            if ($count) {
                $total = self::addAmount($total, $record, $currency, 'chargeback');
            }
        }
        return $total;
    }

    public function combinedExposure(string $intentId, string $currency): int
    {
        $refund = $this->refundExposure($intentId, $currency);
        $chargeback = $this->chargebackExposure($intentId, $currency);
        if ($chargeback > PHP_INT_MAX - $refund) {
            throw new InvariantViolation('Combined refund and chargeback exposure exceeds supported integer range.');
        }
        return $refund + $chargeback;
    }

    /** @param array<string,mixed> $record */
    private function completedChargebackLost(array $record): bool
    {
        $caseId = (string)($record['case_id'] ?? '');
        if ($caseId === '') {
            throw new InvariantViolation('Completed chargeback exposure is missing its case identity.');
        }
        $transactionId = 'txn.chargeback.'.substr(hash('sha256', $caseId), 0, 32);
        $transaction = $this->repository->get('ledger_transactions', $transactionId);
        if ($transaction === null) {
            // A won zero-fee case legitimately creates no ledger transaction.
            return false;
        }
        return match ((string)($transaction['reason'] ?? '')) {
            'chargeback_lost' => true,
            'chargeback_won' => false,
            default => throw new InvariantViolation('Completed chargeback ledger outcome is ambiguous.'),
        };
    }

    /** @param array<string,mixed> $record */
    private static function addAmount(int $total, array $record, string $currency, string $label): int
    {
        if (($record['currency'] ?? null) !== $currency) {
            throw new InvariantViolation('Existing '.$label.' exposure changes the canonical payment currency.');
        }
        $amount = (int)($record['amount_minor'] ?? 0);
        if ($amount <= 0 || $amount > PHP_INT_MAX - $total) {
            throw new InvariantViolation('Existing '.$label.' exposure amount is invalid.');
        }
        return $total + $amount;
    }

    /** @param array<string,mixed> $criteria @return list<array<string,mixed>> */
    private function paged(string $collection, array $criteria): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $this->repository->page($collection, $criteria, self::PAGE_SIZE, $offset);
            foreach ($page as $record) { $rows[] = $record; }
            $offset += count($page);
            if ($offset > self::MAX_ROWS_PER_DOMAIN) {
                throw new InvariantViolation('Payment exposure history exceeds the safe automatic reconciliation bound.');
            }
        } while (count($page) === self::PAGE_SIZE);
        return $rows;
    }
}
