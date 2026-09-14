<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\SubscriptionOperationsService;
use Sabri\CF03\Domain\AiUsageAuthorization;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\PriceLifecycle;
use Sabri\CF03\Domain\PriceVersion;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Domain\TaxMode;
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

$tests['R35 historical paid product remains parseable but can never be checkout eligible'] = static function (): void {
    $product = new FinancialProduct(
        'education.membership',
        ProductKind::EDUCATION_MEMBERSHIP,
        BillingType::RECURRING,
        'owner.cf03',
        'entitlement.education',
        'refund.policy.v1',
        'cancel.policy.v1',
        true,
        true,
        'approval.retired.001'
    );
    reviewSame(false, $product->isCheckoutEligible());
    reviewThrows(static fn () => (new PlatformFinancialPolicy())->assertCollectibleProduct($product), InvariantViolation::class);
};

$tests['R35 voluntary donation remains the only checkout-eligible financial product'] = static function (): void {
    $product = new FinancialProduct(
        'donation.one_time',
        ProductKind::DONATION,
        BillingType::VOLUNTARY,
        'owner.cf03',
        null,
        'refund.policy.v1',
        'cancel.policy.v1',
        true,
        true,
        'approval.donation.001'
    );
    reviewSame(ProductKind::DONATION, $product->kind());
    reviewSame(BillingType::VOLUNTARY, $product->billingType());
    reviewSame(null, $product->entitlementCode());
    reviewSame(true, $product->isCheckoutEligible());
};

$tests['R36 fixed-price lifecycle cannot activate a retired paid price'] = static function (): void {
    $price = new PriceVersion(
        'education.membership',
        'price.education.v1',
        new Money(40000, 'PKR'),
        'GLOBAL',
        TaxMode::NOT_APPLICABLE,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
        'refund.policy.v1',
        'cancel.policy.v1',
        true,
        'approval.price.retired.001'
    );
    $lifecycle = new PriceLifecycle($price, 'approved', 2, 'actor.stager', 'actor.approver');
    reviewThrows(static fn () => $lifecycle->activate(
        'actor.activator', new DateTimeImmutable('2026-09-15T00:00:00Z'), 2
    ), InvariantViolation::class);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d review tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function reviewSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

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
