<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

use RuntimeException;

final class RuntimeSchemaExtension
{
    public const VERSION = '3.1.0';

    /** @param array<string,string> $tables @return array<string,string> */
    public static function apply(array $tables): array
    {
        foreach (['recurring_consents', 'ledger_entries', 'reconciliation_exceptions', 'exports'] as $required) {
            if (!isset($tables[$required])) {
                throw new RuntimeException('CF-03 runtime schema extension is missing '.$required.'.');
            }
        }

        $tables['recurring_consents'] = self::replaceOnce(
            $tables['recurring_consents'],
            'revoked_at datetime(6) NULL, PRIMARY KEY',
            'revoked_at datetime(6) NULL, record_version bigint unsigned NOT NULL DEFAULT 1, PRIMARY KEY'
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
