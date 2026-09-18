<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressFinancialRepository;

final class Round13Wpdb
{
    public string $lastSql = '';

    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_row(string $sql, string $mode = 'ARRAY_A'): ?array { $this->lastSql = $sql; return null; }
    public function get_results(string $sql, string $mode = 'ARRAY_A'): array { $this->lastSql = $sql; return []; }
    public function get_col(string $sql, int $column = 0): array
    {
        $this->lastSql = $sql;
        if (str_contains($sql, 'sabri_cf03_audit_events')) {
            return ['id','audit_id','actor_ref','purpose','action','outcome','trace_id','metadata_json','metadata_hash','previous_hash','entry_hash','created_at'];
        }
        return ['id'];
    }
    public function insert(string $table, array $data): int { return 1; }
    public function update(string $table, array $data, array $where): int { return 1; }
    public function delete(string $table, array $where): int { return 1; }
    public function query(string $sql): int|false { $this->lastSql = $sql; return 1; }
}

$wpdb = new Round13Wpdb();
$repo = new WordPressFinancialRepository($wpdb, 'wp_');
$repo->page('audit', [], 20, 0);

if (!str_contains($wpdb->lastSql, 'ORDER BY id ASC')) {
    throw new RuntimeException('Audit paging must follow physical append order, not hash-like audit_id order.');
}
if (str_contains($wpdb->lastSql, 'ORDER BY audit_id')) {
    throw new RuntimeException('Audit paging regressed to audit_id lexical ordering.');
}

fwrite(STDOUT, "PASS: audit repository paging preserves physical append order for hash-chain reconstruction\n");
