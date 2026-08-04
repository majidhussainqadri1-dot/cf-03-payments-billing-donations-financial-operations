<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\ActivationEvidenceRecord;
use Sabri\CF03\Application\BackupManifest;
use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Application\OutboxMessage;
use Sabri\CF03\Application\PaymentConfirmationService;
use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationFinancialFactType;
use Sabri\CF03\Domain\DonationRecord;
use Sabri\CF03\Domain\DunningPolicy;
use Sabri\CF03\Domain\FinanceExport;
use Sabri\CF03\Domain\FinancePeriod;
use Sabri\CF03\Domain\FinancialEventEnvelope;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\Invoice;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntent;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PaymentIntentTransition;
use Sabri\CF03\Domain\PriceVersion;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\RecurringConsent;
use Sabri\CF03\Domain\RefundBalance;
use Sabri\CF03\Domain\RefundRequest;
use Sabri\CF03\Domain\SecureExportJob;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Domain\TaxMode;
use Sabri\CF03\Domain\TrustedDonationFact;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Persistence\MigrationRunner;
use Sabri\CF03\Persistence\Schema;

$now = new DateTimeImmutable('2026-08-04T17:01:00+05:00');
$tests = [];
$add = static function (string $name, callable $test) use (&$tests): void { $tests[$name] = $test; };

