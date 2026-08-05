<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use RuntimeException;
use Sabri\CF03\Persistence\CompleteSchema;

final class WordPressSchemaInstaller
{
    /** @return list<string> */
    public static function install(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            throw new RuntimeException('WordPress database connection is unavailable for CF-03 schema installation.');
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
        foreach (['get_var', 'get_col', 'prepare'] as $method) {
            if (!method_exists($wpdb, $method)) {
                throw new RuntimeException('WordPress database verification method is unavailable: '.$method.'.');
            }
        }

        $tables = CompleteSchema::tables((string)$wpdb->prefix);
        $applied = [];
        $defects = [];
        foreach ($tables as $name => $sql) {
            dbDelta($sql);
            if (preg_match('/^CREATE TABLE\s+([^\s(]+)/i', $sql, $matches) !== 1) {
                throw new RuntimeException('CF-03 schema statement does not expose a verifiable table name: '.$name.'.');
            }
            $table = $matches[1];
            $query = $wpdb->prepare('SHOW TABLES LIKE %s', $table);
            $found = $wpdb->get_var($query);
            if (!is_string($found) || $found !== $table) {
                $defects[] = $table.':missing_table';
                continue;
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
            $columns[] = $match[1];
        }
        return array_values(array_unique($columns));
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
}
