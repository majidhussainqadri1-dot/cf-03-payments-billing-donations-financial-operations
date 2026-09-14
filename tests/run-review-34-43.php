<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\SubscriptionOperationsService;
use Sabri\CF03\Domain\AiUsageAuthorization;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\NullPaidCapabilityAuthorization;
use Sabri\CF03\Support\InvariantViolation;

$tests = [];

$tests['R34 paid subscription domain is a fail-closed compatibility tombstone'] = static function (): void {
    reviewThrows(static fn () => new Subscription(
        'subscription.retired.001',
        'user.retired.001',
        'education.membership',
        'price.retired.001',
        'pending'
    ), InvariantViolation::class);
};

$tests['R34 dunning policy cannot schedule automatic repeat collection'] = static function (): void {
    reviewThrows(static fn () => new DunningPolicy([300, 600, 1200, 2400]), InvariantViolation::class);
};

$tests['R34 paid AI authorization domain is a fail-closed compatibility tombstone'] = static function (): void {
    reviewThrows(static fn () => new AiUsageAuthorization(
        'authorization.retired.001',
        'user.retired.001',
        'ai.usage.retired',
        'price.retired.001',
        100,
        new Money(1000, 'USD'),
        new DateTimeImmutable('2026-12-31T00:00:00Z')
    ), InvariantViolation::class);
};

$tests['R34 subscription operations tombstone constructs without instantiating retired dunning'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $service = new SubscriptionOperationsService(
        $repo,
        new NullPaidCapabilityAuthorization(),
        new FinancialAuditService($repo)
    );
    reviewThrows(static fn () => $service->create(
        'subscription.retired.002',
        'user.retired.002',
        'education.membership',
        'price.retired.002',
        'provider.retired',
        'decision.retired.002',
        new DateTimeImmutable('2026-09-15T00:00:00Z'),
        new DateTimeImmutable('2026-10-15T00:00:00Z'),
        new DateTimeImmutable('2026-09-15T00:00:00Z')
    ), InvariantViolation::class);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d review tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

/** @param class-string<Throwable> $class */
function reviewThrows(callable $callback, string $class): void
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());
    }
    throw new RuntimeException('Expected '.$class.' to be thrown.');
}
