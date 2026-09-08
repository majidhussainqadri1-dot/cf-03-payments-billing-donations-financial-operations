<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use JsonException;
use RuntimeException;
use Sabri\CF03\Persistence\CompleteSchema;

final class WordPressSchemaInstaller
{
    /** @return list<string> */
    public static function install(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix) || !is_string($wpdb->prefix)) {
            throw new RuntimeException('WordPress database connection is unavailable for CF-03 schema installation.');
        }
        if (preg_match('/^[A-Za-z0-9_]*$/', $wpdb->prefix) !== 1) {
            throw new RuntimeException('WordPress database prefix is unsafe for CF-03 schema installation.');
        }
        if (!function_exists('dbDelta')) {
            $upgrade = defined('ABSPATH') ? ABSPATH.'wp-admin/includes/upgrade.php' : '';
            if ($upgrade !== '' && is_file($upgrade)) {
                require_once $upgrade;
            }
        }
        if (!function_exists('dbDelta')) {
            throw new RuntimeException('WordPress dbDelta is unavailable for CF-03 schema installation.');
        }
        foreach (['get_var', 'get_col', 'get_results', 'prepare', 'query', 'update'] as $method) {
            if (!method_exists($wpdb, $method)) {
                throw new RuntimeException('WordPress database verification method is unavailable: '.$method.'.');
            }
        }

        $tables = CompleteSchema::tables($wpdb->prefix);
        $applied = [];
        $defects = [];
        foreach ($tables as $name => $sql) {
            dbDelta($sql);
            if (preg_match('/^CREATE TABLE\s+([^\s(]+)/i', $sql, $matches) !== 1) {
                throw new RuntimeException('CF-03 schema statement does not expose a verifiable table name: '.$name.'.');
            }
            $table = $matches[1];
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('CF-03 schema exposed an unsafe table identifier.');
            }
            $pattern = method_exists($wpdb, 'esc_like')
                ? $wpdb->esc_like($table)
                : addcslashes($table, '_%\\');
            $query = $wpdb->prepare('SHOW TABLES LIKE %s', $pattern);
            $found = $wpdb->get_var($query);
            if (!is_string($found) || $found !== $table) {
                $defects[] = $table.':missing_table';
                continue;
            }

            if ($name === 'transparency_snapshots') {
                self::repairTransparencySnapshots($wpdb, $table);
            }

            $actualColumns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            if (!is_array($actualColumns)) {
                $defects[] = $table.':columns_unreadable';
                continue;
            }
            $missingColumns = array_values(array_diff(self::requiredColumns($sql), $actualColumns));
            if ($missingColumns !== []) {
                $defects[] = $table.':missing_columns='.implode('|', $missingColumns);
                continue;
            }

            $indexDefects = self::indexDefects($wpdb, $table, $sql);
            if ($indexDefects !== []) {
                $defects[] = $table.':'.implode('|', $indexDefects);
                continue;
            }
            $applied[] = 'cf03-'.CompleteSchema::VERSION.'-'.$name;
        }

        if ($defects !== []) {
            throw new RuntimeException('CF-03 schema verification failed: '.implode(', ', $defects).'.');
        }
        if (count($applied) !== count($tables)) {
            throw new RuntimeException('CF-03 schema installation did not verify every canonical table.');
        }
        return $applied;
    }

    /** @return list<string> */
    public static function requiredColumns(string $sql): array
    {
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        if ($open === false || $close === false || $close <= $open) {
            throw new RuntimeException('CF-03 schema SQL cannot be parsed for column verification.');
        }

        $segments = self::splitTopLevel(substr($sql, $open + 1, $close - $open - 1));
        $columns = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|CONSTRAINT|FULLTEXT|SPATIAL)\b/i', $segment) === 1) {
                continue;
            }
            if (preg_match('/^`?([a-z][a-z0-9_]*)`?\s+/i', $segment, $match) !== 1) {
                throw new RuntimeException('CF-03 schema contains an unparseable column definition.');
            }
            $columns[] = strtolower($match[1]);
        }
        return array_values(array_unique($columns));
    }

    /** @return array<string,array{unique:bool,columns:list<string>}> */
    public static function requiredIndexes(string $sql): array
    {
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        if ($open === false || $close === false || $close <= $open) {
            throw new RuntimeException('CF-03 schema SQL cannot be parsed for index verification.');
        }
        $segments = self::splitTopLevel(substr($sql, $open + 1, $close - $open - 1));
        $indexes = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $segment, $match) === 1) {
                $indexes['PRIMARY'] = ['unique' => true, 'columns' => self::indexColumns($match[1])];
                continue;
            }
            if (preg_match('/^(UNIQUE\s+)?KEY\s+`?([a-z][a-z0-9_]*)`?\s*\((.+)\)$/i', $segment, $match) === 1) {
                $indexes[$match[2]] = [
                    'unique' => trim((string)$match[1]) !== '',
                    'columns' => self::indexColumns($match[3]),
                ];
            }
        }
        if (!isset($indexes['PRIMARY'])) {
            throw new RuntimeException('CF-03 schema table has no verifiable primary key.');
        }
        return $indexes;
    }

    /** @return list<string> */
    private static function indexDefects(object $wpdb, string $table, string $sql): array
    {
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows)) {
            return ['indexes_unreadable'];
        }
        $actual = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return ['index_row_invalid'];
            }
            $name = $row['Key_name'] ?? $row['key_name'] ?? null;
            $column = $row['Column_name'] ?? $row['column_name'] ?? null;
            $nonUnique = $row['Non_unique'] ?? $row['non_unique'] ?? null;
            $sequence = $row['Seq_in_index'] ?? $row['seq_in_index'] ?? null;
            if (!is_string($name) || !is_string($column) || !is_numeric($nonUnique) || !is_numeric($sequence)) {
                return ['index_evidence_invalid'];
            }
            $actual[$name]['unique'] = (int)$nonUnique === 0;
            $actual[$name]['columns'][(int)$sequence] = strtolower($column);
        }
        foreach ($actual as &$definition) {
            ksort($definition['columns']);
            $definition['columns'] = array_values($definition['columns']);
        }
        unset($definition);

        $defects = [];
        foreach (self::requiredIndexes($sql) as $name => $required) {
            if (!isset($actual[$name])) {
                $defects[] = 'missing_index='.$name;
                continue;
            }
            if ($actual[$name]['unique'] !== $required['unique']) {
                $defects[] = 'index_uniqueness='.$name;
            }
            if ($actual[$name]['columns'] !== $required['columns']) {
                $defects[] = 'index_columns='.$name;
            }
        }
        return $defects;
    }

    private static function repairTransparencySnapshots(object $wpdb, string $table): void
    {
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($indexes)) {
            throw new RuntimeException('Transparency snapshot indexes could not be read.');
        }
        $hasLegacyPeriodIndex = false;
        foreach ($indexes as $row) {
            if (is_array($row) && ($row['Key_name'] ?? $row['key_name'] ?? null) === 'period_key') {
                $hasLegacyPeriodIndex = true;
                break;
            }
        }
        if ($hasLegacyPeriodIndex && $wpdb->query("ALTER TABLE {$table} DROP INDEX `period_key`") === false) {
            throw new RuntimeException('Legacy transparency period-only uniqueness could not be removed.');
        }

        $rows = $wpdb->get_results(
            "SELECT id, snapshot_json, source_hash, snapshot_hash FROM {$table}",
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        if (!is_array($rows)) {
            throw new RuntimeException('Transparency snapshot rows could not be read for integrity migration.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_string($row['snapshot_json'] ?? null)
                || !is_string($row['source_hash'] ?? null)
            ) {
                throw new RuntimeException('Transparency snapshot migration encountered invalid row evidence.');
            }
            try {
                $decoded = json_decode($row['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Transparency snapshot migration found corrupt JSON.', 0, $error);
            }
            if (!is_array($decoded)
                || preg_match('/^[a-f0-9]{64}$/', $row['source_hash']) !== 1
                || ($decoded['source_hash'] ?? null) !== $row['source_hash']
            ) {
                throw new RuntimeException('Transparency snapshot source evidence failed migration verification.');
            }
            $canonical = self::canonicalJson($decoded);
            $expectedHash = hash('sha256', $canonical);
            if (($row['snapshot_hash'] ?? null) === $expectedHash) {
                continue;
            }
            $updated = $wpdb->update($table, ['snapshot_hash' => $expectedHash], ['id' => (int)$row['id']]);
            if ($updated !== 1) {
                throw new RuntimeException('Transparency snapshot integrity hash could not be backfilled.');
            }
        }
    }

    /** @return list<string> */
    private static function indexColumns(string $body): array
    {
        $columns = [];
        foreach (explode(',', $body) as $column) {
            $column = trim($column);
            if (preg_match('/^`?([a-z][a-z0-9_]*)`?(?:\([0-9]+\))?(?:\s+(?:ASC|DESC))?$/i', $column, $match) !== 1) {
                throw new RuntimeException('CF-03 schema contains an unparseable index column.');
            }
            $columns[] = strtolower($match[1]);
        }
        return $columns;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $body): array
    {
        $segments = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $char = $body[$index];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($index === 0 || $body[$index - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new RuntimeException('CF-03 schema contains unbalanced parentheses.');
                }
                $buffer .= $char;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                $segments[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('CF-03 schema contains unbalanced quotes or parentheses.');
        }
        if (trim($buffer) !== '') {
            $segments[] = $buffer;
        }
        return $segments;
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
            throw new RuntimeException('Transparency snapshot migration could not canonicalize JSON.', 0, $error);
        }
    }
}
