<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\AuditChain;
use Sabri\CF03\Application\LedgerJournal;
use Sabri\CF03\Application\OutboxMessage;
use Sabri\CF03\Application\PaymentConfirmationService;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Application\ReconciliationEngine;
use Sabri\CF03\Application\RestoreReconciliation;
use Sabri\CF03\Application\RouteCatalogue;
use Sabri\CF03\Domain\AiUsageAuthorization;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationFinancialFactType;
use Sabri\CF03\Domain\DonationRecord;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Domain\FinancePeriod;
use Sabri\CF03\Domain\FinancialActor;
use Sabri\CF03\Domain\FinancialAdjustment;
use Sabri\CF03\Domain\FinancialCapability;
use Sabri\CF03\Domain\FinancialEventEnvelope;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntent;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PriceLifecycle;
use Sabri\CF03\Domain\PriceVersion;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\ProductLifecycle;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\RecurringConsent;
use Sabri\CF03\Domain\RefundRequest;
use Sabri\CF03\Domain\RetentionSchedule;
use Sabri\CF03\Domain\SecureExportJob;
use Sabri\CF03\Domain\SeparationOfDutiesPolicy;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Domain\TaxMode;
use Sabri\CF03\Domain\TrustedDonationFact;
use Sabri\CF03\Infrastructure\NullPaymentProvider;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Persistence\MigrationRunner;
use Sabri\CF03\Persistence\Schema;

$now = new DateTimeImmutable('2026-08-04T14:27:00+05:00');
$tests = [];

