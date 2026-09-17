<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;
use Sabri\CF03\Support\InvariantViolation;

final class RuntimeSchemaExtension
{
    public const VERSION = '4.0.2';

    /** @var list<string> */
    public const RETIRED_TABLES = [
        'recurring_consents',
        'subscriptions',
        'usage_authorizations',
        'usage_facts',
    ];

    public static function isRetiredCollection(string $collection): bool
    {
        return in_array($collection, self::RETIRED_TABLES, true);
    }

    public static function assertActiveCollection(string $collection): void
    {
        if (self::isRetiredCollection($collection)) {
            throw new InvariantViolation(
                'Retired financial collection is not addressable as active CF-03 v4 runtime truth: '.$collection.'.'
            );
        }
    }

    /** @param array<string,string> $tables @return array<string,string> */
    public static function apply(array $tables): array
    {
        foreach (self::RETIRED_TABLES as $retired) {
            unset($tables[$retired]);
        }

        foreach ([
            'customer_refs','provider_events','ledger_entries','reconciliation_exceptions','exports',
            'settlements','settlement_lines','finance_periods','audit','adjustments','retention_ledger',
            'transparency_snapshots','donations','intents','refunds','chargebacks','idempotency','outbox',
        ] as $required) {
            if (!isset($tables[$required])) {
                throw new RuntimeException('CF-03 runtime schema extension is missing '.$required.'.');
            }
        }

        // Every active collection addressed by repository get(collection,id) must
        // have a database-enforced unique single-column identity. Composite provider,
        // scope, batch or record-type keys may remain as additional integrity keys.
        $tables['customer_refs'] = self::replaceOnce(
            $tables['customer_refs'],
            'UNIQUE KEY provider_customer(provider,provider_customer_ref)',
            'UNIQUE KEY provider_customer(provider,provider_customer_ref), UNIQUE KEY provider_customer_ref(provider_customer_ref)'
        );
        $tables['provider_events'] = self::replaceOnce(
            $tables['provider_events'],
            'UNIQUE KEY provider_event(provider,provider_event_id)',
            'UNIQUE KEY provider_event(provider,provider_event_id), UNIQUE KEY provider_event_id(provider_event_id)'
        );
        $tables['settlements'] = self::replaceOnce(
            $tables['settlements'],
            'UNIQUE KEY provider_batch(provider,batch_id)',
            'UNIQUE KEY provider_batch(provider,batch_id), UNIQUE KEY batch_id(batch_id)'
        );
        $tables['settlement_lines'] = self::replaceOnce(
            $tables['settlement_lines'],
            'KEY line_ref(line_ref)',
            'UNIQUE KEY line_ref(line_ref)'
        );
        $tables['idempotency'] = self::replaceOnce(
            $tables['idempotency'],
            'UNIQUE KEY scope_key(scope,idempotency_key)',
            'UNIQUE KEY scope_key(scope,idempotency_key), UNIQUE KEY idempotency_key(idempotency_key)'
        );
        $tables['retention_ledger'] = self::replaceOnce(
            $tables['retention_ledger'],
            'UNIQUE KEY record_once(record_type,record_ref)',
            'UNIQUE KEY record_once(record_type,record_ref), UNIQUE KEY record_ref(record_ref)'
        );

        $tables['ledger_entries'] = self::replaceOnce(
            $tables['ledger_entries'],
            'KEY source_ref(source_ref)',
            'UNIQUE KEY source_once(source_ref)'
        );
        $tables['reconciliation_exceptions'] = self::replaceOnce(
            $tables['reconciliation_exceptions'],
            'accepted_risk_ref varchar(191) NULL, created_at',
            'accepted_risk_ref varchar(191) NULL, resolution_ref varchar(191) NULL, created_at'
        );
        $tables['exports'] = self::replaceOnce(
            $tables['exports'],
            'specification_hash char(64) NOT NULL, maximum_rows',
            'specification_hash char(64) NOT NULL, specification_json longtext NOT NULL, maximum_rows'
        );
        $tables['settlements'] = self::replaceOnce(
            $tables['settlements'],
            'imported_at datetime(6) NOT NULL, status',
            'imported_at datetime(6) NOT NULL, imported_by varchar(191) NOT NULL, posted_by varchar(191) NULL, posted_at datetime(6) NULL, status'
        );
        $tables['finance_periods'] = self::replaceOnce(
            $tables['finance_periods'],
            'reviewed_by varchar(191) NULL, approved_by',
            'reviewed_by varchar(191) NULL, reviewed_at datetime(6) NULL, approved_by'
        );
        $tables['audit'] = self::replaceOnce(
            $tables['audit'],
            'UNIQUE KEY entry_hash(entry_hash), KEY trace_id',
            'UNIQUE KEY entry_hash(entry_hash), UNIQUE KEY previous_hash(previous_hash), KEY trace_id'
        );
        $tables['adjustments'] = self::replaceOnce(
            $tables['adjustments'],
            'currency char(3) NOT NULL, reason_code',
            'currency char(3) NOT NULL, debit_account varchar(128) NOT NULL, credit_account varchar(128) NOT NULL, reason_code'
        );
        $tables['retention_ledger'] = self::replaceOnce(
            $tables['retention_ledger'],
            'legal_hold_ref varchar(191) NULL, actioned_at datetime(6) NULL',
            "legal_hold_ref varchar(191) NULL, action_state varchar(32) NOT NULL DEFAULT 'pending', action_evidence_ref varchar(191) NULL, actioned_at datetime(6) NULL"
        );

        return $tables;
    }

    private static function replaceOnce(string $sql, string $search, string $replacement): string
    {
        if (substr_count($sql, $search) !== 1) {
            throw new RuntimeException('CF-03 runtime schema extension could not apply a deterministic migration.');
        }
        return str_replace($search, $replacement, $sql);
    }
}