$add('review 01 release identity', static fn () => same40('1.0.0-rc.3', defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.0.0-rc.3'));
$add('review 02 activation unknown field', static function () use ($now): void { $r=activation40($now); $r['unexpected']=true; truth40(in_array('activation_record_unknown_fields',ActivationEvidenceRecord::missingGates($r,'1.0.0-rc.3',$now),true)); });
$add('review 03 volatile evidence expiry', static function () use ($now): void { $r=activation40($now); unset($r['approvals']['pci_scope_validation']['expires_at']); truth40(in_array('pci_scope_validated',ActivationEvidenceRecord::missingGates($r,'1.0.0-rc.3',$now),true)); });
$add('review 04 approval separation', static function () use ($now): void { $r=activation40($now); $r['approvals']['staging_acceptance']['approver_ref']=$r['approvals']['founder_change_control']['approver_ref']; truth40(in_array('activation_approval_separation',ActivationEvidenceRecord::missingGates($r,'1.0.0-rc.3',$now),true)); });
$add('review 05 forged webhook cannot reserve', static function () use ($now): void { $n=0; $body=webhook40($now); $v=new ProviderWebhookVerifier(static fn():string=>str_repeat('s',32),static function()use(&$n):bool{$n++;return true;}); throws40(static fn()=>$v->verify('provider.sandbox','key:v1',$body,str_repeat('0',64),$now,$now),DomainException::class); same40(0,$n); });
$add('review 06 event occurrence retained', static function () use ($now): void { same40($now->format(DATE_ATOM),evidence40($now,'payment.settled',new Money(1400,'USD'))->occurredAt()->format(DATE_ATOM)); });
$add('review 07 webhook unknown field', static function () use ($now): void { $secret=str_repeat('s',32); $p=json_decode(webhook40($now),true,32,JSON_THROW_ON_ERROR); $p['extra']='x'; $body=json_encode($p,JSON_THROW_ON_ERROR); $sig=hash_hmac('sha256',$now->getTimestamp().'.'.$body,$secret); throws40(static fn()=>(new ProviderWebhookVerifier(static fn():string=>$secret,static fn():bool=>true))->verify('provider.sandbox','key:v1',$body,$sig,$now,$now),InvalidArgumentException::class); });
$add('review 08 expired intent mutation', static function () use ($now): void { $i=new PaymentIntent('intent:expired','user:1','donation.general',null,new Money(1000,'USD'),'provider.sandbox','idem-expired-intent-0001',str_repeat('a',64),$now->modify('-2 hours'),$now->modify('-1 hour')); throws40(static fn()=>$i->attachProviderReference('provider-payment:1',1,$now),DomainException::class); });
$add('review 09 settlement window', static function () use ($now): void { $i=new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-r40-window-0001',str_repeat('a',64),$now,$now->modify('+2 minutes')); $i->attachProviderReference('provider-payment:1',1,$now); $i->transition(PaymentIntentState::PROVIDER_PENDING,2,$now); $at=$now->modify('+4 minutes'); $e=new ProviderEvidence('provider.sandbox','event:late:1','payment.settled','intent:1',new Money(1400,'USD'),'key:v1',$at,$at,str_repeat('b',64),true,true,$at); throws40(static fn()=>settleService40()->confirmSettled($i,$e,ledger40(1400),3,$at,'event:settled:late'),DomainException::class); });
$add('review 10 no generic refunded transition', static fn()=>throws40(static fn()=>(new PaymentIntentTransition())->assertAllowed(PaymentIntentState::SETTLED,PaymentIntentState::REFUNDED),DomainException::class));
$add('review 11 refund executor validation', static function (): void { $r=new RefundRequest('refund:1','intent:1','user:1',new Money(100,'USD'),'duplicate'); $r->approve('reviewer:1'); throws40(static fn()=>$r->markExecuting(''),InvalidArgumentException::class); });
$add('review 12 reviewer replacement denied', static function (): void { $r=new RefundRequest('refund:1','intent:1','user:1',new Money(100,'USD'),'duplicate'); $r->beginEligibilityReview('reviewer:1',1); throws40(static fn()=>$r->deny('reviewer:2','denied',2),DomainException::class); });
$add('review 13 cumulative refund balance', static function (): void { $b=RefundBalance::fresh('intent:1',new Money(1000,'USD')); $b->reserve('refund:1',new Money(700,'USD'),1); throws40(static fn()=>$b->reserve('refund:2',new Money(400,'USD'),2),DomainException::class); });
$add('review 14 donation fact binding', static function () use ($now): void { $f=donationFact40($now,DonationFinancialFactType::ONE_TIME_COMPLETED,'donation.settled',new Money(1000,'USD')); throws40(static fn()=>$f->assertMatches('provider.sandbox','intent:1',new Money(1400,'USD')),DomainException::class); });
$add('review 15 donation requires intent binding', static function () use ($now): void { $d=new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now); throws40(static fn()=>$d->confirmDonor(1),DomainException::class); });
$add('review 16 recurring consent revocation', static function () use ($now): void { $c=consent40($now); $c->revoke($now->modify('+1 day'),1); throws40(static fn()=>$c->assertActiveAt($now->modify('+2 days')),DomainException::class); });
$add('review 17 cancelled subscription no resume', static function () use ($now): void { $s=new Subscription('sub:1','user:1','product:1','price:1','pending'); $s->transition('active',1); $s->transition('cancelled',2); throws40(static fn()=>$s->resume($now->modify('+1 month'),3),DomainException::class); });
$add('review 18 deterministic pause time', static function () use ($now): void { $s=new Subscription('sub:1','user:1','product:1','price:1','pending'); $s->transition('active',1); throws40(static fn()=>$s->pause($now,$now,2),InvalidArgumentException::class); });
$add('review 19 activation period bounds', static function () use ($now): void { $s=new Subscription('sub:1','user:1','product:1','price:1','pending'); throws40(static fn()=>$s->activate('provider-sub:1',$now,$now->modify('-1 minute'),1),InvalidArgumentException::class); });
$add('review 20 dunning after revocation', static fn()=>throws40(static fn()=>(new DunningPolicy([3600,86400,259200,604800]))->nextRetryAt(new DateTimeImmutable('2026-08-04T17:01:00+05:00'),1,new DateTimeZone('UTC'),false,false,false),DomainException::class));
$add('review 21 chargeback deadline', static fn()=>throws40(static fn()=>new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1000,'USD'),'fraud',new DateTimeImmutable('2026-08-04T17:01:00+05:00'),new DateTimeImmutable('2026-08-04T17:01:00+05:00')),InvalidArgumentException::class));
$add('review 22 chargeback provider acceptance', static function () use ($now): void { $c=new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1000,'USD'),'fraud',$now,$now->modify('+1 day')); $c->submitEvidence(str_repeat('a',64),$now,1); throws40(static fn()=>$c->recordOutcome(false,new Money(100,'USD'),2),DomainException::class); });
$add('review 23 duplicate fraud signals', static function () use ($now): void { throws40(static fn()=>new FraudReviewCase('risk:1','intent:1',[['code'=>'velocity','weight'=>10,'evidence_ref'=>'evidence:1'],['code'=>'velocity','weight'=>20,'evidence_ref'=>'evidence:2']],$now,$now->modify('+1 day')),DomainException::class); });
$add('review 24 fresh appeal reviewer', static function () use ($now): void { $c=new FraudReviewCase('risk:1','intent:1',[['code'=>'velocity','weight'=>10,'evidence_ref'=>'evidence:1']],$now,$now->modify('+1 day')); $c->decide(false,'reviewer:1','declined',$now,1); $c->appeal('appeal',2); throws40(static fn()=>$c->decide(true,'reviewer:1','same reviewer',$now,3),DomainException::class); });
$add('review 25 reconciliation shape', static fn()=>throws40(static fn()=>new ReconciliationResult([['type'=>'x','reference'=>'r','expected'=>-1,'actual'=>0,'currency'=>'usd','material'=>true]]),InvalidArgumentException::class));
$add('review 26 settlement line shape', static function () use ($now): void { throws40(static fn()=>new SettlementBatch('batch:1','provider.sandbox',new Money(1,'USD'),new Money(0,'USD'),new Money(0,'USD'),new Money(1,'USD'),$now,str_repeat('a',64),[['reference'=>'x','type'=>'payment','amount_minor'=>1,'currency'=>'USD']]),InvalidArgumentException::class); });
$add('review 27 no legacy period close', static fn()=>same40(false,method_exists(FinancePeriod::class,'close')));
$add('review 28 no material close bypass', static function (): void { $p=new FinancePeriod('2026-08'); $p->beginReconciliation(1); $r=new ReconciliationResult([['type'=>'missing_line','reference'=>'payment:1','expected'=>100,'actual'=>0,'currency'=>'USD','material'=>true]]); throws40(static fn()=>$p->approveClose($r,'reviewer:1','approver:1',2),DomainException::class); });
$add('review 29 export date inversion', static function () use ($now): void { throws40(static fn()=>new SecureExportJob('export:1','operator:1',['transaction_id'],['date_from'=>'2026-08-05','date_to'=>'2026-08-04'],100,$now->modify('+1 hour')),DomainException::class); });
$add('review 30 export traversal', static function () use ($now): void { $j=new SecureExportJob('export:1','operator:1',['transaction_id'],[],100,$now->modify('+1 hour')); $j->start(1); throws40(static fn()=>$j->complete(str_repeat('a',64),'vault://finance/../secret',2),InvalidArgumentException::class); });
$add('review 31 legacy export safety', static function () use ($now): void { same40("'=1+1",FinanceExport::neutralizeSpreadsheetFormula('=1+1')); throws40(static fn()=>new FinanceExport('export:1',[['provider_secret'=>'x']],str_repeat('a',64),$now->getTimestamp()+60),DomainException::class); });
$add('review 32 audit sensitive metadata', static function () use ($now): void { throws40(static fn()=>new AuditEnvelope('audit:1','operator:1','payment.review','intent','intent:1','finance.review',AuditOutcome::DENIED,$now,'trace:1',['access_token'=>'secret']),DomainException::class); });
$add('review 33 event payload bounds', static function () use ($now): void { $p=[]; for($i=0;$i<65;$i++){$p['f'.$i]=$i;} throws40(static fn()=>new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,$p),InvalidArgumentException::class); });
$add('review 34 outbox chronology', static function () use ($now): void { $m=new OutboxMessage(new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,[]),$now); $m->lease($now); throws40(static fn()=>$m->fail('temporary_error',$now,$now),InvalidArgumentException::class); });
$add('review 35 backup manifest hash', static fn()=>throws40(static fn()=>new BackupManifest(['ledger'=>['count'=>1,'hash'=>'bad']]),InvalidArgumentException::class));
$add('review 36 invoice status', static fn()=>throws40(static fn()=>new Invoice('invoice:1','INV-1','user:1','Sabri',[['description'=>'Item','quantity'=>1,'unit_minor'=>100]],new Money(100,'USD'),'unknown',str_repeat('a',64)),InvalidArgumentException::class));
$add('review 37 checkout port', static function () use ($now): void { throws40(static fn()=>new HostedCheckoutReference('provider.sandbox','session:1','https://payments.example.test:8443/session/1',$now,$now->modify('+30 minutes'),['payments.example.test']),InvalidArgumentException::class); });
$add('review 38 encoded redirect', static function () use ($now): void { [$p,$v]=donationCheckout40($now); throws40(static fn()=>new CheckoutCommand('intent:1','user:1',$p,$v,$v->amount(),'idem-checkout-r40-0001',$now,$now->modify('+30 minutes'),'/billing%2Freturn'),InvalidArgumentException::class); });
$add('review 39 REST scientific amount', static function (): void { $r=WordPressRestApi::donationPreparing(new RestRequest40(['amount_minor'=>'1e3','currency'=>'USD','monthly'=>'0'])); same40(422,$r['status']); });
$add('review 40 migration drift', static function (): void { $m=new MigrationRunner(static function(string $sql):void{},static fn(string $id):bool=>str_ends_with($id,'-products'),static function(string $id,string $hash):void{},static fn(string $id):?string=>str_repeat('0',64)); throws40(static fn()=>$m->migrate('wp_'),RuntimeException::class); });

