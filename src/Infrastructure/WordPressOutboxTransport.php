<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Support\InvariantViolation;

/**
 * CF-03 remains the financial-fact owner. File 01 owns the durable platform
 * event backbone. A WordPress action listener is NOT a durable acknowledgement.
 * File 19 owns notification projection and user delivery separately.
 */
final class WordPressOutboxTransport implements OutboxTransport
{
    /** @deprecated Historical transient hook; never proof of delivery. */
    public const EVENT_HOOK = 'sabri_cf03_financial_fact';
    public const EVENT_PREFIX = 'CF03.';
    public const MIN_FILE01_VERSION = '2.0.1';
    public const MIN_FILE01_CONTRACT = '2.0.0';

    public static function backboneReady(): bool
    {
        return defined('SPF_VERSION')
            && defined('SPF_CONTRACT_VERSION')
            && version_compare((string)SPF_VERSION, self::MIN_FILE01_VERSION, '>=')
            && version_compare((string)SPF_CONTRACT_VERSION, self::MIN_FILE01_CONTRACT, '>=')
            && class_exists('SPF_Event_Bus')
            && is_callable(['SPF_Event_Bus', 'publish']);
    }

    public function publish(string $eventId, string $eventType, array $payload): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $eventId) !== 1
            || preg_match('/^[A-Z][A-Za-z0-9]*(?:\.v[1-9][0-9]*)?$/D', $eventType) !== 1
        ) {
            throw new InvariantViolation('Financial event identity is not a canonical versioned domain fact.');
        }
        if (!self::backboneReady()) {
            throw new InvariantViolation('Approved File 01 durable financial event backbone is unavailable.');
        }

        // Never copy private CF-03 outbox data into File 01. The entire
        // amount/receipt/donor/provider/intent payload is explicitly excluded.
        // Hash the source event ID as it may itself contain an actor reference.
        $opaque = hash('sha256', $eventId);
        $aggregate = 'fact.'.substr($opaque, 0, 48);
        // Existing CF-03 outbox facts are sometimes stored as bare names
        // (DonationSettled, DonationRefunded, FinancialAdjustmentExecuted).
        // File 01 publication is always explicitly versioned without rewriting
        // the original CF-03 accounting or idempotency evidence.
        $versionedType = preg_match('/\.v[1-9][0-9]*$/D', $eventType) === 1
            ? $eventType : $eventType.'.v1';
        $eventName = self::EVENT_PREFIX.$versionedType;
        $minimal = [
            'owner_module' => 'cf03',
            'source_event_hash' => $opaque,
            'source_event_type' => $eventType,
            'event_contract' => 'cf03.financial_fact.v1',
            'accounting_only' => true,
            'donor_privilege' => false,
            'access_signal' => false,
        ];

        // Verified File 01 2.0.1 source contract:
        // SPF_Event_Bus::publish(name, type, id, payload, version, dedupe, privacy).
        // true means durable insert OR durable identical dedupe receipt; it
        // does not mean downstream File 19 projection or final user delivery.
        $accepted = \SPF_Event_Bus::publish(
            $eventName,
            'financial_fact',
            $aggregate,
            $minimal,
            1,
            'cf03:'.$opaque,
            'restricted'
        );
        if ($accepted !== true) {
            throw new InvariantViolation('File 01 did not acknowledge durable financial fact acceptance.');
        }
    }
}
