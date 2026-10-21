<?php

declare(strict_types=1);

namespace Sabri\CF03\Integration;

use Sabri\CF03\Domain\FinancialTransparencySnapshot;
use Sabri\CF03\Support\InvariantViolation;

/**
 * CF05-FR-018: optional, aggregate-only finance metric contract.
 * CF-03 remains the sole ledger owner. CF-05 is an advisory consumer,
 * never a raw financial record reader or a payment decision-maker.
 * This pure projection creates no route, events, runtime or activation.
 */
final class CF05FinancialAggregate
{
    public const CONTRACT_VERSION = 'CF03-CF05-AGG-v1';

    /**
     * Read only an explicitly published, canonical transparency snapshot.
     * No donor/customer/transaction/refund/receipt/PII fields are exported.
     *
     * @param array<string,mixed> $publishedRow
     * @return array<string,mixed>
     */
    public static function fromPublishedSnapshot(array $publishedRow): array
    {
        if (($publishedRow['publication_state'] ?? null) !== 'published') {
            throw new InvariantViolation('CF-05 finance aggregates require a published CF-03 snapshot.');
        }
        $snapshot = $publishedRow['snapshot_json'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvariantViolation('CF-05 aggregate source is not a structured canonical snapshot.');
        }
        $sourceHash = $publishedRow['source_hash'] ?? null;
        $snapshotHash = $publishedRow['snapshot_hash'] ?? null;
        if (!is_string($sourceHash)
            || preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1
            || !is_string($snapshotHash)
            || preg_match('/^[a-f0-9]{64}$/', $snapshotHash) !== 1
            || ($snapshot['source_hash'] ?? null) !== $sourceHash
        ) {
            throw new InvariantViolation('CF-05 snapshot provenance or source hash is incomplete.');
        }

        // Require the exact published aggregate bytes, not merely a plausible
        // supplied hash. A derivative analytics consumer cannot authenticate
        // or silently repair corrupted financial source truth.
        if (!hash_equals($snapshotHash, hash('sha256', self::canonicalJson($snapshot)))) {
            throw new InvariantViolation('CF-05 financial aggregate snapshot hash mismatch.');
        }
        $projection = FinancialTransparencySnapshot::fromArray($snapshot)->toPublicProjection();
        foreach (['total_expenses_minor', 'current_balance_minor', 'founder_paid_or_withdrawn_minor'] as $field) {
            if (!is_int($snapshot[$field] ?? null) || $snapshot[$field] !== $projection[$field]) {
                throw new InvariantViolation('CF-05 financial aggregate declared totals do not reconcile.');
            }
        }
        if (($publishedRow['snapshot_id'] ?? null) !== $projection['snapshot_id']
            || ($publishedRow['currency'] ?? null) !== $projection['currency']
            || !is_string($publishedRow['period_key'] ?? null)
            || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $publishedRow['period_key']) !== 1
        ) {
            throw new InvariantViolation('CF-05 financial aggregate snapshot identity or period is inconsistent.');
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source_owner' => 'CF-03',
            'consumer_owner' => 'CF-05',
            'source_snapshot_id' => $projection['snapshot_id'],
            'source_snapshot_hash' => $snapshotHash,
            'source_currency' => $projection['currency'],
            'period_key' => $publishedRow['period_key'],
            'as_of' => $projection['as_of'],
            'last_financial_update_at' => $projection['last_financial_update_at'],
            'metrics_minor_units' => [
                'net_donations_lifetime' => $projection['total_donations_minor'],
                'net_donations_period' => $projection['current_month_donations_minor'],
                'net_donations_year' => $projection['current_year_donations_minor'],
                'approved_expenses_lifetime' => $projection['total_expenses_minor'],
                'current_balance' => $projection['current_balance_minor'],
            ],
            'individual_records_included' => false,
            'financial_decision_authority' => false,
        ];
    }

    /** Deterministic publication encoding matches ExpenseTransparencyService. */
    private static function canonicalJson(array $payload): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $value) { $item[$key] = $sort($value); }
            return $item;
        };
        try {
            return json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $error) {
            throw new InvariantViolation('CF-05 aggregate source is not canonically serializable.', 0, $error);
        }
    }
}
