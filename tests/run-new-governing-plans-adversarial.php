<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptState;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\RuntimeSchemaExtension;
use Sabri\CF03\Support\InvariantViolation;

$root = dirname(__DIR__);
$tests = [];
$now = new DateTimeImmutable('2026-09-08T05:00:00+05:00');

$tests['legacy monthly prompt actions fail closed'] = static function () use ($now): void {
    foreach ([DonationPromptAction::DONATION_COMPLETED_MONTHLY, DonationPromptAction::MONTHLY_CANCELLED] as $action) {
        throws(static fn () => (new DonationPromptState())->apply($action, $now), InvariantViolation::class);
    }
};

$tests['monthly and recurring intent combinations all fail closed'] = static function () use ($now): void {
    foreach ([[true, false], [false, true], [true, true]] as [$monthly, $consent]) {
        throws(static fn () => new DonationIntentDraft(
            'intent:adversarial:'.($monthly ? '1' : '0').($consent ? '1' : '0'),
            'user:42',
            new Money(1000, 'USD'),
            $monthly,
            $consent,
            DonationServiceState::SANDBOX,
            'idem-adversarial-one-time-0001',
            $now
        ), InvariantViolation::class);
    }
};

$tests['retired schema list is exact and active map unsets it'] = static function (): void {
    same(['recurring_consents','subscriptions','usage_authorizations','usage_facts'], RuntimeSchemaExtension::RETIRED_TABLES);
    $source = source('src/Persistence/RuntimeSchemaExtension.php');
    contains($source, 'unset($tables[$retired]);', true);
};

$tests['public donation UI contains one-time consent and no monthly checkbox'] = static function (): void {
    $ui = source('src/Infrastructure/WordPressPublicUi.php');
    contains($ui, 'name="one_time_consent"', true);
    contains($ui, 'name="monthly"', false);
    contains($ui, 'recurring or automatic repeat charge', true);
};

$tests['public javascript cannot submit monthly or recurring donation fields'] = static function (): void {
    $js = source('assets/js/public.js');
    contains($js, 'one_time_consent', true);
    contains($js, 'monthly_consent', false);
    contains($js, 'name="monthly"', false);
    contains($js, "result.donation_type!=='one_time'", true);
};

$tests['REST contract requires explicit one-time consent and rejects recurring aliases'] = static function (): void {
    $rest = source('src/Infrastructure/WordPressRestApi.php');
    contains($rest, "'one_time_consent'", true);
    contains($rest, "['monthly', 'monthly_consent', 'recurring', 'recurring_consent', 'subscription']", true);
    contains($rest, 'Paid membership, education, AI and other core-platform checkout is prohibited', true);
    contains($rest, "'recurring_donation_available' => false", true);
};

$tests['checkout and webhook active paths contain no monthly product settlement'] = static function (): void {
    $checkout = source('src/Application/DonationCheckoutService.php');
    $webhook = source('src/Application/WebhookIngestionService.php');
    contains($checkout, "'donation.one_time'", true);
    contains($checkout, "'donation.monthly'", false);
    contains($webhook, "!== 'donation.one_time'", true);
    contains($webhook, 'quarantined_retired_financial_product', true);
    contains($webhook, "'no_access_event' => true", true);
};

