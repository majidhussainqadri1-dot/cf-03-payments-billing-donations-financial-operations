<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

if (!extension_loaded('mysqli')) {
    throw new RuntimeException('The real MariaDB refund-lock integration test requires mysqli.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('CF03_TEST_DB_HOST') ?: '';
$user = getenv('CF03_TEST_DB_USER') ?: '';
$password = getenv('CF03_TEST_DB_PASS') ?: '';
$database = getenv('CF03_TEST_DB_NAME') ?: '';
if ($host === '' || $user === '' || $database !== 'cf03_test') {
    throw new RuntimeException('Isolated financial test database configuration is missing.');
}

final class CF03RealWpdbAdapter
{
    public readonly string $prefix;
    public function __construct(public readonly mysqli $db, string $prefix)
    {
        $this->prefix = $prefix;
    }
    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }
    public function prepare(string $statement, mixed ...$arguments): string
    {
        $offset = 0;
        $prepared = preg_replace_callback('/%[sd]/', function (array $match) use (&$offset, $arguments): string {
            if (!array_key_exists($offset, $arguments)) {
                throw new RuntimeException('Missing SQL placeholder argument.');
            }
            $value = $arguments[$offset++];
            return $match[0] === '%d'
                ? (string)(int)$value
                : "'".$this->db->real_escape_string((string)$value)."'";
        }, $statement);
        if (!is_string($prepared) || $offset !== count($arguments)) {
            throw new RuntimeException('Invalid SQL placeholder structure.');
        }
        return $prepared;
    }
    public function get_row(string $query, mixed $format = null): ?array
    {
        $result = $this->db->query($query);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Database query did not return a row set.');
        }
        return $result->fetch_assoc();
    }
    public function get_results(string $query, mixed $format = null): array
    {
        $result = $this->db->query($query);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Database query did not return a row set.');
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    public function get_col(string $query, int $column = 0): array
    {
        $result = $this->db->query($query);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Database query did not return a column.');
        }
        $values = [];
        foreach ($result->fetch_all(MYSQLI_NUM) as $row) {
            $values[] = $row[$column] ?? null;
        }
        return $values;
    }
    public function query(string $query): int|false
    {
        return $this->db->query($query) === true ? $this->db->affected_rows : false;
    }
    public function insert(mixed ...$arguments): int { throw new RuntimeException('Not used in lock-only test.'); }
    public function update(mixed ...$arguments): int { throw new RuntimeException('Not used in lock-only test.'); }
    public function delete(mixed ...$arguments): int { throw new RuntimeException('Not used in lock-only test.'); }
}

$connections = [];
for ($i=0; $i<2; $i++) {
    $conn = new mysqli($host, $user, $password, $database, 3306);
    $conn->set_charset('utf8mb4');
    $conn->query('SET SESSION innodb_lock_wait_timeout = 1');
    $connections[] = $conn;
}
[$first, $second] = $connections;
$innoDb = 'ci_sabri_cf03_payment_intents';
$myisam = 'ci_old_sabri_cf03_payment_intents';
$check = 0;
$assert = static function (bool $yes, string $label) use (&$check): void {
    $check++;
    if (!$yes) { throw new RuntimeException('FAIL: '.$label); }
};
$schema = static function (string $table, string $engine): string {
    return "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        intent_id VARCHAR(128) NOT NULL UNIQUE,
        actor_ref VARCHAR(128) NOT NULL,
        state VARCHAR(32) NOT NULL,
        amount_minor BIGINT NOT NULL,
        currency CHAR(3) NOT NULL,
        record_version INT NOT NULL
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4";
};

