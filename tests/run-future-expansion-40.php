<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FutureDonationExperienceService;
use Sabri\CF03\Application\FutureExpansionRegistry;
use Sabri\CF03\Application\FutureGovernancePrivacyService;
use Sabri\CF03\Application\FutureIntegrationSustainabilityService;
use Sabri\CF03\Application\FutureOperationsIntelligenceService;
use Sabri\CF03\Domain\FutureFinancePolicy;

$tests = 0;

$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};

$throws = static function (callable $callback, string $message) use (&$tests): void {
    $tests++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('FAIL: expected exception — '.$message);
};

FutureExpansionRegistry::assertIntegrity();
$features = FutureExpansionRegistry::all();
$assert(count($features) === 40, 'exactly 40 Future Expansion Pack entries');
$assert(array_keys($features) === array_map(static fn(int $n): string => sprintf('FX-%02d', $n), range(1, 40)), 'IDs are exact FX-01 through FX-40');
foreach ($features as $feature) {
    $assert($feature['activated'] === false, 'future capability defaults fail closed');
    $assert($feature['donor_privilege_allowed'] === false, 'donor privilege prohibited');
    $assert($feature['recurring_donation_allowed'] === false, 'recurring donation prohibited');
    $assert($feature['paid_core_allowed'] === false, 'paid core prohibited');
    $assert($feature['platform_commission_basis_points'] === 0, 'platform commission remains zero');
}
$assert($features['FX-38']['maturity'] === 'coded_conditional_change_control', 'Sharia classification remains conditional');
$assert($features['FX-39']['maturity'] === 'coded_conditional_change_control', 'waqf/grant remains conditional');

$policy = new FutureFinancePolicy();
$lock = $policy->constitutionalLock();
$assert($lock['single_free_core_tier'] === true, 'free core locked');
$assert($lock['donation_type'] === 'one_time', 'one-time donation locked');
$assert($lock['future_pack_activated_by_code_presence'] === false, 'code presence does not activate future features');

$donation = new FutureDonationExperienceService();
$purposes = $donation->purposeCatalogue([
    ['purpose_id' => 'research', 'name' => 'Research', 'accounting_category' => 'research'],
    ['purpose_id' => 'server', 'name' => 'Infrastructure', 'accounting_category' => 'infrastructure'],
]);
$assert(count($purposes) === 2 && $purposes[0]['preselected'] === false, 'FX-01 purpose catalogue has no preselection');
$throws(fn() => $donation->purposeCatalogue([['purpose_id' => 'x', 'name' => 'X', 'entitlement_mapping' => 'premium']]), 'FX-01 rejects entitlement mapping');

$reminder = $donation->reminderPreference('never', new \DateTimeImmutable('2026-09-08T00:00:00+00:00'));
$assert($reminder['next_eligible_at'] === null, 'FX-02 never-remind preference');
$reminder7 = $donation->reminderPreference('seven_days', new \DateTimeImmutable('2026-09-08T00:00:00+00:00'));
$assert(str_starts_with((string)$reminder7['next_eligible_at'], '2026-09-15'), 'FX-02 seven-day minimum');

$privacy = $donation->privacyPreference('aggregate_only');
$assert($privacy['public_identity'] === false && $privacy['behavioral_profile_created'] === false, 'FX-03 privacy control');

$vault = $donation->receiptVaultEntry('receipt.1', ['amount_minor' => 1000, 'currency' => 'USD']);
$assert(strlen($vault['snapshot_sha256']) === 64 && $vault['immutable'] === true, 'FX-04 receipt vault');
$throws(fn() => $donation->receiptVaultEntry('receipt.2', ['cvv' => '123']), 'FX-04 secret rejection');

$secret = str_repeat('s', 32);
$token = $donation->receiptVerificationToken('receipt.1', $vault['snapshot_sha256'], $secret);
$assert($donation->verifyReceiptToken('receipt.1', $vault['snapshot_sha256'], $secret, $token), 'FX-05 receipt authenticity');

$transparency = $donation->transparencySnapshot(
    [['amount_minor' => 1000, 'currency' => 'USD'], ['amount_minor' => 500, 'currency' => 'USD']],
    [['amount_minor' => 200, 'currency' => 'USD']],
    [['amount_minor' => 30, 'currency' => 'USD']]
);
$assert($transparency['received']['USD'] === 1500 && $transparency['donor_identity_included'] === false, 'FX-06 transparency aggregation');

$use = $donation->useOfFundsReport([
    ['purpose_id' => 'research', 'amount_minor' => 400, 'currency' => 'USD'],
    ['purpose_id' => 'research', 'amount_minor' => 600, 'currency' => 'USD'],
]);
$assert($use['allocations']['USD']['research'] === 1000 && $use['accounting_truth_separate'] === true, 'FX-07 use-of-funds report');

$currency = $donation->currencyContract('PKR', ['USD','PKR'], ['PKR']);
$assert($currency['allowed'] === true && $currency['automatic_fx'] === false, 'FX-08 multi-currency gate');

