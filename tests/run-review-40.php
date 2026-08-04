<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\ActivationEvidenceRecord;
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

$tests['review 01 architecture release identity is rc3'] = static function (): void {
    sameR40('1.0.0-rc.3', defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.0.0-rc.3');
};
$tests['review 02 activation record rejects unknown fields'] = static function () use ($now): void {
    $record = activationR40($now); $record['unexpected'] = true;
    truthR40(in_array('activation_record_unknown_fields', ActivationEvidenceRecord::missingGates($record, '1.0.0-rc.3', $now), true));
};
$tests['review 03 volatile activation evidence requires expiry'] = static function () use ($now): void {
    $record = activationR40($now); unset($record['approvals']['pci_scope_validation']['expires_at']);
    truthR40(in_array('pci_scope_validated', ActivationEvidenceRecord::missingGates($record, '1.0.0-rc.3', $now), true));
};
$tests['review 04 activation approvals require separated actors'] = static function () use ($now): void {
    $record = activationR40($now); $record['approvals']['staging_acceptance']['approver_ref'] = $record['approvals']['founder_change_control']['approver_ref'];
    truthR40(in_array('activation_approval_separation', ActivationEvidenceRecord::missingGates($record, '1.0.0-rc.3', $now), true));
};
$tests['review 05 forged webhook cannot reserve event id'] = static function () use ($now): void {
    $reservations = 0; $body = webhookR40($now); $verifier = new ProviderWebhookVerifier(static fn (): string => str_repeat('s', 32), static function () use (&$reservations): bool { $reservations++; return true; });
    throwsR40(static fn () => $verifier->verify('provider.sandbox','key:v1',$body,str_repeat('0',64),$now,$now), DomainException::class);
    sameR40(0, $reservations);
};
$tests['review 06 provider evidence retains occurrence time'] = static function () use ($now): void {
    $evidence = evidenceR40($now, 'payment.settled', new Money(1400,'USD')); sameR40($now->format(DATE_ATOM), $evidence->occurredAt()->format(DATE_ATOM));
};
$tests['review 07 webhook rejects unknown normalized field'] = static function () use ($now): void {
    $secret = str_repeat('s',32); $payload = json_decode(webhookR40($now), true, 32, JSON_THROW_ON_ERROR); $payload['extra'] = 'x'; $body = json_encode($payload, JSON_THROW_ON_ERROR); $sig = hash_hmac('sha256',$now->getTimestamp().'.'.$body,$secret);
    throwsR40(static fn () => (new ProviderWebhookVerifier(static fn ():string=>$secret,static fn ():bool=>true))->verify('provider.sandbox','key:v1',$body,$sig,$now,$now), InvalidArgumentException::class);
};
$tests['review 08 expired intent cannot attach provider reference'] = static function () use ($now): void {
    $intent = new PaymentIntent('intent:expired','user:1','donation.general',null,new Money(1000,'USD'),'provider.sandbox','idem-expired-intent-0001',str_repeat('a',64),$now->modify('-2 hours'),$now->modify('-1 hour'));
    throwsR40(static fn () => $intent->attachProviderReference('provider-payment:1',1,$now), DomainException::class);
};
$tests['review 09 settlement must occur inside intent window'] = static function () use ($now): void {
    $intent = readyIntentR40($now); $evidence = new ProviderEvidence('provider.sandbox','event:late:1','payment.settled','intent:1',new Money(1400,'USD'),'key:v1',$now,$now,str_repeat('b',64),true,true,$now->modify('+2 hours'));
    throwsR40(static fn () => settlementServiceR40()->confirmSettled($intent,$evidence,ledgerR40(1400),3,$now->modify('+2 hours'),'event:settled:late'), DomainException::class);
};
$tests['review 10 generic transition cannot mark refunded'] = static function (): void {
    throwsR40(static fn () => (new PaymentIntentTransition())->assertAllowed(PaymentIntentState::SETTLED, PaymentIntentState::REFUNDED), DomainException::class);
};
$tests['review 11 refund executor identity is validated'] = static function (): void {
    $refund = new RefundRequest('refund:1','intent:1','user:1',new Money(100,'USD'),'duplicate'); $refund->approve('reviewer:1');
    throwsR40(static fn () => $refund->markExecuting(''), InvalidArgumentException::class);
};
$tests['review 12 refund reviewer cannot be replaced on denial'] = static function (): void {
    $refund = new RefundRequest('refund:1','intent:1','user:1',new Money(100,'USD'),'duplicate'); $refund->beginEligibilityReview('reviewer:1',1);
    throwsR40(static fn () => $refund->deny('reviewer:2','denied',2), DomainException::class);
};
$tests['review 13 cumulative partial refunds cannot exceed payment'] = static function (): void {
    $balance = RefundBalance::fresh('intent:1',new Money(1000,'USD')); $balance->reserve('refund:1',new Money(700,'USD'),1);
    throwsR40(static fn () => $balance->reserve('refund:2',new Money(400,'USD'),2), DomainException::class);
};
$tests['review 14 trusted donation fact remains amount bound'] = static function () use ($now): void {
    $fact = donationFactR40($now, DonationFinancialFactType::ONE_TIME_COMPLETED, 'donation.settled', new Money(1000,'USD'));
    throwsR40(static fn () => $fact->assertMatches('provider.sandbox','intent:1',new Money(1400,'USD')), DomainException::class);
};
$tests['review 15 donation confirmation requires immutable intent binding'] = static function () use ($now): void {
    $donation = new DonationRecord('donation:1','user:1',new Money(1000,'USD'),'general',false,false,true,$now);
    throwsR40(static fn () => $donation->confirmDonor(1), DomainException::class);
};
$tests['review 16 revoked recurring consent is inactive'] = static function () use ($now): void {
    $consent = consentR40($now); $consent->revoke($now->modify('+1 day'),1);
    throwsR40(static fn () => $consent->assertActiveAt($now->modify('+2 days')), DomainException::class);
};
$tests['review 17 cancelled subscription cannot resume'] = static function () use ($now): void {
    $subscription = new Subscription('sub:1','user:1','product:1','price:1','pending'); $subscription->transition('active',1); $subscription->transition('cancelled',2);
    throwsR40(static fn () => $subscription->resume($now->modify('+1 month'),3), DomainException::class);
};
$tests['review 18 subscription pause uses explicit decision time'] = static function () use ($now): void {
    $subscription = new Subscription('sub:1','user:1','product:1','price:1','pending'); $subscription->transition('active',1);
    throwsR40(static fn () => $subscription->pause($now,$now,2), InvalidArgumentException::class);
};
$tests['review 19 subscription activation period is bounded'] = static function () use ($now): void {
    $subscription = new Subscription('sub:1','user:1','product:1','price:1','pending');
    throwsR40(static fn () => $subscription->activate('provider-sub:1',$now,$now->modify('-1 minute'),1), InvalidArgumentException::class);
};
$tests['review 20 dunning stops after consent revocation'] = static function () use ($now): void {
    throwsR40(static fn () => (new DunningPolicy([3600,86400,259200,604800]))->nextRetryAt($now,1,new DateTimeZone('UTC'),false,false,false), DomainException::class);
};
$tests['review 21 chargeback deadline follows opening'] = static function () use ($now): void {
    throwsR40(static fn () => new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1000,'USD'),'fraud',$now,$now), InvalidArgumentException::class);
};
$tests['review 22 chargeback outcome requires provider acceptance'] = static function () use ($now): void {
    $case = new ChargebackCase('case:1','provider.sandbox','provider-case:1','intent:1',new Money(1000,'USD'),'fraud',$now,$now->modify('+1 day')); $case->submitEvidence(str_repeat('a',64),$now,1);
    throwsR40(static fn () => $case->recordOutcome(false,new Money(100,'USD'),2), DomainException::class);
};
$tests['review 23 duplicate fraud signals are rejected'] = static function () use ($now): void {
    throwsR40(static fn () => new FraudReviewCase('risk:1','intent:1',[
        ['code'=>'velocity','weight'=>10,'evidence_ref'=>'evidence:1'],['code'=>'velocity','weight'=>20,'evidence_ref'=>'evidence:2']
    ],$now,$now->modify('+1 day')), DomainException::class);
};
$tests['review 24 fraud appeal requires fresh reviewer'] = static function () use ($now): void {
    $case = new FraudReviewCase('risk:1','intent:1',[['code'=>'velocity','weight'=>10,'evidence_ref'=>'evidence:1']],$now,$now->modify('+1 day)); $case->decide(false,'reviewer:1','declined',$now,1); $case->appeal('appeal',2);
    throwsR40(static fn () => $case->decide(true,'reviewer:1','same reviewer',$now,3), DomainException::class);
};
$tests['review 25 malformed reconciliation exception is rejected'] = static function (): void {
    throwsR40(static fn () => new ReconciliationResult([['type'=>'x','reference'=>'r','expected'=>-1,'actual'=>0,'currency'=>'usd','material'=>true]]), InvalidArgumentException::class);
};
$tests['review 26 malformed settlement line is rejected'] = static function () use ($now): void {
    throwsR40(static fn () => new SettlementBatch('batch:1','provider.sandbox',new Money(1,'USD'),new Money(0,'USD'),new Money(0,'USD'),new Money(1,'USD'),$now,str_repeat('a',64),[
        ['reference'=>'x','type'=>'payment','amount_minor'=>1,'currency'=>'USD']
    ]), InvalidArgumentException::class);
};
$tests['review 27 legacy single actor finance close is absent'] = static function (): void {
    sameR40(false, method_exists(FinancePeriod::class,'close'));
};
$tests['review 28 material reconciliation cannot bypass close'] = static function (): void {
    $period = new FinancePeriod('2026-08'); $period->beginReconciliation(1); $result = new ReconciliationResult([['type'=>'missing_line','reference'=>'payment:1','expected'=>100,'actual'=>0,'currency'=>'USD','material'=>true]]);
    throwsR40(static fn () => $period->approveClose($result,'reviewer:1','approver:1',2), DomainException::class);
};
$tests['review 29 export date range cannot be inverted'] = static function () use ($now): void {
    throwsR40(static fn () => new SecureExportJob('export:1','operator:1',['transaction_id'],['date_from'=>'2026-08-05','date_to'=>'2026-08-04'],100,$now->modify('+1 hour')), DomainException::class);
};
$tests['review 30 export artifact rejects traversal'] = static function () use ($now): void {
    $job = new SecureExportJob('export:1','operator:1',['transaction_id'],[],100,$now->modify('+1 hour')); $job->start(1);
    throwsR40(static fn () => $job->complete(str_repeat('a',64),'vault://finance/../secret',2), InvalidArgumentException::class);
};
$tests['review 31 legacy export neutralizes formula and secrets'] = static function () use ($now): void {
    sameR40("'=1+1", FinanceExport::neutralizeSpreadsheetFormula('=1+1'));
    throwsR40(static fn () => new FinanceExport('export:1',[['provider_secret'=>'x']],str_repeat('a',64),$now->getTimestamp()+60), DomainException::class);
};
$tests['review 32 audit metadata denies access tokens'] = static function () use ($now): void {
    throwsR40(static fn () => new AuditEnvelope('audit:1','operator:1','payment.review','intent','intent:1','finance.review',AuditOutcome::DENIED,$now,'trace:1',['access_token'=>'secret']), DomainException::class);
};
$tests['review 33 financial event payload is bounded'] = static function () use ($now): void {
    $payload=[]; for($i=0;$i<65;$i++){$payload['f'.$i]=$i;}
    throwsR40(static fn () => new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,$payload), InvalidArgumentException::class);
};
$tests['review 34 outbox retry must follow failure time'] = static function () use ($now): void {
    $message = new OutboxMessage(new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,[]),$now); $message->lease($now);
    throwsR40(static fn () => $message->fail('temporary_error',$now,$now), InvalidArgumentException::class);
};
$tests['review 35 backup manifest hash is validated'] = static function (): void {
    throwsR40(static fn () => new \Sabri\CF03\Application\BackupManifest(['ledger'=>['count'=>1,'hash'=>'bad']]), InvalidArgumentException::class);
};
$tests['review 36 invoice status is validated'] = static function (): void {
    throwsR40(static fn () => new Invoice('invoice:1','INV-1','user:1','Sabri',[['description'=>'Item','quantity'=>1,'unit_minor'=>100]],new Money(100,'USD'),'unknown',str_repeat('a',64)), InvalidArgumentException::class);
};
$tests['review 37 hosted checkout rejects nonstandard port'] = static function () use ($now): void {
    throwsR40(static fn () => new HostedCheckoutReference('provider.sandbox','session:1','https://payments.example.test:8443/session/1',$now,$now->modify('+30 minutes'),['payments.example.test']), InvalidArgumentException::class);
};
$tests['review 38 checkout rejects encoded slash redirect'] = static function () use ($now): void {
    [$product,$price] = donationCheckoutR40($now);
    throwsR40(static fn () => new CheckoutCommand('intent:1','user:1',$product,$price,$price->amount(),'idem-checkout-r40-0001',$now,$now->modify('+30 minutes'),'/billing%2Freturn'), InvalidArgumentException::class);
};
$tests['review 39 REST donation input rejects scientific amount'] = static function (): void {
    $result = WordPressRestApi::donationPreparing(new RestRequestR40(['amount_minor'=>'1e3','currency'=>'USD','monthly'=>'0'])); sameR40(422,$result['status']);
};
$tests['review 40 migration checksum drift is fatal'] = static function (): void {
    $runner = new MigrationRunner(static function(string $sql):void{},static fn(string $id):bool=>str_ends_with($id,'-products'),static function(string $id,string $checksum):void{},static fn(string $id):?string=>str_repeat('0',64));
    throwsR40(static fn () => $runner->migrate('wp_'), RuntimeException::class);
};

