<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\ActivationGate;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\FutureIntegrationSustainabilityService;
use Sabri\CF03\Application\LedgerJournal;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Domain\CommissionPolicy;
use Sabri\CF03\Domain\DonationPolicy;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PaymentIntentTransition;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$tests = [];

$tests['money uses exact minor units'] = static function (): void {
    $money = Money::fromDecimal('400.00', 'pkr');
    assertSame(40000, $money->minorUnits());
    assertSame('PKR', $money->currency());
    assertSame('400.00', $money->toDecimal());
};

$tests['money rejects excessive precision'] = static function (): void {
    assertThrows(static fn () => Money::fromDecimal('1.001', 'PKR'), InvalidArgumentException::class);
};

$tests['zero commission is constitutional'] = static function (): void {
    $policy = new CommissionPolicy();
    $gross = Money::fromDecimal('1000.00', 'PKR');
    assertSame(0, $policy->platformCommission(CommissionPolicy::DOMAIN_CLINIC, $gross)->minorUnits());
    assertThrows(
        static fn () => $policy->assertConfiguredBasisPoints(CommissionPolicy::DOMAIN_MARKETPLACE, 100),
        DomainException::class
    );
};

$tests['donation defaults do not manipulate consent'] = static function (): void {
    $policy = new DonationPolicy();
    assertSame(null, $policy->defaultAmount());
    assertSame(false, $policy->defaultRecurring());
    $policy->assertNoPrivilegeSignals(['ranking_boost' => false]);
    assertThrows(
        static fn () => $policy->assertNoPrivilegeSignals(['verification_advantage' => true]),
        DomainException::class
    );
};

$tests['payment intent transition rejects browser-style false success'] = static function (): void {
    $transitions = new PaymentIntentTransition();
    assertThrows(
        static fn () => $transitions->assertAllowed(PaymentIntentState::CREATED, PaymentIntentState::SETTLED),
        DomainException::class
    );
    $transitions->assertAllowed(PaymentIntentState::PROVIDER_PENDING, PaymentIntentState::SETTLED);
};

$tests['ledger transaction balances by currency'] = static function (): void {
    $amount = Money::fromDecimal('400.00', 'PKR');
    $transaction = new LedgerTransaction('txn-1', [
        new LedgerEntry('asset.provider_receivable', LedgerEntry::DEBIT, $amount, 'payment-1'),
        new LedgerEntry('income.education_membership', LedgerEntry::CREDIT, $amount, 'payment-1'),
    ]);
    assertSame('txn-1', $transaction->transactionId());
};

$tests['unbalanced ledger transaction is rejected'] = static function (): void {
    assertThrows(static function (): void {
        new LedgerTransaction('txn-2', [
            new LedgerEntry('asset.provider_receivable', LedgerEntry::DEBIT, Money::fromDecimal('400.00', 'PKR'), 'payment-2'),
            new LedgerEntry('income.education_membership', LedgerEntry::CREDIT, Money::fromDecimal('399.99', 'PKR'), 'payment-2'),
        ]);
    }, DomainException::class);
};

$tests['activation gate remains fail closed without complete evidence'] = static function (): void {
    $gate = new ActivationGate(false, static fn (): array => []);
    $status = $gate->evaluate();
    assertSame(false, $status->approved());
    assertTrue(in_array('runtime_constant', $status->missingGates(), true));
};

$tests['activation gate approves only complete evidence'] = static function (): void {
    $gate = new ActivationGate(true, static fn (): array => [
        'founder_change_control_approved' => true,
        'legal_tax_accounting_review_approved' => true,
        'pci_scope_validated' => true,
        'independent_security_acceptance' => true,
        'staging_acceptance' => true,
        'rollback_rehearsal_passed' => true,
        'provider_mode' => 'hosted',
    ]);
    assertSame(true, $gate->evaluate()->approved());
};

$tests['money rejects integer overflow'] = static function (): void {
    $max = new Money(PHP_INT_MAX, 'PKR');
    assertThrows(static fn () => $max->add(new Money(1, 'PKR')), DomainException::class);
};

$tests['ledger balances each currency independently'] = static function (): void {
    new LedgerTransaction('txn-multi', [
        new LedgerEntry('asset.pkr', LedgerEntry::DEBIT, new Money(100, 'PKR'), 'multi'),
        new LedgerEntry('income.pkr', LedgerEntry::CREDIT, new Money(100, 'PKR'), 'multi'),
        new LedgerEntry('asset.usd', LedgerEntry::DEBIT, new Money(50, 'USD'), 'multi'),
        new LedgerEntry('income.usd', LedgerEntry::CREDIT, new Money(50, 'USD'), 'multi'),
    ]);
};

$tests['ledger journal rejects aggregate account-balance overflow'] = static function (): void {
    $journal = new LedgerJournal();
    $at = new DateTimeImmutable('2026-09-14T12:00:00Z');
    $journal->post(new LedgerTransaction('txn-max', [
        new LedgerEntry('asset.provider', LedgerEntry::DEBIT, new Money(PHP_INT_MAX, 'PKR'), 'max-asset'),
        new LedgerEntry('income.donation', LedgerEntry::CREDIT, new Money(PHP_INT_MAX, 'PKR'), 'max-income'),
    ]), 'test', 'max', 'system:test', 'overflow-boundary', '2026-09', $at, $at);
    $journal->post(new LedgerTransaction('txn-one', [
        new LedgerEntry('asset.provider', LedgerEntry::DEBIT, new Money(1, 'PKR'), 'one-asset'),
        new LedgerEntry('income.donation', LedgerEntry::CREDIT, new Money(1, 'PKR'), 'one-income'),
    ]), 'test', 'one', 'system:test', 'overflow-boundary', '2026-09', $at, $at);
    assertThrows(static fn () => $journal->accountBalances('PKR'), DomainException::class);
};

