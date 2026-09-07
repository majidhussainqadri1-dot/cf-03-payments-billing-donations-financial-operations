<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;

final class RuntimeSchemaExtension
{
    public const VERSION = '4.0.0';

    /** @var list<string> */
    public const RETIRED_TABLES = [
        'recurring_consents',
        'subscriptions',
        'usage_authorizations',
        'usage_facts',
    ];

    /** @param array<string,string> $tables @return array<string,string> */
    public static function apply(array $tables): array
    {
        // These tables are intentionally removed from the active canonical schema.
        // Existing installations may retain physical legacy tables for bounded
        // audit/reconciliation retention, but CF-03 v4 must not create or address
        // them as active runtime truth.
        foreach (self::RETIRED_TABLES as $retired) {
            unset($tables[$retired]);
        }

        foreach ([
            'ledger_entries',
            'reconciliation_exceptions',
            'exports',
            'settlements',
            'finance_periods',
            'audit',
            'adjustments',
            'transparency_snapshots',
            'donations',
            'intents',
            'refunds',
            'chargebacks',
            'outbox',
        ] as $required) {
            if (!isset($tables[$required])) {
                throw new RuntimeException('CF-03 runtime schema extension is missing '.$required.'.');
            }
        }

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

        // Legacy recurring columns remain nullable/false in the donation table for
        // backward-readable historical rows only. New code always writes false/null.
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