try {
    $first->query("DROP TABLE IF EXISTS {$innoDb}");
    $first->query("DROP TABLE IF EXISTS {$myisam}");
    $first->query($schema($innoDb, 'InnoDB'));
    $first->query($schema($myisam, 'MyISAM'));
    $first->query("INSERT INTO {$innoDb}
       (intent_id,actor_ref,state,amount_minor,currency,record_version)
       VALUES ('intent:ci:1','user:100','settled',500,'USD',1)");
    $first->query("INSERT INTO {$myisam}
       (intent_id,actor_ref,state,amount_minor,currency,record_version)
       VALUES ('intent:ci:1','user:100','settled',500,'USD',1)");

    $repo1 = new WordPressFinancialRepository(new CF03RealWpdbAdapter($first, 'ci_'), 'ci_');
    $repo2 = new WordPressFinancialRepository(new CF03RealWpdbAdapter($second, 'ci_'), 'ci_');
    $legacy = new WordPressFinancialRepository(new CF03RealWpdbAdapter($first, 'ci_old_'), 'ci_old_');

    $blocked = false;
    try { $repo1->getForUpdate('intents', 'intent:ci:1'); }
    catch (InvariantViolation) { $blocked = true; }
    $assert($blocked, 'Actual adapter denies a row-lock read outside a transaction');

    $blocked = false;
    try {
        $legacy->transaction(static fn() => $legacy->getForUpdate('intents', 'intent:ci:1'));
    } catch (InvariantViolation) {
        $blocked = true;
    }
    $assert($blocked, 'Real MyISAM engine is rejected before monetary reservation');

    // Keep two independent real database transactions simultaneously open.
    $lockTimedOut = false;
    $holder = $repo1->transaction(function () use ($repo1, $second, $innoDb, &$lockTimedOut) {
        $locked = $repo1->getForUpdate('intents', 'intent:ci:1');
        $second->begin_transaction();
        try {
            $second->query("SELECT intent_id FROM {$innoDb}
                WHERE intent_id = 'intent:ci:1' FOR UPDATE");
        } catch (mysqli_sql_exception $error) {
            $lockTimedOut = $error->getCode() === 1205;
        } finally {
            $second->rollback();
        }
        return $locked;
    });
    $assert(($holder['intent_id'] ?? null) === 'intent:ci:1',
        'Application repository obtains the canonical InnoDB row');
    $assert(($holder['version'] ?? null) === 1,
        'Application repository hydration retains its canonical version');
    $assert($lockTimedOut, 'Second real database connection cannot acquire the same row lock concurrently');

    $afterCommit = $repo2->transaction(
        static fn() => $repo2->getForUpdate('intents', 'intent:ci:1')
    );
    $assert(($afterCommit['intent_id'] ?? null) === 'intent:ci:1',
        'Second worker acquires the lock after first worker commits');

    // A rejected financial transaction must not commit partial row changes.
    $rollbackSeen = false;
    try {
        $repo1->transaction(function () use ($first, $repo1, $innoDb): void {
            $repo1->getForUpdate('intents', 'intent:ci:1');
            $first->query("UPDATE {$innoDb} SET amount_minor=1 WHERE intent_id='intent:ci:1'");
            throw new RuntimeException('Simulated financial decision failure');
        });
    } catch (RuntimeException $error) {
        $rollbackSeen = str_contains($error->getMessage(), 'Simulated');
    }
    $assert($rollbackSeen, 'Simulated financial decision throws through repository rollback');
    $persisted = $first->query("SELECT amount_minor FROM {$innoDb} WHERE intent_id='intent:ci:1'");
    $row = $persisted instanceof mysqli_result ? $persisted->fetch_assoc() : null;
    $assert((int)($row['amount_minor'] ?? 0) === 500,
        'Actual InnoDB transaction rollback restores original financial amount');
} finally {
    foreach ($connections as $conn) {
        try { $conn->rollback(); } catch (Throwable) {}
    }
    try { $first->query("DROP TABLE IF EXISTS {$innoDb}"); } catch (Throwable) {}
    try { $first->query("DROP TABLE IF EXISTS {$myisam}"); } catch (Throwable) {}
    foreach ($connections as $conn) {
        try { $conn->close(); } catch (Throwable) {}
    }
}

fwrite(STDOUT, "CF-03 isolated MariaDB lock/engine/rollback: {$check}/{$check} real-DB checks passed; production DB remains unverified.\n");
