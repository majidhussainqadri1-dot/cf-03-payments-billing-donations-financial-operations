<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\ActivationGate;
use Sabri\CF03\Domain\CommissionPolicy;
use Sabri\CF03\Domain\DonationPolicy;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PaymentIntentTransition;

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

$tests['activation gate rejects malformed record'] = static function (): void {
    $gate = new ActivationGate(true, static fn (): string => 'invalid');
    assertSame(false, $gate->evaluate()->approved());
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