$ops = new FutureOperationsIntelligenceService();
$providers = [
    ['code' => 'p1', 'approved' => true, 'healthy' => true, 'currencies' => ['USD'], 'jurisdictions' => ['PK'], 'priority' => 10, 'latency_ms' => 200],
    ['code' => 'p2', 'approved' => true, 'healthy' => true, 'currencies' => ['USD'], 'jurisdictions' => ['PK'], 'priority' => 20, 'latency_ms' => 300],
];
$selected = $ops->selectProvider($providers, 'USD', 'PK');
$assert($selected['provider'] === 'p2' && $selected['donor_status_considered'] === false, 'FX-09 provider selection');

$health = $ops->providerHealth([['code' => 'p1', 'healthy' => true, 'error_rate_basis_points' => 12]]);
$assert($health['providers']['p1']['secrets_redacted'] === true, 'FX-10 provider health');

$failover = $ops->failoverProvider('p1', 'new_intent', $providers, 'USD', 'PK');
$assert($failover['provider'] === 'p2' && $failover['existing_intent_moved'] === false, 'FX-11 safe failover');
$throws(fn() => $ops->failoverProvider('p1', 'provider_created', $providers, 'USD', 'PK'), 'FX-11 blocks existing intent migration');

$forensics = $ops->webhookForensics('evt.1', '{"ok":true}', ['Authorization' => 'secret', 'X-Signature' => 'abc'], 'settled');
$assert(!isset($forensics['safe_headers']['authorization']) && isset($forensics['safe_headers']['x-signature']), 'FX-12 webhook forensics redaction');

$uncertain = $ops->uncertainResolution('settled', false, true);
$assert($uncertain['state'] === 'uncertain' && $uncertain['final'] === false, 'FX-13 uncertainty retained');

$queue = $ops->reconciliationQueue([['exception_id' => 'e1', 'type' => 'amount_mismatch', 'material' => true, 'state' => 'open']]);
$assert($queue['close_blocked'] === true, 'FX-14 reconciliation queue blocks close');

$confidence = $ops->reconciliationConfidence(99, 100, 1);
$assert($confidence['confidence_basis_points'] === 9900 && $confidence['public_trust_badge'] === false, 'FX-15 reconciliation confidence');

$close = $ops->financeCloseChecklist([
    'settlements_imported' => true,
    'material_exceptions_resolved' => true,
    'refunds_reconciled' => true,
    'chargebacks_accounted' => true,
    'audit_complete' => true,
    'backup_verified' => true,
    'reviewer_signed' => true,
    'approver_signed' => true,
]);
$assert($close['ready_to_close'] === true, 'FX-16 close checklist');

$approval = $ops->dualApproval('requester', 'reviewer', 'approver', 'executor');
$assert($approval['separation_of_duties'] === true, 'FX-17 dual approval');
$throws(fn() => $ops->dualApproval('same', 'same', 'approver'), 'FX-17 toxic actor combination rejected');

$refundPreview = $ops->refundEligibilityPreview(1000, 200, 500, true);
$assert($refundPreview['eligible_for_review'] === true && $refundPreview['approval_promised'] === false, 'FX-18 refund preview');

$sla = $ops->refundSlaTimeline('provider_pending', new \DateTimeImmutable('2026-09-08T00:00:00+00:00'));
$assert($sla['provider_completion_estimate'] === null, 'FX-19 no fabricated refund estimate');

$chargeback = $ops->chargebackEvidencePackage('case.1', ['intent_id' => 'i1', 'receipt_hash' => str_repeat('a', 64), 'clinical_notes' => 'secret']);
$assert(!isset($chargeback['evidence']['clinical_notes']) && $chargeback['clinical_data_included'] === false, 'FX-20 privacy-minimized dispute evidence');

$gov = new FutureGovernancePrivacyService();
$privacyCenter = $gov->financialPrivacyCenter(['receipt' => ['purpose' => 'financial_record', 'retention' => '7y']]);
$assert(isset($privacyCenter['categories']['receipt']) && $privacyCenter['provider_secrets_exposed'] === false, 'FX-21 privacy center');

$userExport = $gov->userFinanceExport('user:1', ['receipt','refund','unknown'], 3600);
$assert($userExport['types'] === ['receipt','refund'] && $userExport['encrypted'] === true, 'FX-22 user export');

$accountant = $gov->accountantExport(['transaction_id','amount_minor','password'], 'USD', '2026-01-01', '2026-12-31');
$assert(!in_array('password', $accountant['fields'], true) && $gov->neutralizeSpreadsheetCell('=1+1') === "'=1+1", 'FX-23 accountant export safety');

$hashes = ['ledger' => str_repeat('a',64), 'reconciliation' => str_repeat('b',64), 'configuration' => str_repeat('c',64), 'backup' => str_repeat('d',64)];
$audit = $gov->auditEvidencePackage('2026-09', $hashes, ['reviewer','approver']);
$assert(strlen($audit['package_sha256']) === 64 && $audit['immutable'] === true, 'FX-24 immutable audit package');

$config = $gov->configurationVersion('cfg.1', ['currency' => 'USD', 'purpose' => 'general'], 'approval.1');
$assert(strlen($config['snapshot_sha256']) === 64, 'FX-25 configuration history');

