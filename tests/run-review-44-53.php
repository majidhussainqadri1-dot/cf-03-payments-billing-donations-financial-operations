<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\CatalogDisclosureService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\ReconciliationEngine;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\SettlementBatch;
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

$tests['R46 refund reservations advance the intent concurrency version and roll back failed over-reservation'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T00:00:00Z');
    $repo->insert('intents', 'intent.refund.001', [
        'intent_id' => 'intent.refund.001',
        'actor_ref' => 'user.refund.001',
        'product_id' => 'donation.one_time',
        'amount_minor' => 1000,
        'currency' => 'USD',
        'provider' => 'provider.test',
        'provider_ref' => 'payment.provider.001',
        'state' => 'settled',
        'record_version' => 3,
    ]);
    $service = new RefundWorkflowService($repo, new ProviderRegistry(), RuntimeConfiguration::preparing());
    $service->request('refund.001', 'intent.refund.001', 'user.refund.001', new Money(600, 'USD'), 'requested_by_donor', $now);
    reviewSame(4, $repo->get('intents', 'intent.refund.001')['version']);
    $service->request('refund.002', 'intent.refund.001', 'user.refund.001', new Money(400, 'USD'), 'requested_by_donor', $now);
    reviewSame(5, $repo->get('intents', 'intent.refund.001')['version']);
    reviewThrows(static fn () => $service->request(
        'refund.003', 'intent.refund.001', 'user.refund.001', new Money(1, 'USD'), 'requested_by_donor', $now
    ), InvariantViolation::class);
    reviewSame(5, $repo->get('intents', 'intent.refund.001')['version']);
    reviewSame(null, $repo->get('refunds', 'refund.003'));
};

$tests['R46 chargeback provider must match the canonical payment provider'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $opened = new DateTimeImmutable('2026-09-17T00:00:00Z');
    $repo->insert('intents', 'intent.chargeback.001', [
        'intent_id' => 'intent.chargeback.001',
        'actor_ref' => 'user.chargeback.001',
        'product_id' => 'donation.one_time',
        'amount_minor' => 5000,
        'currency' => 'USD',
        'provider' => 'provider.correct',
        'provider_ref' => 'payment.correct.001',
        'state' => 'settled',
        'record_version' => 2,
    ]);
    $case = new ChargebackCase(
        'chargeback.001',
        'provider.wrong',
        'provider-case.001',
        'intent.chargeback.001',
        new Money(2000, 'USD'),
        'fraud_claim',
        $opened,
        $opened->modify('+30 days')
    );
    $service = new RiskOperationsService($repo, RuntimeConfiguration::preparing());
    reviewThrows(static fn () => $service->openChargeback($case, $opened), InvariantViolation::class);
    reviewSame(null, $repo->get('chargebacks', 'chargeback.001'));
};

$tests['R47 reconciliation rejects non-canonical financial line semantics'] = static function (): void {
    $settledAt = new DateTimeImmutable('2026-09-17T00:00:00Z');
    $batch = new SettlementBatch(
        'batch.review47.001',
        'provider.review47',
        new Money(1000, 'USD'),
        new Money(0, 'USD'),
        new Money(0, 'USD'),
        new Money(1000, 'USD'),
        $settledAt,
        str_repeat('a', 64),
        [[
            'reference' => 'payment.review47.001',
            'type' => 'payment',
            'amount_minor' => 1000,
            'currency' => 'USD',
        ]]
    );
    $engine = new ReconciliationEngine();
    reviewThrows(static fn () => $engine->reconcile($batch, [[
        'reference' => 'payment.review47.001',
        'type' => 'payout',
        'amount_minor' => 1000,
        'currency' => 'USD',
    ]]), InvalidArgumentException::class);
    reviewThrows(static fn () => $engine->reconcile($batch, [[
        'reference' => 'x',
        'type' => 'payment',
        'amount_minor' => 1000,
        'currency' => 'USD',
    ]]), InvalidArgumentException::class);
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
