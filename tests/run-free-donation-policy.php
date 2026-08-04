<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\DonationPromptService;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\DonationFrequencyPreference;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptContext;
use Sabri\CF03\Domain\DonationPromptPolicy;
use Sabri\CF03\Domain\DonationPromptState;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Infrastructure\MemoryDonationPromptStateStore;

$tests = [];
$now = new DateTimeImmutable('2026-08-04T11:39:00+05:00');

$tests['paid services are suspended'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    $product = new FinancialProduct('education.membership.monthly', ProductKind::EDUCATION_MEMBERSHIP, BillingType::RECURRING, 'file00.membership', 'education.structured', 'refund.education.v1', 'cancel.education.v1', true, true, 'approval.product.education.1');
    throws(static fn () => $policy->assertCollectibleProduct($product), DomainException::class);
};
$tests['donation remains the only collectible product kind'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    $donation = new FinancialProduct('donation.general', ProductKind::DONATION, BillingType::VOLUNTARY, 'cf03.finance', null, 'refund.donation.v1', 'cancel.donation.v1', true, true, 'approval.donation.1');
    $policy->assertCollectibleProduct($donation);
};
$tests['suggested amounts exact and no defaults'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    same([1000, 1400, 5000], array_map(static fn (Money $money): int => $money->minorUnits(), $policy->suggestedDonationAmounts()));
    same(null, $policy->defaultDonationAmount());
    same(false, $policy->defaultRecurringDonation());
};
$tests['platform fee remains zero'] = static function (): void {
    $policy = new PlatformFinancialPolicy();
    same(0, $policy->platformCommissionBasisPoints());
    throws(static fn () => $policy->assertNoPlatformFee(1), DomainException::class);
};
$tests['appeal contract has exact guest keys and no preselection'] = static function (): void {
    $contract = DonationAppealCopy::contract();
    same(['sabri_donation_prompt_seen', 'sabri_donation_prompt_next_at'], $contract['guest_storage']['keys']);
    same(null, $contract['preselected_amount']);
    same(false, $contract['monthly_checkbox']['checked']);
    same(['Support Now', 'Remind Me Later', 'Not Now', 'Close'], $contract['actions']);
};
$tests['prompt waits for engagement'] = static function () use ($now): void {
    $decision = (new DonationPromptPolicy())->decide(new DonationPromptState(), context(29), $now);
    same(false, $decision->shouldShow());
    same('engagement_threshold_not_met', $decision->reason());
};
$tests['prompt shows after thirty seconds'] = static function () use ($now): void {
    same(true, (new DonationPromptPolicy())->decide(new DonationPromptState(), context(30), $now)->shouldShow());
};
$tests['meaningful interaction can satisfy engagement'] = static function () use ($now): void {
    same(true, (new DonationPromptPolicy())->decide(new DonationPromptState(), context(1, true), $now)->shouldShow());
};
$tests['sensitive contexts are blocked'] = static function () use ($now): void {
    foreach (['login', 'registration', 'password_recovery', 'guardian_consent', 'clinical_consultation', 'emergency_warning', 'support_appeal', 'payment_error'] as $page) {
        same(false, (new DonationPromptPolicy())->decide(new DonationPromptState(), context(60, true, $page), $now)->shouldShow());
    }
};
$tests['page view cap is enforced'] = static function () use ($now): void {
    same('page_view_cap', (new DonationPromptPolicy())->decide(new DonationPromptState(), context(60, true, 'normal', true), $now)->reason());
};
$tests['checkout failure suppresses same session'] = static function () use ($now): void {
    same('checkout_failed_this_session', (new DonationPromptPolicy())->decide(new DonationPromptState(), context(60, true, 'normal', false, true), $now)->reason());
};
$tests['shown prompt creates seven-day cap'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::SHOWN, $now);
    $decision = (new DonationPromptPolicy())->decide($state, context(60), $now->modify('+6 days 23 hours'));
    same(false, $decision->shouldShow());
    same($now->modify('+7 days')->format(DATE_ATOM), $decision->nextEligibleAt()?->format(DATE_ATOM));
};
$tests['remind later snoozes seven days'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::REMIND_LATER, $now);
    same($now->modify('+7 days')->format(DATE_ATOM), $state->snoozedUntil()?->format(DATE_ATOM));
};
$tests['not now snoozes seven days'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::NOT_NOW, $now);
    same($now->modify('+7 days')->format(DATE_ATOM), $state->snoozedUntil()?->format(DATE_ATOM));
};
$tests['close snoozes seven days'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::CLOSE, $now);
    same($now->modify('+7 days')->format(DATE_ATOM), $state->snoozedUntil()?->format(DATE_ATOM));
};
$tests['completed donation suppresses thirty days'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::DONATION_COMPLETED_ONE_TIME, $now);
    $decision = (new DonationPromptPolicy())->decide($state, context(60), $now->modify('+29 days'));
    same(false, $decision->shouldShow());
    same($now->modify('+30 days')->format(DATE_ATOM), $decision->nextEligibleAt()?->format(DATE_ATOM));
};
$tests['monthly donor is always suppressed'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::DONATION_COMPLETED_MONTHLY, $now);
    $decision = (new DonationPromptPolicy())->decide($state, context(60), $now->modify('+365 days'));
    same(false, $decision->shouldShow());
    same('active_monthly_donor', $decision->reason());
    same(DonationFrequencyPreference::MONTHLY_ACTIVE, $state->frequencyPreference());
};
$tests['state round trip preserves requested fields'] = static function () use ($now): void {
    $state = (new DonationPromptState())->apply(DonationPromptAction::SHOWN, $now)->apply(DonationPromptAction::NOT_NOW, $now);
    $restored = DonationPromptState::fromStorage($state->toStorage());
    same($state->toStorage(), $restored->toStorage());
};
$tests['service persists prompt actions'] = static function () use ($now): void {
    $store = new MemoryDonationPromptStateStore();
    $service = new DonationPromptService($store, new DonationPromptPolicy(), static fn (): DateTimeImmutable => $now);
    $service->record('user:42', DonationPromptAction::SHOWN);
    same(false, $service->decision('user:42', context(60))->shouldShow());
};
$tests['monthly consent must be explicit'] = static function () use ($now): void {
    throws(static fn () => new DonationIntentDraft('intent:1', 'user:42', new Money(1000, 'USD'), true, false, DonationServiceState::SANDBOX, 'idem-donation-0001', $now), DomainException::class);
};
$tests['preparing state blocks provider checkout'] = static function () use ($now): void {
    $draft = new DonationIntentDraft('intent:1', 'user:42', new Money(1000, 'USD'), false, false, DonationServiceState::PREPARING, 'idem-donation-0001', $now);
    throws(static fn () => $draft->assertProviderCheckoutAvailable(), DomainException::class);
};
$tests['safe donation payload excludes donor reference'] = static function () use ($now): void {
    $draft = new DonationIntentDraft('intent:1', 'user:42', new Money(1400, 'USD'), true, true, DonationServiceState::SANDBOX, 'idem-donation-0001', $now);
    $payload = $draft->toSafePayload();
    same(false, array_key_exists('donor_reference', $payload));
    same(1400, $payload['amount_minor_units']);
    same(true, $payload['monthly']);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function context(int $seconds, bool $interaction = false, string $page = 'normal', bool $shown = false, bool $failed = false): DonationPromptContext
{
    return new DonationPromptContext('pageview:12345', $page, $seconds, $interaction, $shown, $failed);
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) { throw new RuntimeException(var_export($expected, true) . ' !== ' . var_export($actual, true)); }
}
/** @param class-string<Throwable> $class */
function throws(callable $callback, string $class): void
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected ' . $class . ', got ' . $error::class);
    }
    throw new RuntimeException('Expected ' . $class);
}