$sim = $gov->policySimulation(['currencies' => ['USD'], 'countries' => ['PK']], [['currency' => 'USD', 'country' => 'PK']]);
$assert($sim['simulation_only'] === true && $sim['production_mutation'] === false && $sim['results'][0]['would_allow'] === true, 'FX-26 policy simulation');

$sandbox = $gov->sandboxScenario('duplicate_webhook');
$assert($sandbox['real_money_allowed'] === false, 'FX-27 sandbox laboratory');

$ready = $gov->deploymentReadiness([
    'provider'=>true,'webhook'=>true,'legal'=>true,'tax'=>true,'accounting'=>true,'pci'=>true,'security'=>true,
    'staging'=>true,'cross_file_integration'=>true,'backup_restore'=>true,'rollback'=>true,'founder_approval'=>true,
]);
$assert($ready['ready'] === true && $ready['collection_fail_closed'] === false, 'FX-28 deployment readiness');

$kill = $gov->killSwitchState(['new_donations' => true, 'webhooks' => true]);
$assert($kill['new_donations'] === true && $kill['refund_execution'] === false, 'FX-29 independent kill switches');

$restore = $gov->restoreVerification(
    ['ledger_count'=>1,'ledger_hash'=>'a','receipt_count'=>1,'receipt_hash'=>'b','outbox_count'=>1,'outbox_hash'=>'c','dedupe_count'=>1,'dedupe_hash'=>'d'],
    ['ledger_count'=>1,'ledger_hash'=>'a','receipt_count'=>1,'receipt_hash'=>'b','outbox_count'=>1,'outbox_hash'=>'c','dedupe_count'=>1,'dedupe_hash'=>'d']
);
$assert($restore['verified'] === true && $restore['writes_may_reopen'] === true, 'FX-30 restore verification');

$integration = new FutureIntegrationSustainabilityService();
$analytics = $integration->privacyPreservingAnalytics([['checkout_attempts'=>10,'checkout_successes'=>8,'refunds_completed'=>1,'reconciliation_exceptions'=>0]]);
$assert($analytics['metrics']['checkout_attempts'] === 10 && $analytics['donor_profiling'] === false, 'FX-31 privacy analytics');
$throws(fn() => $integration->privacyPreservingAnalytics([['actor_ref'=>'user:1']]), 'FX-31 rejects identifiers');

$a11y = $integration->accessibilityContract();
$assert($a11y['rtl_urdu'] === true && $a11y['screen_reader'] === true, 'FX-32 accessibility contract');

$support = $integration->supportBridge(['case_ref'=>'c1','state'=>'pending','provider_secret'=>'x']);
$assert(!isset($support['case']['provider_secret']) && $support['ledger_mutation_allowed'] === false, 'FX-33 support bridge');

$notifications = $integration->notificationPreferences(false);
$assert($notifications['donation_appeals'] === false && $notifications['transaction_receipts'] === true, 'FX-34 notification preferences');

$event = $integration->financeEventEnvelope('DonationSettled','d1','t1',['amount_minor'=>1000]);
$assert($event['entitlement_command'] === false && strlen($event['payload_sha256']) === 64, 'FX-35 event explorer envelope');
$throws(fn() => $integration->financeEventEnvelope('DonationSettled','d1','t1',['grant_access'=>true]), 'FX-35 rejects entitlement command');

$jurisdiction = $integration->jurisdictionRule('PK',['PKR','USD'],['p1'],['refund_terms'],true);
$assert($jurisdiction['country'] === 'PK' && $jurisdiction['launch_approved'] === true, 'FX-36 jurisdiction registry');

$legal = $integration->taxLegalDisclosure('PK','v1','Donation receipt',true,false);
$assert($legal['blanket_global_claim'] === false, 'FX-37 legal disclosure');
$throws(fn() => $integration->taxLegalDisclosure('PK','v1','Tax deductible',false,true), 'FX-37 tax claim fails without legal approval');

$sharia = $integration->shariaClassification('zakat',false,false,false);
$assert($sharia['enabled'] === false && $sharia['separate_eligibility_rules_required'] === true, 'FX-38 Sharia layer fail closed');
$general = $integration->shariaClassification('general_donation',false,false,false);
$assert($general['enabled'] === true, 'FX-38 current general donation remains available under current law');

$sustainability = $integration->sustainabilityModule('waqf',false,false);
$assert($sustainability['enabled'] === false && $sustainability['change_control_required'] === true, 'FX-39 waqf/grant fail closed');

$command = $integration->founderCommandCenter(
    ['healthy'=>true],
    ['confidence_basis_points'=>10000],
    ['open'=>0],
    ['ready_to_close'=>true],
    ['ready'=>false],
    ['new_donations'=>true]
);
$assert($command['direct_ledger_edit'] === false && $command['command_scope'] === 'operational_governance_only', 'FX-40 Founder command center');

fwrite(STDOUT, "CF-03 Future Expansion Pack 40: {$tests} assertions passed.\n");
