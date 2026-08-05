<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\BackupManifest;
use Sabri\CF03\Application\IncidentControl;
use Sabri\CF03\Domain\FinanceExport;
use Sabri\CF03\Domain\FinancePeriod;
use Sabri\CF03\Domain\Invoice;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PrePurchaseDisclosure;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\RefundRequest;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\MigrationRunner;
use Sabri\CF03\Persistence\Schema;

$tests = [];

$tests['recurring disclosure requires interval'] = static fn () => assertThrows(
    static fn () => new PrePurchaseDisclosure('product:1', 'version:1', new Money(40000, 'PKR'), true, null, null, 'exclusive', 'refund:1', 'cancel:1', 'hosted', 'notice'),
    InvalidArgumentException::class
);
$tests['one time disclosure rejects renewal'] = static fn () => assertThrows(
    static fn () => new PrePurchaseDisclosure('product:1', 'version:1', new Money(100, 'PKR'), false, 'month', null, 'exclusive', 'refund:1', 'cancel:1', 'hosted', 'notice'),
    InvalidArgumentException::class
);
$tests['valid disclosure serializes exact amount'] = static function (): void {
    $disclosure = new PrePurchaseDisclosure('education-membership', 'version:1', new Money(40000, 'PKR'), true, 'month', '2026-09-01', 'exclusive', 'refund:1', 'cancel:1', 'hosted', 'notice');
    assertSame(40000, $disclosure->toArray()['total_minor']);
};
$tests['invoice validates item total'] = static function (): void {
    new Invoice('invoice:1', 'INV-1', 'user:1', 'Sabri', [['description' => 'Membership', 'quantity' => 1, 'unit_minor' => 40000]], new Money(40000, 'PKR'), 'issued', hash('sha256', 'snapshot'));
};
$tests['invoice rejects mismatch'] = static fn () => assertThrows(
    static fn () => new Invoice('invoice:1', 'INV-1', 'user:1', 'Sabri', [['description' => 'Membership', 'quantity' => 1, 'unit_minor' => 39999]], new Money(40000, 'PKR'), 'issued', hash('sha256', 'snapshot')),
    DomainException::class
);
$tests['subscription optimistic transition'] = static function (): void {
    $subscription = new Subscription('sub:1', 'user:1', 'product:1', 'version:1', 'pending');
    $subscription->transition('active', 1);
    assertSame('active', $subscription->state());
    assertSame(2, $subscription->version());
};
$tests['subscription rejects stale version'] = static function (): void {
    $subscription = new Subscription('sub:1', 'user:1', 'product:1', 'version:1', 'pending');
    assertThrows(static fn () => $subscription->transition('active', 2), DomainException::class);
};
$tests['subscription rejects invalid transition'] = static function (): void {
    $subscription = new Subscription('sub:1', 'user:1', 'product:1', 'version:1', 'pending');
    assertThrows(static fn () => $subscription->transition('past_due', 1), DomainException::class);
};
$tests['refund separates requester reviewer executor'] = static function (): void {
    $refund = new RefundRequest('refund:1', 'intent:1', 'user:1', new Money(100, 'PKR'), 'duplicate');
    $refund->approve('reviewer:1');
    $refund->markExecuting('executor:1');
    $refund->succeed();
    assertSame('succeeded', $refund->status());
};
$tests['refund requester cannot approve'] = static function (): void {
    $refund = new RefundRequest('refund:1', 'intent:1', 'user:1', new Money(100, 'PKR'), 'duplicate');
    assertThrows(static fn () => $refund->approve('user:1'), DomainException::class);
};
$tests['refund reviewer cannot execute'] = static function (): void {
    $refund = new RefundRequest('refund:1', 'intent:1', 'user:1', new Money(100, 'PKR'), 'duplicate');
    $refund->approve('reviewer:1');
    assertThrows(static fn () => $refund->markExecuting('reviewer:1'), DomainException::class);
};
$tests['material reconciliation blocks close'] = static function (): void {
    $result = new ReconciliationResult([['type' => 'missing_line', 'reference' => 'payment:1', 'expected' => 100, 'actual' => 0, 'currency' => 'PKR', 'material' => true]]);
    assertThrows(static fn () => $result->assertClosable(), DomainException::class);
};
$tests['non material reconciliation permits close'] = static function (): void {
    $result = new ReconciliationResult([['type' => 'rounding_difference', 'reference' => 'payment:1', 'expected' => 100, 'actual' => 99, 'currency' => 'PKR', 'material' => false]]);
    $period = new FinancePeriod('2026-08');
    $period->beginReconciliation(1);
    $period->approveClose($result, 'reviewer:1', 'approver:1', 2);
    $period->lock(new DateTimeImmutable('2026-09-01T00:00:00Z'), 3);
    assertSame(true, $period->closed());
};
$tests['closed period rejects mutation'] = static function (): void {
    $period = new FinancePeriod('2026-08');
    $period->beginReconciliation(1);
    $period->approveClose(new ReconciliationResult([]), 'reviewer:1', 'approver:1', 2);
    $period->lock(new DateTimeImmutable('2026-09-01T00:00:00Z'), 3);
    assertThrows(static fn () => $period->assertWritable(), DomainException::class);
};
$tests['export neutralizes formulas'] = static fn () => assertSame("'=2+2", FinanceExport::neutralizeSpreadsheetFormula('=2+2'));
$tests['export rejects protected fields'] = static fn () => assertThrows(
    static fn () => new FinanceExport('export:1', [['provider_secret' => 'redacted']], hash('sha256', 'm'), time() + 60),
    DomainException::class
);
$tests['export expiry works'] = static function (): void {
    $export = new FinanceExport('export:1', [['amount_minor' => 100]], hash('sha256', 'm'), 100);
    assertSame(true, $export->expired(100));
};
$tests['memory repository rejects duplicates'] = static function (): void {
    $repository = new MemoryFinancialRepository();
    $repository->insert('test_records', 'record:1', ['state' => 'a']);
    assertThrows(static fn () => $repository->insert('test_records', 'record:1', ['state' => 'b']), DomainException::class);
};
$tests['memory repository compare and swap'] = static function (): void {
    $repository = new MemoryFinancialRepository();
    $repository->insert('test_records', 'record:1', ['state' => 'a']);
    $next = $repository->compareAndSwap('test_records', 'record:1', 1, static fn (array $value): array => ['state' => 'b']);
    assertSame(2, $next['version']);
};
$tests['memory repository stale update rejected'] = static function (): void {
    $repository = new MemoryFinancialRepository();
    $repository->insert('test_records', 'record:1', ['state' => 'a']);
    assertThrows(static fn () => $repository->compareAndSwap('test_records', 'record:1', 2, static fn (array $value): array => $value), DomainException::class);
};
$tests['schema declares complete owner tables'] = static function (): void {
    $tables = Schema::tables('wp_');
    foreach (['products', 'prices', 'intents', 'provider_events', 'ledger_transactions', 'ledger_entries', 'subscriptions', 'invoices', 'refunds', 'outbox', 'audit', 'migrations'] as $name) {
        assertSame(true, isset($tables[$name]));
    }
};
$tests['migration runner is checksum aware'] = static function (): void {
    $ran = [];
    $recorded = [];
    $tables = Schema::tables('wp_');
    $productChecksum = hash('sha256', $tables['products']);
    $runner = new MigrationRunner(
        static function (string $sql) use (&$ran): void { $ran[] = $sql; },
        static fn (string $id): bool => str_ends_with($id, '-products'),
        static function (string $id, string $checksum) use (&$recorded): void { $recorded[$id] = $checksum; },
        static fn (string $id): ?string => str_ends_with($id, '-products') ? $productChecksum : null
    );
    $applied = $runner->migrate('wp_');
    assertSame(false, in_array('cf03-2.0.0-products', $applied, true));
    assertSame(count($tables) - 1, count($applied));
    assertSame(count($tables) - 1, count($recorded));
};
$tests['incident controls are selective'] = static function (): void {
    $incident = new IncidentControl();
    $incident->enableAfterApproval();
    $incident->killCheckout();
    assertSame(['checkout' => false, 'refunds' => true, 'webhooks' => true], $incident->status());
};
$tests['backup manifests match'] = static function (): void {
    $manifest = new BackupManifest(['ledger' => ['count' => 2, 'hash' => hash('sha256', 'x')]]);
    $manifest->assertMatches(new BackupManifest(['ledger' => ['count' => 2, 'hash' => hash('sha256', 'x')]]));
};
$tests['backup mismatch rejected'] = static function (): void {
    $manifest = new BackupManifest(['ledger' => ['count' => 2, 'hash' => hash('sha256', 'x')]]);
    assertThrows(static fn () => $manifest->assertMatches(new BackupManifest(['ledger' => ['count' => 1, 'hash' => hash('sha256', 'x')]])), DomainException::class);
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
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: ' . var_export($expected, true) . ' !== ' . var_export($actual, true));
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(callable $callback, string $class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException('Expected ' . $class . ', got ' . $error::class);
    }
    throw new RuntimeException('Expected exception ' . $class);
}
