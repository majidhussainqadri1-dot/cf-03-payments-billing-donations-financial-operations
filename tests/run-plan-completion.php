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

$tests = [];
$now = new DateTimeImmutable('2026-08-04T14:27:00+05:00');

$tests['financial actor capability passes with recent authentication'] = static function () use ($now): void {
    $actor = new FinancialActor('operator:1', [FinancialCapability::OPERATE_PAYMENTS], false, $now->modify('-5 minutes'));
    $actor->assertCan(FinancialCapability::OPERATE_PAYMENTS, $now, 600);
};
$tests['financial actor missing capability fails'] = static function (): void {
    $actor = new FinancialActor('operator:1', [FinancialCapability::VIEW_AUDIT]);
    assertThrowsPlan(static fn () => $actor->assertCan(FinancialCapability::OPERATE_PAYMENTS), DomainException::class);
};
$tests['suspended financial actor fails closed'] = static function (): void {
    $actor = new FinancialActor('operator:1', [FinancialCapability::VIEW_AUDIT], true);
    assertThrowsPlan(static fn () => $actor->assertCan(FinancialCapability::VIEW_AUDIT), DomainException::class);
};
$tests['toxic finance capability combination rejected'] = static function (): void {
    assertThrowsPlan(static fn () => (new SeparationOfDutiesPolicy())->assertNoToxicCombination([
        FinancialCapability::REVIEW_REFUNDS,
        FinancialCapability::EXECUTE_REFUNDS,
    ]), DomainException::class);
};
$tests['refund duties require three distinct actors'] = static function (): void {
    assertThrowsPlan(static fn () => (new SeparationOfDutiesPolicy())->assertRefundWorkflow('a', 'b', 'b'), DomainException::class);
};
$tests['donation product lifecycle uses separate actors'] = static function () use ($now): void {
    $lifecycle = new ProductLifecycle(donationProduct());
    $lifecycle->stage('stager:1', 1);
    $lifecycle->approve('approver:1', 2);
    $lifecycle->activate('activator:1', $now, 3);
    assertSamePlan('active', $lifecycle->state());
    assertSamePlan(4, $lifecycle->recordVersion());
};
$tests['product stager cannot self approve'] = static function (): void {
    $lifecycle = new ProductLifecycle(donationProduct());
    $lifecycle->stage('operator:1', 1);
    assertThrowsPlan(static fn () => $lifecycle->approve('operator:1', 2), DomainException::class);
};
$tests['paid product activation suspended by founder policy'] = static function () use ($now): void {
    $lifecycle = new ProductLifecycle(educationProduct());
    $lifecycle->stage('stager:1', 1);
    $lifecycle->approve('approver:1', 2);
    assertThrowsPlan(static fn () => $lifecycle->activate('activator:1', $now, 3), DomainException::class);
};
$tests['price lifecycle activates approved effective price'] = static function () use ($now): void {
    $price = price('price:1', $now->modify('-1 day'), null);
    $lifecycle = new PriceLifecycle($price);
    $lifecycle->recordStager('stager:1');
    $lifecycle->approve('approver:1', 1);
    $lifecycle->activate('activator:1', $now, 2);
    assertSamePlan('active', $lifecycle->state());
};
$tests['overlapping active prices rejected'] = static function () use ($now): void {
    $first = new PriceLifecycle(price('price:1', $now->modify('-2 days'), $now->modify('+2 days')));
    $first->recordStager('s:1');
    $first->approve('a:1', 1);
    $first->activate('x:1', $now, 2);
    $second = new PriceLifecycle(price('price:2', $now->modify('-1 day'), null));
    $second->recordStager('s:2');
    $second->approve('a:2', 1);
    assertThrowsPlan(static fn () => $second->activate('x:2', $now, 2, [$first]), DomainException::class);
};
$tests['payment intent provider reference immutable'] = static function () use ($now): void {
    $intent = intent($now);
    $intent->attachProviderReference('provider-payment:1', 1, $now);
    assertThrowsPlan(static fn () => $intent->attachProviderReference('provider-payment:2', 2, $now), DomainException::class);
};
$tests['payment intent rejects stale version'] = static function () use ($now): void {
    $intent = intent($now);
    assertThrowsPlan(static fn () => $intent->transition(PaymentIntentState::PROVIDER_PENDING, 2, $now), DomainException::class);
};
$tests['trusted payment state requires provider reference'] = static function () use ($now): void {
    $intent = intent($now);
    $intent->transition(PaymentIntentState::PROVIDER_PENDING, 1, $now);
    assertThrowsPlan(static fn () => $intent->transition(PaymentIntentState::SETTLED, 2, $now), DomainException::class);
};
$tests['payment intent records safe failure code'] = static function () use ($now): void {
    $intent = intent($now);
    $intent->transition(PaymentIntentState::PROVIDER_PENDING, 1, $now);
    $intent->transition(PaymentIntentState::FAILED, 2, $now, 'provider_declined');
    assertSamePlan('provider_declined', $intent->snapshot()['failure_code']);
};
$tests['raw HMAC webhook verifies and parses exact money'] = static function () use ($now): void {
    $secret = str_repeat('s', 32);
    $body = json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD'], JSON_THROW_ON_ERROR);
    $signatureAt = $now->modify('-10 seconds');
    $signature = hash_hmac('sha256', $signatureAt->getTimestamp() . '.' . $body, $secret);
    $verifier = new ProviderWebhookVerifier(static fn (string $provider, string $key): string => $secret, static fn (string $provider, string $event): bool => true);
    $evidence = $verifier->verify('provider.sandbox', 'key:v1', $body, $signature, $signatureAt, $now);
    assertSamePlan(1400, $evidence->amount()->minorUnits());
};
$tests['forged HMAC webhook rejected'] = static function () use ($now): void {
    $body = json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD'], JSON_THROW_ON_ERROR);
    $verifier = new ProviderWebhookVerifier(static fn (): string => str_repeat('s', 32), static fn (): bool => true);
    assertThrowsPlan(static fn () => $verifier->verify('provider.sandbox', 'key:v1', $body, str_repeat('0', 64), $now, $now), DomainException::class);
};
$tests['replayed provider event rejected'] = static function () use ($now): void {
    $secret = str_repeat('s', 32);
    $body = json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD'], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $now->getTimestamp() . '.' . $body, $secret);
    $verifier = new ProviderWebhookVerifier(static fn (): string => $secret, static fn (): bool => false);
    assertThrowsPlan(static fn () => $verifier->verify('provider.sandbox', 'key:v1', $body, $signature, $now, $now), DomainException::class);
};
$tests['payment confirmation requires evidence ledger and event'] = static function () use ($now): void {
    $intent = intent($now);
    $intent->attachProviderReference('provider-payment:1', 1, $now);
    $intent->transition(PaymentIntentState::PROVIDER_PENDING, 2, $now);
    $ledger = ledger('ledger:1', 1400);
    $written = [];
    $events = [];
    $service = new PaymentConfirmationService(
        static fn (callable $work): mixed => $work(),
        static function (LedgerTransaction $transaction) use (&$written): void { $written[] = $transaction->transactionId(); },
        static function ($event) use (&$events): void { $events[] = $event->type(); }
    );
    $event = $service->confirmSettled($intent, evidence($now, 'payment.settled', new Money(1400,'USD')), $ledger, 3, $now, 'event:settled:1');
    assertSamePlan('PaymentSettled', $event->type());
    assertSamePlan(['ledger:1'], $written);
    assertSamePlan(['PaymentSettled'], $events);
};
$tests['settlement batch enforces gross fee refund net'] = static function () use ($now): void {
    $batch = settlement($now);
    assertSamePlan(8500, $batch->net()->minorUnits());
};
$tests['settlement duplicate line rejected'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new SettlementBatch('batch:2','provider.sandbox',new Money(10000,'USD'),new Money(500,'USD'),new Money(1000,'USD'),new Money(8500,'USD'),$now,str_repeat('a',64),[
        ['reference'=>'p1','type'=>'payment','amount_minor'=>10000,'currency'=>'USD'],
        ['reference'=>'p1','type'=>'fee','amount_minor'=>500,'currency'=>'USD'],
        ['reference'=>'r1','type'=>'refund','amount_minor'=>1000,'currency'=>'USD'],
    ]), DomainException::class);
};
$tests['reconciliation exact match has no exceptions'] = static function () use ($now): void {
    $result = (new ReconciliationEngine())->reconcile(settlement($now), settlement($now)->lines());
    assertSamePlan(0, $result->count());
};
$tests['reconciliation amount mismatch material'] = static function () use ($now): void {
    $lines = settlement($now)->lines();
    $lines[0]['amount_minor'] = 9000;
    $result = (new ReconciliationEngine())->reconcile(settlement($now), $lines, ['USD'=>100]);
    assertSamePlan(false, $result->mayClose());
};
$tests['ledger journal source is idempotent'] = static function () use ($now): void {
    $journal = new LedgerJournal();
    $journal->post(ledger('ledger:1',1400),'payment','intent:1','operator:1','settlement','2026-08',$now,$now);
    assertThrowsPlan(static fn () => $journal->post(ledger('ledger:2',1400),'payment','intent:1','operator:2','retry','2026-08',$now,$now), DomainException::class);
};
$tests['ledger journal exposes balanced account totals'] = static function () use ($now): void {
    $journal = new LedgerJournal();
    $journal->post(ledger('ledger:1',1400),'payment','intent:1','operator:1','settlement','2026-08',$now,$now);
    assertSamePlan(['asset.cash'=>1400,'income.donation'=>-1400], $journal->accountBalances('USD'));
};
$tests['recurring consent requires explicit confirmation'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,false), DomainException::class);
};
$tests['recurring material term change requires new consent'] = static function () use ($now): void {
    $consent = recurringConsent($now);
    assertThrowsPlan(static fn () => $consent->assertRenewalParity(new Money(1400,'USD'),'month',str_repeat('a',64)), DomainException::class);
};
$tests['dunning retry respects evening quiet hours'] = static function (): void {
    $failed = new DateTimeImmutable('2026-08-04T20:30:00+05:00');
    $policy = new DunningPolicy([3600,86400,259200,604800]);
    $retry = $policy->nextRetryAt($failed,1,new DateTimeZone('Asia/Karachi'));
    assertSamePlan('2026-08-05T08:00:00+05:00',$retry->format(DATE_ATOM));
};
$tests['dunning does not blame user during provider outage'] = static function () use ($now): void {
    $policy = new DunningPolicy([3600,86400,259200,604800]);
    assertThrowsPlan(static fn () => $policy->nextRetryAt($now,1,new DateTimeZone('UTC'),true), DomainException::class);
};
$tests['AI usage authorization prices exact signed usage'] = static function () use ($now): void {
    $auth = new AiUsageAuthorization('auth:1','user:1','token',new Money(2,'USD'),100,new Money(200,'USD'),$now->modify('+1 day'),str_repeat('a',64));
    $occurred = $now;
    $secret = str_repeat('z',32);
    $payload = 'auth:1|usage:1|50|' . $occurred->format(DATE_ATOM);
    $auth->assertSignedUsageFact('usage:1',50,$occurred,hash_hmac('sha256',$payload,$secret),$secret);
    assertSamePlan(100,$auth->priceFor(50,$now)->minorUnits());
};
$tests['AI usage beyond cap rejected'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new AiUsageAuthorization('auth:1','user:1','token',new Money(3,'USD'),100,new Money(200,'USD'),$now->modify('+1 day'),str_repeat('a',64)), DomainException::class);
};
$tests['refund lifecycle supports uncertainty and reconciliation'] = static function (): void {
    $refund = new RefundRequest('refund:1','intent:1','user:1',new Money(1000,'USD'),'duplicate','requested',null,null,new Money(1400,'USD'));
    $refund->beginEligibilityReview('reviewer:1',1);
    $refund->approve('reviewer:1','eligible',2);
    $refund->markProviderPending('executor:1','provider-refund:1',3);
    $refund->markUncertain(4);
    $refund->succeed(5);
    $refund->reconcile(true,6);
    $refund->close(7);
    assertSamePlan('closed',$refund->status());
};
$tests['refund exceeds refundable balance rejected'] = static function (): void {
    assertThrowsPlan(static fn () => new RefundRequest('refund:1','intent:1','user:1',new Money(1500,'USD'),'duplicate','requested',null,null,new Money(1400,'USD')), DomainException::class);
};
$tests['chargeback closes only after ledger adjustment'] = static function () use ($now): void {
    $case = new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1400,'USD'),'fraud',$now->modify('+2 days'));
    $case->requireEvidence($now,1);
    $case->submitEvidence(str_repeat('a',64),$now,2);
    $case->recordProviderAcceptance(3);
    $case->recordOutcome(false,new Money(100,'USD'),4);
    $case->markLedgerAdjusted(5);
    $case->close(6);
    assertSamePlan('closed',$case->state());
};
$tests['late chargeback evidence rejected'] = static function () use ($now): void {
    $case = new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1400,'USD'),'fraud',$now->modify('+1 hour'));
    assertThrowsPlan(static fn () => $case->submitEvidence(str_repeat('a',64),$now->modify('+2 hours'),1), DomainException::class);
};
$tests['fraud review allows reasoned appeal'] = static function () use ($now): void {
    $case = fraud($now);
    $case->decide(false,'reviewer:1','risk confirmed',$now,1);
    $case->appeal('new evidence',2);
    $case->decide(true,'reviewer:2','appeal accepted',$now,3);
    assertSamePlan('approved',$case->state());
};
$tests['fraud review rejects prohibited signal'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new FraudReviewCase('risk:1','intent:1',[['code'=>'religion','weight'=>10,'evidence_ref'=>'evidence:1']],$now->modify('+1 day')), InvalidArgumentException::class);
};
$tests['financial adjustment requires three actors'] = static function () use ($now): void {
    $adjustment = new FinancialAdjustment('adjust:1','ledger:1',new Money(100,'USD'),'correction',str_repeat('a',64),'requester:1',$now);
    $adjustment->approve('approver:1',1);
    assertThrowsPlan(static fn () => $adjustment->execute('approver:1',2), DomainException::class);
    $adjustment->execute('executor:1',2);
    assertSamePlan('executed',$adjustment->state());
};
$tests['donation public projection contains no identity or privilege'] = static function () use ($now): void {
    $donation = new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now);
    $projection = $donation->publicProjection(true);
    assertSamePlan(null,$projection['donor_reference']);
    assertSamePlan([],$projection['privileges']);
    assertSamePlan(false,$projection['supporter_acknowledgment']);
};
$tests['donation settles only from trusted fact'] = static function () use ($now): void {
    $donation = new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now);
    $donation->confirmDonor(1);
    $fact = new TrustedDonationFact(DonationFinancialFactType::ONE_TIME_COMPLETED,evidence($now,'donation.settled',new Money(1000,'USD')),'provider.sandbox','intent:1',new Money(1000,'USD'));
    $donation->settle($fact,'provider-payment:1',2);
    $donation->issueReceipt('receipt:1',3);
    assertSamePlan('receipt_issued',$donation->state());
};
$tests['donation refund requires trusted refund fact'] = static function () use ($now): void {
    $donation = settledDonation($now);
    $wrong = new TrustedDonationFact(DonationFinancialFactType::ONE_TIME_COMPLETED,evidence($now,'donation.settled',new Money(1000,'USD')),'provider.sandbox','intent:1',new Money(1000,'USD'));
    assertThrowsPlan(static fn () => $donation->refund($wrong,3), DomainException::class);
};
$tests['secure export field allowlist blocks secrets'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new SecureExportJob('export:1','operator:1',['cvv'],[],100,$now->modify('+1 hour')), DomainException::class);
};
$tests['secure export lifecycle requires encrypted evidence'] = static function () use ($now): void {
    $job = new SecureExportJob('export:1','operator:1',['transaction_id','amount_minor'],['currency'=>'USD'],100,$now->modify('+1 hour'));
    $job->start(1);
    $job->complete(str_repeat('a',64),'secure-object:1',2);
    $job->assertDownloadable($now);
    assertSamePlan('ready',$job->state());
};
$tests['retention legal hold blocks deletion'] = static function () use ($now): void {
    $retention = new RetentionSchedule();
    assertThrowsPlan(static fn () => $retention->assertDeletionAllowed('guest_prompt_state',$now->modify('-100 days'),$now,true), DomainException::class);
};
$tests['expired guest prompt state may delete'] = static function () use ($now): void {
    (new RetentionSchedule())->assertDeletionAllowed('guest_prompt_state',$now->modify('-100 days'),$now,false);
};
$tests['restore matching manifests succeeds'] = static function (): void {
    $manifest = ['ledger'=>['count'=>2,'hash'=>str_repeat('a',64)]];
    $result = (new RestoreReconciliation())->verify($manifest,$manifest,['event:1'],['event:1']);
    assertSamePlan(true,$result['balanced']);
};
$tests['restore duplicate event posting rejected'] = static function (): void {
    $manifest = ['ledger'=>['count'=>2,'hash'=>str_repeat('a',64)]];
    assertThrowsPlan(static fn () => (new RestoreReconciliation())->verify($manifest,$manifest,['event:1'],['event:1','event:1']), DomainException::class);
};
$tests['finance period requires separated close actors'] = static function (): void {
    $period = new FinancePeriod('2026-08');
    $period->beginReconciliation(1);
    assertThrowsPlan(static fn () => $period->approveClose(new ReconciliationResult([]),'operator:1','operator:1',2), DomainException::class);
};
$tests['finance period closes and locks after reconciliation'] = static function () use ($now): void {
    $period = new FinancePeriod('2026-08');
    $period->beginReconciliation(1);
    $period->approveClose(new ReconciliationResult([]),'reviewer:1','approver:1',2);
    $period->lock($now,3);
    assertSamePlan(true,$period->closed());
};
$tests['financial event envelope rejects command names'] = static function () use ($now): void {
    assertThrowsPlan(static fn () => new FinancialEventEnvelope('event:1','PaymentGrant','intent:1','1','1.0','trace:1',$now,[]), InvalidArgumentException::class);
};
$tests['financial event payload hash stable across key order'] = static function () use ($now): void {
    $a = new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,['b'=>2,'a'=>1]);
    $b = new FinancialEventEnvelope('event:2','PaymentSettled','intent:1','1','1.0','trace:2',$now,['a'=>1,'b'=>2]);
    assertSamePlan($a->payloadSha256(),$b->payloadSha256());
};
$tests['audit chain verifies append-only hashes'] = static function () use ($now): void {
    $chain = new AuditChain();
    $chain->append(new AuditEnvelope('audit:1','operator:1','payment.review','intent','intent:1','finance.review',AuditOutcome::SUCCEEDED,$now,'trace:1',[]));
    $chain->append(new AuditEnvelope('audit:2','operator:2','period.close','period','2026-08','finance.close',AuditOutcome::SUCCEEDED,$now,'trace:2',[]));
    $chain->verify();
    assertSamePlan(2,$chain->count());
};
$tests['outbox leases retries and delivers'] = static function () use ($now): void {
    $event = new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,[]);
    $message = new OutboxMessage($event,$now);
    $message->lease($now);
    $message->fail('temporary_error',$now->modify('+5 minutes'));
    assertSamePlan($now->modify('+5 minutes')->format(DATE_ATOM),$message->availableAt()->format(DATE_ATOM));
    $message->lease($now->modify('+5 minutes'));
    $message->delivered();
    assertSamePlan('delivered',$message->state());
};
$tests['null provider fails closed'] = static function () use ($now): void {
    $provider = new NullPaymentProvider();
    assertSamePlan('unconfigured_fail_closed',$provider->health());
    assertThrowsPlan(static fn () => $provider->refund('provider:1',new Money(100,'USD'),'idem-refund-0001'), DomainException::class);
};
$tests['provider registry falls back to null provider'] = static function (): void {
    $provider = (new ProviderRegistry())->selectForCurrency('USD');
    assertSamePlan('provider.unconfigured',$provider->providerId());
};
$tests['route catalogue keeps CF03 ownership and File20 shell'] = static function (): void {
    $routes = RouteCatalogue::definitions();
    assertSamePlan('CF-03',$routes[0]['owner']);
    assertSamePlan('File 20',$routes[0]['shell_owner']);
};
$tests['REST policy reports free fail-closed mode'] = static function (): void {
    $policy = WordPressRestApi::policy();
    assertSamePlan(true,$policy['all_core_services_free']);
    assertSamePlan(false,$policy['live_collection_enabled']);
};
$tests['complete schema declares all canonical owner tables'] = static function (): void {
    $tables = Schema::tables('wp_');
    $required = ['products','prices','customer_refs','intents','provider_events','ledger_transactions','ledger_entries','recurring_consents','subscriptions','usage_authorizations','usage_facts','invoices','refunds','chargebacks','donations','settlements','settlement_lines','reconciliation_exceptions','finance_periods','adjustments','fraud_reviews','exports','idempotency','outbox','audit','retention_ledger','provider_registry','migrations'];
    foreach ($required as $table) { assertSamePlan(true,isset($tables[$table])); }
    assertSamePlan('2.0.0',Schema::VERSION);
};
$tests['migration runner uses schema version and checksums'] = static function (): void {
    $executed=[]; $recorded=[];
    $runner = new MigrationRunner(
        static function(string $sql) use (&$executed):void{$executed[]=$sql;},
        static fn(string $id):bool=>str_ends_with($id,'-products'),
        static function(string $id,string $checksum) use (&$recorded):void{$recorded[$id]=$checksum;}
    );
    $applied=$runner->migrate('wp_');
    assertSamePlan(false,in_array('cf03-2.0.0-products',$applied,true));
    assertSamePlan(count(Schema::tables('wp_'))-1,count($applied));
    foreach($recorded as $checksum){assertSamePlan(64,strlen($checksum));}
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function donationProduct(): FinancialProduct
{
    return new FinancialProduct('donation.general',ProductKind::DONATION,BillingType::VOLUNTARY,'cf03.finance',null,'refund.donation.v1','cancel.donation.v1',true,true,'approval.donation.1');
}
function educationProduct(): FinancialProduct
{
    return new FinancialProduct('education.membership.monthly',ProductKind::EDUCATION_MEMBERSHIP,BillingType::RECURRING,'file00.membership','education.structured','refund.education.v1','cancel.education.v1',true,true,'approval.education.1');
}
function price(string $id, DateTimeImmutable $from, ?DateTimeImmutable $until): PriceVersion
{
    return new PriceVersion('education.membership.monthly',$id,new Money(40000,'PKR'),'GLOBAL',TaxMode::NOT_APPLICABLE,$from,$until,'refund.education.v1','cancel.education.v1',true,'approval.price.1');
}
function intent(DateTimeImmutable $now): PaymentIntent
{
    return new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-payment-intent-0001',str_repeat('a',64),$now,$now->modify('+1 hour'));
}
function evidence(DateTimeImmutable $now,string $type,Money $amount): ProviderEvidence
{
    return new ProviderEvidence('provider.sandbox','event:' . str_replace('.','-',$type),$type,'intent:1',$amount,'key:v1',$now->modify('-10 seconds'),$now,str_repeat('b',64),true,true);
}
function ledger(string $id,int $amount): LedgerTransaction
{
    return new LedgerTransaction($id,[
        new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money($amount,'USD'),'intent:1'),
        new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money($amount,'USD'),'intent:1'),
    ]);
}
function settlement(DateTimeImmutable $now): SettlementBatch
{
    return new SettlementBatch('batch:1','provider.sandbox',new Money(10000,'USD'),new Money(500,'USD'),new Money(1000,'USD'),new Money(8500,'USD'),$now,str_repeat('a',64),[
        ['reference'=>'p1','type'=>'payment','amount_minor'=>10000,'currency'=>'USD'],
        ['reference'=>'f1','type'=>'fee','amount_minor'=>500,'currency'=>'USD'],
        ['reference'=>'r1','type'=>'refund','amount_minor'=>1000,'currency'=>'USD'],
    ]);
}
function recurringConsent(DateTimeImmutable $now): RecurringConsent
{
    return new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,true);
}
function fraud(DateTimeImmutable $now): FraudReviewCase
{
    return new FraudReviewCase('risk:1','intent:1',[['code'=>'velocity','weight'=>25,'evidence_ref'=>'evidence:1']],$now->modify('+1 day'));
}
function settledDonation(DateTimeImmutable $now): DonationRecord
{
    $donation = new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now);
    $donation->confirmDonor(1);
    $fact = new TrustedDonationFact(DonationFinancialFactType::ONE_TIME_COMPLETED,evidence($now,'donation.settled',new Money(1000,'USD')),'provider.sandbox','intent:1',new Money(1000,'USD'));
    $donation->settle($fact,'provider-payment:1',2);
    return $donation;
}
function assertSamePlan(mixed $expected,mixed $actual):void
{
    if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}
}
/** @param class-string<Throwable> $class */
function assertThrowsPlan(callable $callback,string $class):void
{
    try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}
    throw new RuntimeException('Expected '.$class.' to be thrown.');
}