$tests['system integrity rejects orphan empty and overflow ledger evidence'] = static function (): void {
    $orphan = new MemoryFinancialRepository(true);
    $orphan->insert('ledger_entries', 'entry.orphan.001', [
        'transaction_id' => 'txn.missing.001',
        'account' => 'asset.provider',
        'direction' => 'debit',
        'amount_minor' => 1,
        'currency' => 'USD',
        'source_ref' => 'entry.orphan.001',
    ]);
    assertThrows(
        static fn () => (new SystemIntegrityService($orphan, new FinancialAuditService($orphan)))->ledgerBalance(),
        DomainException::class
    );

    $empty = new MemoryFinancialRepository(true);
    $empty->insert('ledger_transactions', 'txn.empty.001', [
        'transaction_id' => 'txn.empty.001',
        'source_type' => 'test',
        'source_ref' => 'source.empty.001',
    ]);
    assertThrows(
        static fn () => (new SystemIntegrityService($empty, new FinancialAuditService($empty)))->ledgerBalance(),
        DomainException::class
    );

    $overflow = new MemoryFinancialRepository(true);
    $overflow->insert('ledger_transactions', 'txn.overflow.001', [
        'transaction_id' => 'txn.overflow.001',
        'source_type' => 'test',
        'source_ref' => 'source.overflow.001',
    ]);
    foreach ([
        ['entry.max.debit', 'debit', PHP_INT_MAX],
        ['entry.one.debit', 'debit', 1],
        ['entry.max.credit', 'credit', PHP_INT_MAX],
    ] as [$sourceRef, $direction, $amount]) {
        $overflow->insert('ledger_entries', $sourceRef, [
            'transaction_id' => 'txn.overflow.001',
            'account' => $direction === 'debit' ? 'asset.provider' : 'income.donation',
            'direction' => $direction,
            'amount_minor' => $amount,
            'currency' => 'USD',
            'source_ref' => $sourceRef,
        ]);
    }
    assertThrows(
        static fn () => (new SystemIntegrityService($overflow, new FinancialAuditService($overflow)))->ledgerBalance(),
        DomainException::class
    );
};

$tests['fully refunded zero-fee settlement posts without empty ledger transaction'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $at = new DateTimeImmutable('2026-09-14T12:00:00Z');
    $gates = array_fill_keys([
        'founder_change_control','legal_tax_accounting','pci_scope','provider_selected',
        'independent_security','staging_acceptance','rollback_evidence','file00_contract',
        'file20_file25_contract','file24_assurance','operations_ready',
    ], true);
    $runtime = new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', $gates);
    $audit = new FinancialAuditService($repo);
    $service = new SettlementOperationsService($repo, $runtime, $audit);
    $lines = [
        ['reference'=>'pay.zero.001','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],
        ['reference'=>'refund.zero.001','type'=>'refund','amount_minor'=>1000,'currency'=>'USD'],
    ];
    $batch = new SettlementBatch(
        'batch.zero.001', 'provider.test', new Money(1000, 'USD'), new Money(0, 'USD'),
        new Money(1000, 'USD'), new Money(0, 'USD'), $at, str_repeat('b', 64), $lines
    );
    $service->importAndReconcile($batch, $lines, ['USD'=>0], 'operator.importer.001', $at);
    $posted = $service->postResolvedBatch('batch.zero.001', 'operator.poster.001', $at->modify('+1 minute'));
    assertSame('posted', $posted['status']);
    assertSame([], $repo->all('ledger_transactions'));
};

$tests['activation gate rejects malformed record'] = static function (): void {
    $gate = new ActivationGate(true, static fn (): string => 'invalid');
    assertSame(false, $gate->evaluate()->approved());
};

$tests['FX-39 requires founder legal-accounting Sharia and operational readiness'] = static function (): void {
    $service = new FutureIntegrationSustainabilityService();

    assertSame(false, $service->sustainabilityModule('waqf', true, true, false, true)['enabled']);
    assertSame(false, $service->sustainabilityModule('waqf', true, true, true, false)['enabled']);
    assertSame(false, $service->sustainabilityModule('waqf', false, true, true, true)['enabled']);
    assertSame(false, $service->sustainabilityModule('waqf', true, false, true, true)['enabled']);
    assertSame(true, $service->sustainabilityModule('waqf', true, true, true, true)['enabled']);
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
        throw new RuntimeException(sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Expected condition to be true.');
    }
}

/** @param class-string<Throwable> $exceptionClass */
function assertThrows(callable $callback, string $exceptionClass): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException(sprintf('Expected %s, got %s.', $exceptionClass, $error::class));
    }

    throw new RuntimeException(sprintf('Expected %s to be thrown.', $exceptionClass));
}
