<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use JsonException;
use Sabri\CF03\Domain\FinancialTransparencySnapshot;
use Sabri\CF03\Support\InvariantViolation;

final class WordPressTransparencyRepository
{
    public function latestPublished(): ?FinancialTransparencySnapshot
    {
        global $wpdb;
        if (!isset($wpdb)
            || !is_object($wpdb)
            || !isset($wpdb->prefix)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_row')
        ) {
            return null;
        }

        $table = (string)$wpdb->prefix.'sabri_cf03_transparency_snapshots';
        $sql = $wpdb->prepare(
            "SELECT snapshot_json, source_hash, snapshot_hash FROM {$table} WHERE publication_state = %s ORDER BY published_at DESC, id DESC LIMIT 1",
            'published'
        );
        $row = $wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($row)) {
            return null;
        }
        try {
            $json = $row['snapshot_json'] ?? null;
            $sourceHash = $row['source_hash'] ?? null;
            $snapshotHash = $row['snapshot_hash'] ?? null;
            if (!is_string($json)
                || !is_string($sourceHash)
                || !is_string($snapshotHash)
                || preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $snapshotHash) !== 1
            ) {
                throw new InvariantViolation('Published transparency snapshot integrity evidence is invalid.');
            }
            $record = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($record)
                || ($record['source_hash'] ?? null) !== $sourceHash
                || !hash_equals($snapshotHash, hash('sha256', self::canonicalJson($record)))
            ) {
                throw new InvariantViolation('Published transparency snapshot failed integrity verification.');
            }
            return FinancialTransparencySnapshot::fromArray($record);
        } catch (JsonException $error) {
            throw new InvariantViolation('Published transparency snapshot JSON is corrupt.', 0, $error);
        }
    }

    private static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Transparency snapshot cannot be canonically encoded.', 0, $error);
        }
    }
}