$failures=0;
foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));
exit($failures===0?0:1);

final class RestRequestR40
{
    /** @param array<string,mixed> $params */
    public function __construct(private array $params) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}

/** @return array<string,mixed> */
function activationR40(DateTimeImmutable $now): array
{
    $approval=static fn(string $id):array=>['approved'=>true,'evidence_id'=>'evidence:'.$id,'approver_ref'=>'approver:'.$id,'approved_at'=>$now->modify('-1 hour')->format(DATE_ATOM),'expires_at'=>$now->modify('+30 days')->format(DATE_ATOM)];
    return ['schema_version'=>'1.0','module_version'=>'1.0.0-rc.3','record_id'=>'activation:cf03:1','configuration_hash'=>str_repeat('a',64),'approvals'=>[
        'founder_change_control'=>$approval('founder'),'legal_tax_accounting_review'=>$approval('legal'),'pci_scope_validation'=>$approval('pci'),'independent_security_acceptance'=>$approval('security'),'staging_acceptance'=>$approval('staging'),'rollback_rehearsal'=>$approval('rollback')
    ],'provider'=>['mode'=>'hosted','provider_ref'=>'provider:sandbox','evidence_id'=>'evidence:provider','validated_at'=>$now->modify('-1 hour')->format(DATE_ATOM),'expires_at'=>$now->modify('+30 days')->format(DATE_ATOM)]];
}
function webhookR40(DateTimeImmutable $now): string
{
    return json_encode(['event_id'=>'event:1','type'=>'payment.settled','intent_id'=>'intent:1','amount_minor'=>1400,'currency'=>'USD','occurred_at'=>$now->format(DATE_ATOM)],JSON_THROW_ON_ERROR);
}
function evidenceR40(DateTimeImmutable $now,string $type,Money $amount): ProviderEvidence
{
    return new ProviderEvidence('provider.sandbox','event:'.str_replace('.','-',$type),$type,'intent:1',$amount,'key:v1',$now,$now,str_repeat('b',64),true,true,$now);
}
function readyIntentR40(DateTimeImmutable $now): PaymentIntent
{
    $intent=new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-r40-payment-0001',str_repeat('a',64),$now,$now->modify('+1 hour')); $intent->attachProviderReference('provider-payment:1',1,$now); $intent->transition(PaymentIntentState::PROVIDER_PENDING,2,$now); return $intent;
}
function ledgerR40(int $amount): LedgerTransaction
{
    return new LedgerTransaction('ledger:1',[new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money($amount,'USD'),'intent:1'),new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money($amount,'USD'),'intent:1')]);
}
function settlementServiceR40(): PaymentConfirmationService
{
    return new PaymentConfirmationService(static fn(callable $work):mixed=>$work(),static function(LedgerTransaction $transaction):void{},static function($event):void{});
}
function donationFactR40(DateTimeImmutable $now,DonationFinancialFactType $type,string $eventType,Money $amount): TrustedDonationFact
{
    return new TrustedDonationFact($type,evidenceR40($now,$eventType,$amount),'provider.sandbox','intent:1',$amount);
}
function consentR40(DateTimeImmutable $now): RecurringConsent
{
    return new RecurringConsent('consent:1','user:1','donation.monthly',new Money(1000,'USD'),'month',$now->modify('+1 month'),str_repeat('a',64),'/billing',$now,true);
}
/** @return array{0:FinancialProduct,1:PriceVersion} */
function donationCheckoutR40(DateTimeImmutable $now): array
{
    $product=new FinancialProduct('donation.general',ProductKind::DONATION,BillingType::VOLUNTARY,'cf03.finance',null,'refund.donation.v1','cancel.donation.v1',true,true,'approval.donation.1');
    $price=new PriceVersion('donation.general','price.usd.2026',new Money(1000,'USD'),'GLOBAL',TaxMode::NOT_APPLICABLE,$now->modify('-1 day'),null,'refund.donation.v1','cancel.donation.v1',true,'approval.price.1'); return [$product,$price];
}
function sameR40(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
function truthR40(bool $condition):void{if(!$condition){throw new RuntimeException('Expected condition to be true.');}}
/** @param class-string<Throwable> $class */
function throwsR40(callable $callback,string $class):void{try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}throw new RuntimeException('Expected '.$class.' to be thrown.');}