$failures=0;
foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));
exit($failures===0?0:1);

final class RestRequest40
{
    /** @param array<string,mixed> $params */
    public function __construct(private array $params) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}

/** @return array<string,mixed> */
function activation40(DateTimeImmutable $now): array
{
    $a=static fn(string $id):array=>['approved'=>true,'evidence_id'=>'evidence:'.$id,'approver_ref'=>'approver:'.$id,'approved_at'=>$now->modify('-1 hour')->format(DATE_ATOM),'expires_at'=>$now->modify('+30 days')->format(DATE_ATOM)];
    return ['schema_version'=>'1.0','module_version'=>'1.0.0-rc.3','record_id'=>'activation:cf03:1','configuration_hash'=>str_repeat('a',64),'approvals'=>['founder_change_control'=>$a('founder'),'legal_tax_accounting_review'=>$a('legal'),'pci_scope_validation'=>$a('pci'),'independent_security_acceptance'=>$a('security'),'staging_acceptance'=>$a('staging'),'rollback_rehearsal'=>$a('rollback')],'provider'=>['mode'=>'hosted','provider_ref'=>'provider:sandbox','evidence_id'=>'evidence:provider','validated_at'=>$now->modify('-1 hour')->format(DATE_ATOM),'expires_at'=>$now->modify('+30 days')->format(DATE_ATOM)]];
}
function webhook40(DateTimeImmutable $now): string { return json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD','occurred_at'=>$now->format(DATE_ATOM)],JSON_THROW_ON_ERROR); }
function evidence40(DateTimeImmutable $now,string $type,Money $amount): ProviderEvidence { return new ProviderEvidence('provider.sandbox','event:'.str_replace('.','-',$type),$type,'intent:1',$amount,'key:v1',$now,$now,str_repeat('b',64),true,true,$now); }
function readyIntent40(DateTimeImmutable $now): PaymentIntent { $i=new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-r40-payment-0001',str_repeat('a',64),$now,$now->modify('+1 hour')); $i->attachProviderReference('provider-payment:1',1,$now); $i->transition(PaymentIntentState::PROVIDER_PENDING,2,$now); return $i; }
function ledger40(int $amount): LedgerTransaction { return new LedgerTransaction('ledger:1',[new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money($amount,'USD'),'intent:1'),new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money($amount,'USD'),'intent:1')]); }
function settleService40(): PaymentConfirmationService { return new PaymentConfirmationService(static fn(callable $w):mixed=>$w(),static function(LedgerTransaction $t):void{},static function($e):void{}); }
function donationFact40(DateTimeImmutable $now,DonationFinancialFactType $type,string $eventType,Money $amount): TrustedDonationFact { return new TrustedDonationFact($type,evidence40($now,$eventType,$amount),'provider.sandbox','intent:1',$amount); }
function consent40(DateTimeImmutable $now): RecurringConsent { return new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,true); }
/** @return array{0:FinancialProduct,1:PriceVersion} */
function donationCheckout40(DateTimeImmutable $now): array { $p=new FinancialProduct('donation.general',ProductKind::DONATION,BillingType::VOLUNTARY,'cf03.finance',null,'refund.donation.v1','cancel.donation.v1',true,true,'approval.donation.1'); $v=new PriceVersion('donation.general','price.usd.2026',new Money(1000,'USD'),'GLOBAL',TaxMode::NOT_APPLICABLE,$now->modify('-1 day'),null,'refund.donation.v1','cancel.donation.v1',true,'approval.price.1'); return [$p,$v]; }
function same40(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
function truth40(bool $condition):void{if(!$condition){throw new RuntimeException('Expected true.');}}
/** @param class-string<Throwable> $class */
function throws40(callable $callback,string $class):void{try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}throw new RuntimeException('Expected '.$class.' to be thrown.');}
