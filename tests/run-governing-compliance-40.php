<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\FutureExpansionRegistry;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationNeutralityPolicy;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FutureFinancePolicy;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Persistence\CompleteSchema;

$source = static function(string $path): string {
    $bytes = file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($bytes)) { throw new RuntimeException('Unreadable source: '.$path); }
    return $bytes;
};
$rejects = static function(callable $f): bool {
    try { $f(); } catch (Throwable) { return true; }
    return false;
};
$policy = new PlatformFinancialPolicy();
$neutral = new DonationNeutralityPolicy();
$features = FutureExpansionRegistry::all();
$future = new FutureFinancePolicy();
$cases = [];
$add = static function(string $name, callable $f) use (&$cases): void { $cases[] = [$name,$f]; };
$add('01 | Current CF-03 v1.1 governing plan identity', static fn():bool => str_contains(PlatformFinancialPolicy::GOVERNING_CF03_PLAN,'v1.1-Future40'));
$add('02 | Single free platform tier', static fn():bool => $policy->allCoreServicesFree());
$add('03 | File 05 structured education remains free', static fn():bool => $policy->structuredEducationFree());
$add('04 | File 16 Sabri AI remains free', static fn():bool => $policy->aiCoreFree());
$add('05 | No fixed fee on core platform', static fn():bool => $policy->fixedFeesProhibited());
$add('06 | Zero commission for File 08/18', static fn():bool => $policy->platformCommissionBasisPoints()===0);
$add('07 | No mandatory or preselected donation amount', static fn():bool => $policy->defaultDonationAmount()===null);
$add('08 | No automatic recurring donation', static fn():bool => !$policy->recurringDonationAvailable() && $policy->oneTimeDonationOnly());
$add('09 | Appeal silence at least seven days', static fn():bool => PlatformFinancialPolicy::APPEAL_MINIMUM_DAYS===7);
$add('10 | Financial ownership is not donor-owned', static fn():bool => $policy->founderOwned() && !$policy->isTrust());
$add('11 | Donor and non-donor core capability parity', static fn():bool => $neutral->publicContract()['donor_and_non_donor_core_capabilities_equal']===true);
$add('12 | Donor and non-donor AI parity', static fn():bool => $neutral->publicContract()['donor_and_non_donor_ai_equal']===true);
$add('13 | Donor and non-donor education parity', static fn():bool => $neutral->publicContract()['donor_and_non_donor_education_equal']===true);
$add('14 | File 26 ranking must ignore donation', static fn():bool => $neutral->publicContract()['file26_ranking_must_ignore_donation']===true);
$add('15 | Nested donation entitlement injection rejected', static fn():bool => $rejects(static fn()=>$neutral->assertNeutralProjection(['nested'=>['entitlement'=>'premium']])));
$add('16 | Privileged support signal rejected', static fn():bool => $rejects(static fn()=>$neutral->assertNeutralProjection(['support_priority'=>10])));
$add('17 | Active schema generation version 4.0.0', static fn():bool => CompleteSchema::VERSION==='4.0.0');
$add('18 | Exactly 27 active canonical tables', static fn():bool => count(CompleteSchema::tables(''))===27);
$add('19 | No canonical recurring mandate table', static fn():bool => !isset(CompleteSchema::tables('')['recurring_consents']));
$add('20 | No canonical subscription table', static fn():bool => !isset(CompleteSchema::tables('')['subscriptions']));
$add('21 | No canonical paid AI usage-authorization table', static fn():bool => !isset(CompleteSchema::tables('')['usage_authorizations']));
$add('22 | Exactly 40 future features are registered', static fn():bool => count($features)===40);
$add('23 | No Future40 feature auto-activated by code presence', static fn():bool => count(array_filter($features,static fn(array $f):bool=>($f['activated']??true)!==false))===0);
$add('24 | FX-38 Sharia classification conditional', static fn():bool => $features['FX-38']['maturity']==='coded_conditional_change_control');
$add('25 | FX-39 Waqf/grants conditional', static fn():bool => $features['FX-39']['maturity']==='coded_conditional_change_control');
$add('26 | Future financial model remains voluntary one-time', static fn():bool => FutureFinancePolicy::ACTIVE_DONATION_MODEL==='voluntary_one_time_only');
$add('27 | AI cannot become paid via Future40', static fn():bool => $future->constitutionalLock()['paid_core_allowed']===false);
$add('28 | Future pack commission is zero', static fn():bool => $future->constitutionalLock()['platform_commission_basis_points']===0);
$add('29 | Recurring request rejected at intent constructor', static fn():bool => $rejects(static fn()=>new DonationIntentDraft('intent:gov:1','user:1',new Money(1000,'USD'),true,false,DonationServiceState::SANDBOX,'idem-govern-00000001',new DateTimeImmutable('now'))));
$add('30 | Preparing runtime blocks donation checkout', static fn():bool => $rejects(static fn()=>RuntimeConfiguration::preparing()->assertDonationCheckoutReady()));
$add('31 | Canonical approved donation product guard present before provider call', static fn():bool => strpos($source('src/Application/DonationCheckoutService.php'),'ApprovedDonationCatalogGate')<strpos($source('src/Application/DonationCheckoutService.php'),'createHostedDonationCheckout('));
$add('32 | Approved product provisioning occurs after schema validation', static fn():bool => strpos($source('src/Plugin.php'),'new DonationCatalogSeeder()')>strpos($source('src/Plugin.php'),'WordPressSchemaInstaller::install()'));
$add('33 | Financial platform activation stays preparing by default', static fn():bool => str_contains($source('src/Plugin.php'),"OPTION_MODE, 'preparing'"));
$add('34 | Provider idempotency namespace is opaque per intent', static fn():bool => str_contains($source('src/Application/DonationIntentDraft.php'),'donation-provider|'));
$add('35 | Expired financial outbox lease recovery exists', static fn():bool => str_contains($source('src/Application/OutboxDispatcher.php'),'recoverExpiredLeases('));
$add('36 | Silent no-consumer delivery is prohibited', static fn():bool => str_contains($source('src/Infrastructure/WordPressOutboxTransport.php'),'has_action(self::EVENT_HOOK) === false'));
$add('37 | File 20/25 presentation integration readiness gate', static fn():bool => str_contains($source('src/Application/RuntimeConfiguration.php'),'file20_file25_contract'));
$add('38 | File 24 independent financial assurance gate', static fn():bool => str_contains($source('src/Application/RuntimeConfiguration.php'),'file24_assurance'));
$add('39 | Current central Sabri Green #087A4E enforced', static fn():bool => str_contains($source('assets/css/public.css'),'--sabri-green:#087A4E'));
$add('40 | No recurring UI action; explicit voluntary consent', static fn():bool => str_contains($source('assets/js/public.js'),'one_time_consent:true')&&!str_contains($source('assets/js/public.js'),'recurring:true'));

if (count($cases)!==40) { throw new RuntimeException('Exactly forty governing compliance checks are required.'); }
foreach ($cases as $entry) {
    [$name,$probe]=$entry;
    try { $passed=$probe(); }
    catch (Throwable $e) { throw new RuntimeException('FAIL: '.$name.' | '.$e::class,0,$e); }
    if ($passed!==true) { throw new RuntimeException('FAIL: '.$name); }
    fwrite(STDOUT,'PASS '.$name."\n");
}
fwrite(STDOUT,"CF-03 central/CF plan governance checks: 40/40 passed (source and unit invariants, NOT Live acceptance).\n");
