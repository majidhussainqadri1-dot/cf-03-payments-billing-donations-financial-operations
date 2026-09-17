<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\CatalogDisclosureService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$tests = [];

$tests['R44 stale active donation record is excluded from public collectible catalog'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T00:00:00Z');
    $repo->insert('products', 'donation.one_time', [
        'product_id' => 'donation.one_time',
        'kind' => ProductKind::DONATION->value,
        'billing_type' => BillingType::VOLUNTARY->value,
        'owner' => 'owner.cf03',
        'entitlement_mapping' => null,
        'lifecycle_state' => 'active',
        'policy_version' => 'legacy-policy',
        'approval_ref' => 'approval.legacy.001',
        'effective_at' => $now,
    ]);
    $catalog = (new CatalogDisclosureService($repo, new FinancialAuditService($repo)))->publicCatalog($now);
    reviewSame([], $catalog['collectible_products']);
};

$tests['R44 conflicting existing donation definition cannot be silently reused'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T00:00:00Z');
    $repo->insert('products', 'donation.one_time', [
        'product_id' => 'donation.one_time',
        'kind' => ProductKind::DONATION->value,
        'billing_type' => BillingType::VOLUNTARY->value,
        'owner' => 'owner.other',
        'entitlement_mapping' => null,
        'lifecycle_state' => 'active',
        'policy_version' => PlatformFinancialPolicy::DECISION_ID,
        'approval_ref' => 'approval.donation.001',
        'effective_at' => $now,
    ]);
    $service = new CatalogDisclosureService($repo, new FinancialAuditService($repo));
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
    reviewThrows(static fn () => $service->registerApprovedDonationProduct(
        $product,
        'actor.stager',
        'actor.approver',
        'actor.activator',
        $now
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
