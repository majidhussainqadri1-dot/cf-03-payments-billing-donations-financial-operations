<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\BillingQueryService;
use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Application\DonationCatalogSeeder;
use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\GoverningPlanRegistry;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\DonationNeutralityPolicy;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptContext;
use Sabri\CF03\Domain\DonationPromptPolicy;
use Sabri\CF03\Domain\DonationPromptState;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\RecurringConsent;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Persistence\RuntimeSchemaExtension;
use Sabri\CF03\Support\InvariantViolation;

$tests = [];
$now = new DateTimeImmutable('2026-09-08T04:30:00+05:00');

$tests['only two new plans govern current candidate'] = static function (): void {
    same([
        'SSH-PMP-2026-v3.0',
        'CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.0',
    ], GoverningPlanRegistry::governingPlans());
    same('CF03-2026-v1.0', PlatformFinancialPolicy::DECISION_ID);
};

$tests['single free tier and zero commission are constitutional'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    same(true, $policy->allCoreServicesFree());
    same(true, $policy->structuredEducationFree());
    same(true, $policy->aiCoreFree());
    same(true, $policy->paidServicesSuspended());
    same(0, $policy->platformCommissionBasisPoints());
    same(true, $policy->oneTimeDonationOnly());
    same(false, $policy->recurringDonationAvailable());
    same(7, PlatformFinancialPolicy::APPEAL_MINIMUM_DAYS);
};

$tests['only donation can be collectible'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    $paidEducation = new FinancialProduct(
        'education.membership.monthly',
        ProductKind::EDUCATION_MEMBERSHIP,
        BillingType::RECURRING,
        'file00.membership',
        'education.structured',
        'refund.education.v1',
        'cancel.education.v1',
        true,
        true,
        'approval.product.education.1'
    );
    throws(static fn () => $policy->assertCollectibleProduct($paidEducation), InvariantViolation::class);
};

$tests['appeal copy is seven-day one-time no-preselection'] = static function (): void {
    $contract = DonationAppealCopy::contract();
    same('one_time', $contract['donation_type']);
    same(false, $contract['recurring_available']);
    same(false, $contract['automatic_repeat_charge']);
    same(true, $contract['explicit_one_time_consent_required']);
    same(null, $contract['preselected_amount']);
    same(7, $contract['frequency']['minimum_days_between_appeals']);
    foreach ($contract['amounts'] as $amount) {
        same(false, $amount['preselected'] ?? false);
    }
};

$tests['prompt remains blocked until seven days pass'] = static function () use ($now): void {
    $policy = new DonationPromptPolicy();
    $context = context(60);
    $state = (new DonationPromptState())->apply(DonationPromptAction::SHOWN, $now);
    same(false, $policy->decide($state, $context, $now->modify('+6 days 23 hours 59 minutes'))->shouldShow());
    same(true, $policy->decide($state, $context, $now->modify('+7 days'))->shouldShow());
};

$tests['dismissal and completed donation both use seven-day silence'] = static function () use ($now): void {
    foreach ([DonationPromptAction::REMIND_LATER, DonationPromptAction::NOT_NOW, DonationPromptAction::CLOSE] as $action) {
        $state = (new DonationPromptState())->apply($action, $now);
        same($now->modify('+7 days')->format(DATE_ATOM), $state->snoozedUntil()?->format(DATE_ATOM));
    }
    $done = (new DonationPromptState())->apply(DonationPromptAction::DONATION_COMPLETED_ONE_TIME, $now);
    same($now->modify('+7 days')->format(DATE_ATOM), $done->nextDonationPromptAt()?->format(DATE_ATOM));
};

$tests['recurring donation input is rejected at intent boundary'] = static function () use ($now): void {
    throws(static fn () => new DonationIntentDraft(
        'intent:one-time:1', 'user:42', new Money(1000, 'USD'), true, true,
        DonationServiceState::SANDBOX, 'idem-one-time-donation-0001', $now
    ), InvariantViolation::class);

    $draft = new DonationIntentDraft(
        'intent:one-time:2', 'user:42', new Money(1400, 'USD'), false, false,
        DonationServiceState::SANDBOX, 'idem-one-time-donation-0002', $now
    );
    $payload = $draft->toSafePayload();
    same('one_time', $payload['donation_type']);
    same(false, $payload['recurring']);
    same(false, $draft->monthly());
};

