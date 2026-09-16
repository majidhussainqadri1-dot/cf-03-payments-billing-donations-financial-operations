<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Application\RestoreReconciliation;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressFinancialRepository;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Persistence\MigrationRunner;
use Sabri\CF03\Persistence\RuntimeSchemaExtension;
use Sabri\CF03\Support\InvariantViolation;

final class Round5Wpdb
{
    public string $prefix = 'wp_';
    /** @var list<string> */ public array $queries = [];
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_row(string $sql, mixed $mode = null): mixed { $this->queries[]=$sql; return null; }
    public function get_results(string $sql, mixed $mode = null): array { $this->queries[]=$sql; return []; }
    public function get_col(string $sql, int $column = 0): array { $this->queries[]=$sql; return ['migration_id','checksum','status','started_at','completed_at','error_code']; }
    public function insert(string $table, array $data): int|false { return 1; }
    public function update(string $table, array $data, array $where): int|false { return 1; }
    public function delete(string $table, array $where): int|false { return 1; }
    public function query(string $sql): int|bool { $this->queries[]=$sql; return true; }
}

final class Round5RetentionExecutor implements RetentionActionExecutor
{
    public int $calls = 0;
    public function archive(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.archive.'.$recordReference;}
    public function anonymize(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.anonymize.'.$recordReference;}
    public function delete(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.delete.'.$recordReference;}
}

$tests = [];

$tests['retired collections fail closed before physical legacy table access'] = static function (): void {
    expectInvariant(static fn () => RuntimeSchemaExtension::assertActiveCollection('subscriptions'));
    $wpdb = new Round5Wpdb();
    $repo = new WordPressFinancialRepository($wpdb, 'wp_');
    expectInvariant(static fn () => $repo->get('subscriptions', 'subscription:legacy'));
    same5([], $wpdb->queries);
};

$tests['repository pagination uses canonical collection key not assumed numeric id'] = static function (): void {
    $wpdb = new Round5Wpdb();
    $repo = new WordPressFinancialRepository($wpdb, 'wp_');
    same5([], $repo->all('migrations'));
    $sql = implode("\n", $wpdb->queries);
    if (!str_contains($sql, 'ORDER BY migration_id ASC')) {
        throw new RuntimeException('Migration collection pagination is not ordered by its canonical key.');
    }
};

$tests['migration runner binds durable checksum evidence to complete schema v4'] = static function (): void {
    $executed = [];
    $recorded = [];
    $runner = new MigrationRunner(
        static function (string $sql) use (&$executed): void { $executed[] = $sql; },
        static fn (string $id): bool => false,
        static function (string $id,string $checksum) use (&$recorded): void { $recorded[$id]=$checksum; },
        static fn (string $id): ?string => null
    );
    $applied = $runner->migrate('wp_');
    same5(count(CompleteSchema::tables('wp_')), count($applied));
    same5(count($applied), count($recorded));
    foreach ($applied as $id) {
        if (!str_starts_with($id, 'cf03-'.CompleteSchema::VERSION.'-')) {
            throw new RuntimeException('Migration ID is not bound to complete schema version.');
        }
        if (!isset($recorded[$id]) || preg_match('/^[a-f0-9]{64}$/', $recorded[$id]) !== 1) {
            throw new RuntimeException('Migration checksum evidence is missing.');
        }
    }
};

$tests['restore rejects missing duplicate and unexpected provider postings'] = static function (): void {
    $hash = str_repeat('a', 64);
    $manifest = ['ledger'=>['count'=>1,'hash'=>$hash]];
    $restore = new RestoreReconciliation();
    expectInvariant(static fn () => $restore->verify($manifest,$manifest,['event:1'],[]));
    expectInvariant(static fn () => $restore->verify($manifest,$manifest,['event:1'],['event:1','event:1']));
    expectInvariant(static fn () => $restore->verify($manifest,$manifest,['event:1'],['event:1','event:2']));
    same5(true, $restore->verify($manifest,$manifest,['event:1'],['event:1'])['balanced']);
};

$tests['retention external action is claimed and executed exactly once'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $executor = new Round5RetentionExecutor();
    $service = new RetentionOperationsService($repo, $executor);
    $created = new DateTimeImmutable('2026-09-01T00:00:00+00:00');
    $service->schedule('profile_record','record:retention:1','P1',$created,$created->modify('+1 day'),'delete');
    $first = $service->executeDue('record:retention:1',$created->modify('+2 days'));
    $second = $service->executeDue('record:retention:1',$created->modify('+3 days'));
    same5('delete', $first['status']);
    same5('already_actioned', $second['status']);
    same5(1, $executor->calls);
};

$tests['plugin records migration evidence before declaring schema and runtime ready'] = static function (): void {
    $source = file_get_contents(__DIR__.'/../src/Plugin.php');
    if (!is_string($source)) { throw new RuntimeException('Plugin source unavailable.'); }
    $record = strpos($source, 'self::recordMigrationEvidence($migrations);');
    $schemaReady = strpos($source, 'update_option(self::OPTION_SCHEMA_VERSION, CompleteSchema::VERSION, false);');
    $runtimeReady = strpos($source, 'update_option(self::OPTION_RUNTIME_STATUS, self::RUNTIME_STATUS, false);');
    if ($record === false || $schemaReady === false || $runtimeReady === false || !($record < $schemaReady && $schemaReady < $runtimeReady)) {
        throw new RuntimeException('Migration evidence is not durably ordered before readiness publication.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}
fwrite(STDOUT, count($tests)." tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);

function expectInvariant(callable $operation): void
{
    try { $operation(); }
    catch (InvariantViolation) { return; }
    throw new RuntimeException('Expected InvariantViolation.');
}

function same5(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}