$tests['signed webhook duplicate remains authenticated for canonical duplicate parity'] = static function (): void {
    $secret = str_repeat('s', 32);
    $timestamp = new DateTimeImmutable('2026-09-08T05:00:00+05:00');
    $body = json_encode([
        'event_id' => 'evt.duplicate.001',
        'type' => 'payment.settled',
        'intent_id' => 'intent.duplicate.001',
        'amount_minor' => 1000,
        'currency' => 'USD',
        'occurred_at' => $timestamp->format(DATE_ATOM),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $timestamp->getTimestamp().'.'.$body, $secret);
    $verifier = new ProviderWebhookVerifier(
        static fn (string $provider, string $keyVersion): string => $secret,
        static fn (string $provider, string $eventId): bool => false,
        300
    );
    $evidence = $verifier->verify('provider.test', 'key.v1', $body, $signature, $timestamp, $timestamp);
    same(false, $evidence->eventIdUnique());
    $evidence->assertAuthenticated(300);
    throws(static fn () => $evidence->assertTrusted(300), InvariantViolation::class);
};

$tests['adjustment retry requires exact terms and chronology cannot move backwards'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $requestedAt = new DateTimeImmutable('2026-09-14T12:00:00Z');
    $repo->insert('ledger_transactions', 'txn.adjust.source.001', [
        'transaction_id' => 'txn.adjust.source.001',
        'source_type' => 'provider_settlement',
        'source_ref' => 'provider.event.001',
        'effective_at' => $requestedAt,
        'recorded_at' => $requestedAt,
        'actor_ref' => 'system.provider',
        'reason' => 'trusted_settlement',
        'period_id' => '2026-09',
        'reversal_of' => null,
        'trace_id' => 'trace.adjust.source.001',
    ]);
    $gates = array_fill_keys([
        'founder_change_control','legal_tax_accounting','pci_scope','provider_selected',
        'independent_security','staging_acceptance','rollback_evidence','file00_contract',
        'file20_file25_contract','file24_assurance','operations_ready',
    ], true);
    $configuration = new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', $gates);
    $service = new FinancialAdjustmentService($repo, $configuration, new FinancialAuditService($repo));
    $service->request(
        'adjustment.exact.001',
        'txn.adjust.source.001',
        new Money(1000, 'USD'),
        'expense.financial_adjustment',
        'asset.provider_clearing',
        'correction',
        str_repeat('b', 64),
        'user.requester.001',
        $requestedAt
    );
    throws(static fn () => $service->request(
        'adjustment.exact.001',
        'txn.adjust.source.001',
        new Money(1000, 'USD'),
        'expense.financial_adjustment',
        'asset.bank_receivable',
        'correction',
        str_repeat('b', 64),
        'user.requester.001',
        $requestedAt
    ), InvariantViolation::class);
    throws(static fn () => $service->decide(
        'adjustment.exact.001', true, 'user.reviewer.001', 1, $requestedAt->modify('-1 second')
    ), InvariantViolation::class);
    $service->decide('adjustment.exact.001', true, 'user.reviewer.001', 1, $requestedAt);
    throws(static fn () => $service->execute(
        'adjustment.exact.001', 'user.executor.001', 2, $requestedAt->modify('-1 second')
    ), InvariantViolation::class);
};

$tests['won zero-fee chargeback creates no empty ledger transaction'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $at = new DateTimeImmutable('2026-09-14T12:00:00Z');
    $repo->insert('chargebacks', 'case.zero.001', [
        'case_id' => 'case.zero.001',
        'provider' => 'provider.test',
        'provider_case_ref' => 'provider.case.001',
        'intent_id' => 'intent.case.001',
        'amount_minor' => 1000,
        'currency' => 'USD',
        'reason_code' => 'fraud',
        'response_deadline' => $at->modify('+30 days'),
        'evidence_hash' => str_repeat('a', 64),
        'provider_fee_minor' => 0,
        'state' => 'won',
        'record_version' => 5,
        'created_at' => $at->modify('-1 day'),
        'updated_at' => $at,
    ]);
    $gates = array_fill_keys([
        'founder_change_control','legal_tax_accounting','pci_scope','provider_selected',
        'independent_security','staging_acceptance','rollback_evidence','file00_contract',
        'file20_file25_contract','file24_assurance','operations_ready',
    ], true);
    $config = new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', $gates);
    $result = (new RiskOperationsService($repo, $config))->adjustChargebackLedger(
        'case.zero.001', 'operator.finance.001', $at, 5
    );
    same(null, $result['transaction_id']);
    same(true, $result['no_financial_delta']);
    same([], $repo->all('ledger_transactions'));
    same('ledger_adjusted', $repo->get('chargebacks', 'case.zero.001')['state']);
};

$tests['paid AI billing and subscription services are tombstones not charging paths'] = static function (): void {
    $ai = source('src/Application/AiUsageBillingService.php');
    $subscriptions = source('src/Application/SubscriptionOperationsService.php');
    contains($ai, 'income.ai_usage', false);
    contains($ai, 'AI usage billing is retired', true);
    contains($subscriptions, 'Paid subscription, renewal, grace and dunning operations are retired', true);
    contains($subscriptions, "repository->insert('subscriptions'", false);
};

$tests['active manifests contain no current monthly donation contract'] = static function (): void {
    foreach (['manifests/cf03-contracts.json','manifests/cf03-release-1.4.0.json','manifests/cf03-future-expansion-40.json','manifests/donation-appeal-contract.json'] as $path) {
        $text = source($path);
        contains($text, '"recurring_donation_available": true', false);
        contains($text, '"automatic_repeat_charge": true', false);
        contains($text, '"calendar_month_maximum"', false);
        contains($text, '"remind_later_days": 30', false);
    }
};

$tests['current README does not promote superseded financial model'] = static function (): void {
    $readme = source('README.md');
    contains($readme, 'one-time donation only', true);
    contains($readme, 'at least **7 days**', true);
    contains($readme, 'Donations are voluntary, one-time or monthly', false);
    contains($readme, 'requires at least 30 days', false);
};

$tests['active source does not contain fixed PKR400 or paid AI pricing claim'] = static function () use ($root): void {
    $paths = [
        'src/Domain/PlatformFinancialPolicy.php',
        'src/Application/GoverningPlanRegistry.php',
        'src/Infrastructure/WordPressRestApi.php',
        'src/Infrastructure/WordPressPublicUi.php',
        'src/Application/FutureExpansionRegistry.php',
        'src/Domain/FutureFinancePolicy.php',
        'README.md',
        'manifests/cf03-contracts.json',
        'manifests/cf03-release-1.4.0.json',
        'manifests/cf03-future-expansion-40.json',
    ];
    foreach ($paths as $path) {
        $text = (string)file_get_contents($root.'/'.$path);
        contains($text, 'PKR 400', false);
        contains(strtolower($text), 'paid ai add-on', false);
    }
};

$tests['future pack cannot activate itself or revive donor privilege'] = static function (): void {
    $registry = source('src/Application/FutureExpansionRegistry.php');
    $policy = source('src/Domain/FutureFinancePolicy.php');
    contains($registry, "'activated' => false", true);
    contains($registry, "'donor_privilege_allowed' => false", true);
    contains($registry, "'recurring_donation_allowed' => false", true);
    contains($registry, "'paid_core_allowed' => false", true);
    contains($policy, "'future_pack_activated_by_code_presence' => false", true);
};

$tests['release identity and package builder stay aligned'] = static function (): void {
    $bootstrap = source('cf-03-payments-billing-donations-financial-operations.php');
    $builder = source('scripts/build-package.sh');
    contains($bootstrap, 'Version: 1.4.0-rc.1', true);
    contains($bootstrap, "SABRI_CF03_SCHEMA_VERSION', '4.0.0'", true);
    contains($bootstrap, 'CF03-FUTURE40-2026-09-08', true);
    contains($builder, 'VERSION="1.4.0-rc.1"', true);
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
fwrite(STDOUT, sprintf("%d adversarial tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function source(string $path): string
{
    $value = file_get_contents(dirname(__DIR__).'/'.$path);
    if (!is_string($value)) { throw new RuntimeException('Could not read '.$path); }
    return $value;
}

function contains(string $haystack, string $needle, bool $expected): void
{
    $actual = str_contains($haystack, $needle);
    if ($actual !== $expected) {
        throw new RuntimeException(($expected ? 'Missing: ' : 'Unexpected: ').$needle);
    }
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(var_export($expected, true).' !== '.var_export($actual, true));
    }
}

/** @param class-string<Throwable> $class */
function throws(callable $callback, string $class): void
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());
    }
    throw new RuntimeException('Expected '.$class);
}