$tests['01 actor capability with recent auth'] = static function () use ($now): void {
    (new FinancialActor('operator:1', [FinancialCapability::OPERATE_PAYMENTS], false, $now->modify('-5 minutes')))
        ->assertCan(FinancialCapability::OPERATE_PAYMENTS, $now, 600);
};
$tests['02 actor missing capability fails'] = static fn () => expectPlan(
    static fn () => (new FinancialActor('operator:1', [FinancialCapability::VIEW_AUDIT]))->assertCan(FinancialCapability::OPERATE_PAYMENTS),
    DomainException::class
);
$tests['03 suspended actor fails closed'] = static fn () => expectPlan(
    static fn () => (new FinancialActor('operator:1', [FinancialCapability::VIEW_AUDIT], true))->assertCan(FinancialCapability::VIEW_AUDIT),
    DomainException::class
);
$tests['04 toxic capabilities rejected'] = static fn () => expectPlan(
    static fn () => (new SeparationOfDutiesPolicy())->assertNoToxicCombination([FinancialCapability::REVIEW_REFUNDS, FinancialCapability::EXECUTE_REFUNDS]),
    DomainException::class
);
$tests['05 refund duties require distinct actors'] = static fn () => expectPlan(
    static fn () => (new SeparationOfDutiesPolicy())->assertRefundWorkflow('actor:a', 'actor:b', 'actor:b'),
    DomainException::class
);
$tests['06 donation product lifecycle activates'] = static function () use ($now): void {
    $life = new ProductLifecycle(donationProductPlan());
    $life->stage('stager:1', 1); $life->approve('approver:1', 2); $life->activate('activator:1', $now, 3);
    samePlan('active', $life->state());
};
$tests['07 product stager cannot approve'] = static function (): void {
    $life = new ProductLifecycle(donationProductPlan()); $life->stage('operator:1', 1);
    expectPlan(static fn () => $life->approve('operator:1', 2), DomainException::class);
};
$tests['08 paid product activation suspended'] = static function () use ($now): void {
    $life = new ProductLifecycle(educationProductPlan()); $life->stage('stager:1', 1); $life->approve('approver:1', 2);
    expectPlan(static fn () => $life->activate('activator:1', $now, 3), DomainException::class);
};
$tests['09 effective approved price activates'] = static function () use ($now): void {
    $life = new PriceLifecycle(pricePlan('price:1', $now->modify('-1 day'), null));
    $life->recordStager('stager:1'); $life->approve('approver:1', 1); $life->activate('activator:1', $now, 2);
    samePlan('active', $life->state());
};
$tests['10 overlapping active price rejected'] = static function () use ($now): void {
    $first = new PriceLifecycle(pricePlan('price:1', $now->modify('-2 days'), $now->modify('+2 days')));
    $first->recordStager('stager:1'); $first->approve('approver:1', 1); $first->activate('activator:1', $now, 2);
    $second = new PriceLifecycle(pricePlan('price:2', $now->modify('-1 day'), null));
    $second->recordStager('stager:2'); $second->approve('approver:2', 1);
    expectPlan(static fn () => $second->activate('activator:2', $now, 2, [$first]), DomainException::class);
};
$tests['11 provider reference immutable'] = static function () use ($now): void {
    $intent = intentPlan($now); $intent->attachProviderReference('provider-payment:1', 1, $now);
    expectPlan(static fn () => $intent->attachProviderReference('provider-payment:2', 2, $now), DomainException::class);
};
$tests['12 stale payment version rejected'] = static function () use ($now): void {
    expectPlan(static fn () => intentPlan($now)->transition(PaymentIntentState::PROVIDER_PENDING, 2, $now), DomainException::class);
};
$tests['13 trusted state requires provider reference'] = static function () use ($now): void {
    $intent = intentPlan($now); $intent->transition(PaymentIntentState::PROVIDER_PENDING, 1, $now);
    expectPlan(static fn () => $intent->transition(PaymentIntentState::SETTLED, 2, $now), DomainException::class);
};
$tests['14 failed intent records safe code'] = static function () use ($now): void {
    $intent = intentPlan($now); $intent->transition(PaymentIntentState::PROVIDER_PENDING, 1, $now); $intent->transition(PaymentIntentState::FAILED, 2, $now, 'provider_declined');
    samePlan('provider_declined', $intent->snapshot()['failure_code']);
};
$tests['15 raw HMAC webhook verifies exact money'] = static function () use ($now): void {
    $secret = str_repeat('s', 32); $body = webhookBodyPlan($now); $signature = hash_hmac('sha256', $now->getTimestamp() . '.' . $body, $secret);
    $evidence = (new ProviderWebhookVerifier(static fn (): string => $secret, static fn (): bool => true))->verify('provider.sandbox', 'key:v1', $body, $signature, $now, $now);
    samePlan(1400, $evidence->amount()->minorUnits());
};
$tests['16 forged webhook rejected'] = static function () use ($now): void {
    $body = webhookBodyPlan($now); $verifier = new ProviderWebhookVerifier(static fn (): string => str_repeat('s', 32), static fn (): bool => true);
    expectPlan(static fn () => $verifier->verify('provider.sandbox', 'key:v1', $body, str_repeat('0', 64), $now, $now), DomainException::class);
};
$tests['17 replayed webhook rejected'] = static function () use ($now): void {
    $secret = str_repeat('s', 32); $body = webhookBodyPlan($now); $signature = hash_hmac('sha256', $now->getTimestamp() . '.' . $body, $secret);
    $verifier = new ProviderWebhookVerifier(static fn (): string => $secret, static fn (): bool => false);
    expectPlan(static fn () => $verifier->verify('provider.sandbox', 'key:v1', $body, $signature, $now, $now), DomainException::class);
};
$tests['18 payment confirmation writes ledger and event'] = static function () use ($now): void {
    $intent = readyIntentPlan($now); $ledger = ledgerPlan('ledger:1', 1400); $written = []; $events = [];
    $service = new PaymentConfirmationService(
        static fn (callable $work): mixed => $work(),
        static function (LedgerTransaction $transaction) use (&$written): void { $written[] = $transaction->transactionId(); },
        static function ($event) use (&$events): void { $events[] = $event->type(); }
    );
    $event = $service->confirmSettled($intent, evidencePlan($now, 'payment.settled', new Money(1400, 'USD')), $ledger, 3, $now, 'event:settled:1');
    samePlan('PaymentSettled', $event->type()); samePlan(['ledger:1'], $written); samePlan(['PaymentSettled'], $events);
};
$tests['19 settlement totals enforce net'] = static function () use ($now): void { samePlan(8500, settlementPlan($now)->net()->minorUnits()); };
$tests['20 duplicate settlement line rejected'] = static function () use ($now): void {
    expectPlan(static fn () => new SettlementBatch('batch:2', 'provider.sandbox', new Money(10000, 'USD'), new Money(500, 'USD'), new Money(1000, 'USD'), new Money(8500, 'USD'), $now, str_repeat('a', 64), [
        ['reference'=>'payment:1','type'=>'payment','amount_minor'=>10000,'currency'=>'USD'],
        ['reference'=>'payment:1','type'=>'fee','amount_minor'=>500,'currency'=>'USD'],
        ['reference'=>'refund:1','type'=>'refund','amount_minor'=>1000,'currency'=>'USD'],
    ]), DomainException::class);
};
$tests['21 reconciliation exact match'] = static function () use ($now): void {
    $batch = settlementPlan($now); samePlan(0, (new ReconciliationEngine())->reconcile($batch, $batch->lines())->count());
};
$tests['22 reconciliation mismatch material'] = static function () use ($now): void {
    $batch = settlementPlan($now); $lines = $batch->lines(); $lines[0]['amount_minor'] = 9000;
    samePlan(false, (new ReconciliationEngine())->reconcile($batch, $lines, ['USD'=>100])->mayClose());
};
$tests['23 ledger source idempotency'] = static function () use ($now): void {
    $journal = new LedgerJournal(); $journal->post(ledgerPlan('ledger:1', 1400), 'payment', 'intent:1', 'operator:1', 'settlement', '2026-08', $now, $now);
    expectPlan(static fn () => $journal->post(ledgerPlan('ledger:2', 1400), 'payment', 'intent:1', 'operator:2', 'retry', '2026-08', $now, $now), DomainException::class);
};
$tests['24 ledger account balances'] = static function () use ($now): void {
    $journal = new LedgerJournal(); $journal->post(ledgerPlan('ledger:1', 1400), 'payment', 'intent:1', 'operator:1', 'settlement', '2026-08', $now, $now);
    samePlan(['asset.cash'=>1400,'income.donation'=>-1400], $journal->accountBalances('USD'));
};
$tests['25 recurring consent explicit'] = static function () use ($now): void {
    expectPlan(static fn () => new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,false), DomainException::class);
};
$tests['26 recurring terms parity'] = static function () use ($now): void {
    expectPlan(static fn () => recurringConsentPlan($now)->assertRenewalParity(new Money(1400,'USD'), 'month', str_repeat('a',64)), DomainException::class);
};
$tests['27 dunning respects quiet hours'] = static function (): void {
    $failed = new DateTimeImmutable('2026-08-04T20:30:00+05:00');
    samePlan('2026-08-05T08:00:00+05:00', (new DunningPolicy([3600,86400,259200,604800]))->nextRetryAt($failed,1,new DateTimeZone('Asia/Karachi'))->format(DATE_ATOM));
};
$tests['28 provider outage avoids user dunning'] = static function () use ($now): void {
    expectPlan(static fn () => (new DunningPolicy([3600,86400,259200,604800]))->nextRetryAt($now,1,new DateTimeZone('UTC'),true), DomainException::class);
};
$tests['29 AI usage signed and exact'] = static function () use ($now): void {
    $auth = new AiUsageAuthorization('auth:1','user:1','token',new Money(2,'USD'),100,new Money(200,'USD'),$now->modify('+1 day'),str_repeat('a',64));
    $secret = str_repeat('z',32); $payload = 'auth:1|usage:1|50|' . $now->format(DATE_ATOM);
    $auth->assertSignedUsageFact('usage:1',50,$now,hash_hmac('sha256',$payload,$secret),$secret); samePlan(100,$auth->priceFor(50,$now)->minorUnits());
};
$tests['30 AI usage beyond cap rejected'] = static function () use ($now): void {
    expectPlan(static fn () => new AiUsageAuthorization('auth:1','user:1','token',new Money(3,'USD'),100,new Money(200,'USD'),$now->modify('+1 day'),str_repeat('a',64)), DomainException::class);
};
$tests['31 refund uncertainty reconciliation lifecycle'] = static function (): void {
    $refund = new RefundRequest('refund:1','intent:1','user:1',new Money(1000,'USD'),'duplicate','requested',null,null,new Money(1400,'USD'));
    $refund->beginEligibilityReview('reviewer:1',1); $refund->approve('reviewer:1','eligible',2); $refund->markProviderPending('executor:1','provider-refund:1',3);
    $refund->markUncertain(4); $refund->succeed(5); $refund->reconcile(true,6); $refund->close(7); samePlan('closed',$refund->status());
};
$tests['32 refund balance enforced'] = static fn () => expectPlan(
    static fn () => new RefundRequest('refund:1','intent:1','user:1',new Money(1500,'USD'),'duplicate','requested',null,null,new Money(1400,'USD')),
    DomainException::class
);
$tests['33 chargeback closes after provider and ledger'] = static function () use ($now): void {
    $case = new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1400,'USD'),'fraud',$now,$now->modify('+2 days'));
    $case->requireEvidence($now,1); $case->submitEvidence(str_repeat('a',64),$now,2); $case->recordProviderAcceptance(3); $case->recordOutcome(false,new Money(100,'USD'),4); $case->markLedgerAdjusted(5); $case->close(6);
    samePlan('closed',$case->state());
};
$tests['34 late chargeback evidence rejected'] = static function () use ($now): void {
    $case = new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1400,'USD'),'fraud',$now,$now->modify('+1 hour'));
    expectPlan(static fn () => $case->submitEvidence(str_repeat('a',64),$now->modify('+2 hours'),1), DomainException::class);
};
$tests['35 fraud appeal uses fresh reviewer'] = static function () use ($now): void {
    $case = fraudPlan($now); $case->decide(false,'reviewer:1','risk confirmed',$now,1); $case->appeal('new evidence',2); $case->decide(true,'reviewer:2','appeal accepted',$now,3);
    samePlan('approved',$case->state());
};
$tests['36 prohibited fraud signal rejected'] = static function () use ($now): void {
    expectPlan(static fn () => new FraudReviewCase('risk:1','intent:1',[['code'=>'religion','weight'=>10,'evidence_ref'=>'evidence:1']],$now,$now->modify('+1 day')), InvalidArgumentException::class);
};
$tests['37 adjustment requires three actors'] = static function () use ($now): void {
    $adjustment = new FinancialAdjustment('adjust:1','ledger:1',new Money(100,'USD'),'correction',str_repeat('a',64),'requester:1',$now);
    $adjustment->approve('approver:1',1); expectPlan(static fn () => $adjustment->execute('approver:1',2), DomainException::class); $adjustment->execute('executor:1',2);
    samePlan('executed',$adjustment->state());
};
$tests['38 donation public projection private'] = static function () use ($now): void {
    $projection = (new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now))->publicProjection(true);
    samePlan(null,$projection['donor_reference']); samePlan([],$projection['privileges']); samePlan(false,$projection['supporter_acknowledgment']);
};
$tests['39 donation trusted settlement and receipt'] = static function () use ($now): void {
    $donation = donationReadyPlan($now); $fact = donationFactPlan($now, DonationFinancialFactType::ONE_TIME_COMPLETED, 'donation.settled');
    $donation->settle($fact,'provider-payment:1',3); $donation->issueReceipt('receipt:1',4); samePlan('receipt_issued',$donation->state());
};
$tests['40 donation refund requires refund fact'] = static function () use ($now): void {
    $donation = settledDonationPlan($now); $wrong = donationFactPlan($now, DonationFinancialFactType::ONE_TIME_COMPLETED, 'donation.settled');
    expectPlan(static fn () => $donation->refund($wrong,4), DomainException::class);
};
$tests['41 export allowlist blocks secret field'] = static function () use ($now): void {
    expectPlan(static fn () => new SecureExportJob('export:1','operator:1',['cvv'],[],100,$now->modify('+1 hour')), DomainException::class);
};
$tests['42 secure export lifecycle'] = static function () use ($now): void {
    $job = new SecureExportJob('export:1','operator:1',['transaction_id','amount_minor'],['currency'=>'USD'],100,$now->modify('+1 hour'));
    $job->start(1); $job->complete(str_repeat('a',64),'secure-object:1',2); $job->assertDownloadable($now); samePlan('ready',$job->state());
};
$tests['43 legal hold blocks deletion'] = static function () use ($now): void {
    expectPlan(static fn () => (new RetentionSchedule())->assertDeletionAllowed('guest_prompt_state',$now->modify('-100 days'),$now,true), DomainException::class);
};
$tests['44 expired guest prompt may delete'] = static function () use ($now): void {
    (new RetentionSchedule())->assertDeletionAllowed('guest_prompt_state',$now->modify('-100 days'),$now,false);
};
$tests['45 restore manifest parity succeeds'] = static function (): void {
    $manifest = ['ledger'=>['count'=>2,'hash'=>str_repeat('a',64)]]; samePlan(true,(new RestoreReconciliation())->verify($manifest,$manifest,['event:1'],['event:1'])['balanced']);
};
$tests['46 restore duplicate event rejected'] = static function (): void {
    $manifest = ['ledger'=>['count'=>2,'hash'=>str_repeat('a',64)]];
    expectPlan(static fn () => (new RestoreReconciliation())->verify($manifest,$manifest,['event:1'],['event:1','event:1']), DomainException::class);
};
$tests['47 finance close actors separated'] = static function (): void {
    $period = new FinancePeriod('2026-08'); $period->beginReconciliation(1);
    expectPlan(static fn () => $period->approveClose(new ReconciliationResult([]),'operator:1','operator:1',2), DomainException::class);
};
$tests['48 finance period closes and locks'] = static function () use ($now): void {
    $period = new FinancePeriod('2026-08'); $period->beginReconciliation(1); $period->approveClose(new ReconciliationResult([]),'reviewer:1','approver:1',2); $period->lock($now,3);
    samePlan(true,$period->closed());
};
$tests['49 command-like event rejected'] = static function () use ($now): void {
    expectPlan(static fn () => new FinancialEventEnvelope('event:1','PaymentGrant','intent:1','1','1.0','trace:1',$now,[]), InvalidArgumentException::class);
};
$tests['50 event payload hash canonical'] = static function () use ($now): void {
    $a = new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,['b'=>2,'a'=>1]);
    $b = new FinancialEventEnvelope('event:2','PaymentSettled','intent:1','1','1.0','trace:2',$now,['a'=>1,'b'=>2]); samePlan($a->payloadSha256(),$b->payloadSha256());
};
$tests['51 audit chain verifies'] = static function () use ($now): void {
    $chain = new AuditChain(); $chain->append(new AuditEnvelope('audit:1','operator:1','payment.review','intent','intent:1','finance.review',AuditOutcome::SUCCEEDED,$now,'trace:1',[]));
    $chain->append(new AuditEnvelope('audit:2','operator:2','period.close','period','2026-08','finance.close',AuditOutcome::SUCCEEDED,$now,'trace:2',[])); $chain->verify(); samePlan(2,$chain->count());
};
$tests['52 outbox retry and delivery chronology'] = static function () use ($now): void {
    $event = new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,[]); $message = new OutboxMessage($event,$now);
    $message->lease($now); $retry = $now->modify('+5 minutes'); $message->fail('temporary_error',$now,$retry); samePlan($retry->format(DATE_ATOM),$message->availableAt()->format(DATE_ATOM));
    $message->lease($retry); $message->delivered($retry); samePlan('delivered',$message->state());
};
$tests['53 null provider fails closed'] = static function (): void {
    $provider = new NullPaymentProvider(); samePlan('unconfigured_fail_closed',$provider->health());
    expectPlan(static fn () => $provider->refund('provider:1',new Money(100,'USD'),'idem-refund-0001'), DomainException::class);
};
$tests['54 provider registry fallback'] = static function (): void { samePlan('provider.unconfigured',(new ProviderRegistry())->selectForCurrency('USD')->providerId()); };
$tests['55 route ownership retained'] = static function (): void { $routes = RouteCatalogue::definitions(); samePlan('CF-03',$routes[0]['owner']); samePlan('File 20',$routes[0]['shell_owner']); };
$tests['56 REST policy free and fail closed'] = static function (): void { $policy = WordPressRestApi::policy(); samePlan(true,$policy['all_core_services_free']); samePlan(false,$policy['live_collection_enabled']); };
$tests['57 complete schema tables present'] = static function (): void {
    $tables = Schema::tables('wp_'); $required = ['products','prices','customer_refs','intents','provider_events','ledger_transactions','ledger_entries','recurring_consents','subscriptions','usage_authorizations','usage_facts','invoices','refunds','chargebacks','donations','settlements','settlement_lines','reconciliation_exceptions','finance_periods','adjustments','fraud_reviews','exports','idempotency','outbox','audit','retention_ledger','provider_registry','migrations'];
    foreach ($required as $table) { samePlan(true,isset($tables[$table])); } samePlan('2.0.0',Schema::VERSION);
};
$tests['58 migration checksum evidence required'] = static function (): void {
    $tables = Schema::tables('wp_'); $productChecksum = hash('sha256',$tables['products']); $executed=[]; $recorded=[];
    $runner = new MigrationRunner(
        static function(string $sql) use (&$executed):void{$executed[]=$sql;},
        static fn(string $id):bool=>str_ends_with($id,'-products'),
        static function(string $id,string $checksum) use (&$recorded):void{$recorded[$id]=$checksum;},
        static fn(string $id):?string=>str_ends_with($id,'-products')?$productChecksum:null
    );
    $applied=$runner->migrate('wp_'); samePlan(false,in_array('cf03-2.0.0-products',$applied,true)); samePlan(count($tables)-1,count($applied));
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function donationProductPlan(): FinancialProduct
{
    return new FinancialProduct('donation.general',ProductKind::DONATION,BillingType::VOLUNTARY,'cf03.finance',null,'refund.donation.v1','cancel.donation.v1',true,true,'approval.donation.1');
}
function educationProductPlan(): FinancialProduct
{
    return new FinancialProduct('education.membership.monthly',ProductKind::EDUCATION_MEMBERSHIP,BillingType::RECURRING,'file00.membership','education.structured','refund.education.v1','cancel.education.v1',true,true,'approval.education.1');
}
function pricePlan(string $id, DateTimeImmutable $from, ?DateTimeImmutable $until): PriceVersion
{
    return new PriceVersion('education.membership.monthly',$id,new Money(40000,'PKR'),'GLOBAL',TaxMode::NOT_APPLICABLE,$from,$until,'refund.education.v1','cancel.education.v1',true,'approval.price.1');
}
function intentPlan(DateTimeImmutable $now): PaymentIntent
{
    return new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-payment-intent-0001',str_repeat('a',64),$now,$now->modify('+1 hour'));
}
function readyIntentPlan(DateTimeImmutable $now): PaymentIntent
{
    $intent = intentPlan($now); $intent->attachProviderReference('provider-payment:1',1,$now); $intent->transition(PaymentIntentState::PROVIDER_PENDING,2,$now); return $intent;
}
function evidencePlan(DateTimeImmutable $now,string $type,Money $amount): ProviderEvidence
{
    return new ProviderEvidence('provider.sandbox','event:' . str_replace('.','-',$type),$type,'intent:1',$amount,'key:v1',$now,$now,str_repeat('b',64),true,true,$now);
}
function webhookBodyPlan(DateTimeImmutable $now): string
{
    return json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD','occurred_at'=>$now->format(DATE_ATOM)], JSON_THROW_ON_ERROR);
}
function ledgerPlan(string $id,int $amount): LedgerTransaction
{
    return new LedgerTransaction($id,[new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money($amount,'USD'),'intent:1'),new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money($amount,'USD'),'intent:1')]);
}
function settlementPlan(DateTimeImmutable $now): SettlementBatch
{
    return new SettlementBatch('batch:1','provider.sandbox',new Money(10000,'USD'),new Money(500,'USD'),new Money(1000,'USD'),new Money(8500,'USD'),$now,str_repeat('a',64),[
        ['reference'=>'payment:1','type'=>'payment','amount_minor'=>10000,'currency'=>'USD'],
        ['reference'=>'fee:1','type'=>'fee','amount_minor'=>500,'currency'=>'USD'],
        ['reference'=>'refund:1','type'=>'refund','amount_minor'=>1000,'currency'=>'USD'],
    ]);
}
function recurringConsentPlan(DateTimeImmutable $now): RecurringConsent
{
    return new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,true);
}
function fraudPlan(DateTimeImmutable $now): FraudReviewCase
{
    return new FraudReviewCase('risk:1','intent:1',[['code'=>'velocity','weight'=>25,'evidence_ref'=>'evidence:1']],$now,$now->modify('+1 day'));
}
function donationReadyPlan(DateTimeImmutable $now): DonationRecord
{
    $donation = new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now);
    $donation->bindPaymentIntent('intent:1','provider.sandbox',1); $donation->confirmDonor(2); return $donation;
}
function donationFactPlan(DateTimeImmutable $now, DonationFinancialFactType $type, string $eventType): TrustedDonationFact
{
    return new TrustedDonationFact($type,evidencePlan($now,$eventType,new Money(1000,'USD')),'provider.sandbox','intent:1',new Money(1000,'USD'));
}
function settledDonationPlan(DateTimeImmutable $now): DonationRecord
{
    $donation = donationReadyPlan($now); $donation->settle(donationFactPlan($now,DonationFinancialFactType::ONE_TIME_COMPLETED,'donation.settled'),'provider-payment:1',3); return $donation;
}
function samePlan(mixed $expected,mixed $actual):void
{
    if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}
}
/** @param class-string<Throwable> $class */
function expectPlan(callable $callback,string $class):void
{
    try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}
    throw new RuntimeException('Expected '.$class.' to be thrown.');
}