$tests['legacy recurring consent cannot be created'] = static function () use ($now): void {
    throws(static fn () => new RecurringConsent(
        'consent:legacy:1', 'user:42', 'donation.monthly', new Money(1000, 'USD'),
        'month', $now->modify('+1 month'), str_repeat('a', 64), '/billing/donations', $now, true
    ), InvariantViolation::class);
};

$tests['schema v4 removes retired active tables'] = static function (): void {
    same('4.0.0', CompleteSchema::VERSION);
    same('2.0.0', CompleteSchema::BASE_VERSION);
    $tables = CompleteSchema::tables('wp_');
    same(27, count($tables));
    foreach (RuntimeSchemaExtension::RETIRED_TABLES as $retired) {
        same(false, array_key_exists($retired, $tables));
    }
};

$tests['catalog seeder activates one-time and retires legacy monthly product'] = static function () use ($now): void {
    $repo = new MemoryFinancialRepository();
    $repo->insert('products', 'donation.monthly', [
        'product_id' => 'donation.monthly',
        'kind' => ProductKind::DONATION->value,
        'billing_type' => BillingType::VOLUNTARY->value,
        'owner' => 'CF-03',
        'entitlement_mapping' => null,
        'lifecycle_state' => 'active',
        'policy_version' => 'legacy',
        'approval_ref' => 'legacy',
        'record_version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    same(['donation.one_time'], (new DonationCatalogSeeder())->seed($repo, $now));
    same('active', $repo->get('products', 'donation.one_time')['lifecycle_state']);
    same('retired', $repo->get('products', 'donation.monthly')['lifecycle_state']);
};

$tests['billing projection omits subscriptions and legacy recurring donation rows'] = static function () use ($now): void {
    $repo = new MemoryFinancialRepository();
    $repo->insert('donations', 'donation.one', [
        'donation_id' => 'donation.one', 'donor_ref' => 'user:42', 'amount_minor' => 1000,
        'currency' => 'USD', 'purpose_code' => 'support', 'recurring' => false,
        'receipt_ref' => null, 'state' => 'settled', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $repo->insert('donations', 'donation.legacy', [
        'donation_id' => 'donation.legacy', 'donor_ref' => 'user:42', 'amount_minor' => 1000,
        'currency' => 'USD', 'purpose_code' => 'support', 'recurring' => true,
        'receipt_ref' => null, 'state' => 'settled', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $result = (new BillingQueryService($repo))->forActor('user:42');
    same(false, array_key_exists('subscriptions', $result) && $result['subscriptions'] !== []);
    same(false, $result['recurring_available']);
    same(1, count($result['donations']));
    same('donation.one', $result['donations'][0]['donation_id']);
};

$tests['donation privilege projection is rejected'] = static function (): void {
    $neutrality = new DonationNeutralityPolicy();
    $neutrality->assertNeutralProjection(['donation' => ['rank' => null, 'ai_priority' => false]]);
    throws(static fn () => $neutrality->assertNeutralProjection(['donation' => ['ranking_boost' => 1]]), InvariantViolation::class);
    $contract = $neutrality->publicContract();
    same(true, $contract['donor_and_non_donor_ai_equal']);
    same(true, $contract['donor_and_non_donor_education_equal']);
};

$tests['active manifests match release identity'] = static function (): void {
    $root = dirname(__DIR__);
    $contracts = json_decode((string)file_get_contents($root.'/manifests/cf03-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
    $release = json_decode((string)file_get_contents($root.'/manifests/cf03-release-1.3.0.json'), true, 512, JSON_THROW_ON_ERROR);
    $appeal = json_decode((string)file_get_contents($root.'/manifests/donation-appeal-contract.json'), true, 512, JSON_THROW_ON_ERROR);
    same('1.3.0-rc.1', $contracts['software_version']);
    same('4.0.0', $contracts['active_schema_version']);
    same('1.3.0-rc.1', $release['release']);
    same(7, $appeal['frequency']['minimum_days_between_appeals']);
    same(false, $appeal['recurring_available']);
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

function context(int $seconds): DonationPromptContext
{
    return new DonationPromptContext('pageview:newplan:1', 'normal', $seconds, false, false, false, false);
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
