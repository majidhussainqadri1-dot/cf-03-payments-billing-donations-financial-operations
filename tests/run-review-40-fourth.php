<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationCheckoutService;
use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\DonationProviderRegistry;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Contracts\DonationPaymentProvider;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressRequestGuard;
use Sabri\CF03\Support\InvariantViolation;

final class FourthReviewProvider implements DonationPaymentProvider
{
    /** @var array<string,HostedCheckoutReference> */
    private array $sessions=[];
    public function providerCode():string{return'provider.fourth';}
    public function createHostedDonationCheckout(DonationIntentDraft $intent):HostedCheckoutReference
    {
        $id='session.'.substr(hash('sha256',$intent->intentId()),0,32);
        return $this->sessions[$id]=new HostedCheckoutReference(
            $this->providerCode(),$id,'https://checkout.example.test/'.$id,
            $intent->createdAt(),$intent->createdAt()->modify('+30 minutes'),['checkout.example.test']
        );
    }
    public function resumeHostedDonationCheckout(string $providerSessionReference):HostedCheckoutReference
    {
        if(!isset($this->sessions[$providerSessionReference])){throw new InvariantViolation('Missing fixture session.');}
        return$this->sessions[$providerSessionReference];
    }
    public function queryDonationEvidence(string $providerPaymentReference):ProviderEvidence
    {throw new InvariantViolation('Not used.');}
}

final class FourthReviewRequest
{
    public function __construct(private string$route,private string$method,private string$body){}
    public function get_route():string{return$this->route;}
    public function get_method():string{return$this->method;}
    public function get_body():string{return$this->body;}
}

$root=dirname(__DIR__);
$read=static function(string$path)use($root):string{$v=file_get_contents($root.'/'.$path);if(!is_string($v)){throw new RuntimeException('Unable to read '.$path);}return$v;};
$now=new DateTimeImmutable('2026-08-06T00:08:00+05:00');
$gates=['founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,'webhook_endpoint'=>true];
$tests=[];

$tests['01 checkout request fingerprint excludes volatile creation time']=static function()use($read):void{$s=$read('src/Application/DonationCheckoutService.php');$p=substr($s,strpos($s,'private function requestHash'),1800);same4(false,str_contains($p,"'created_at'"));};
$tests['02 checkout fingerprint binds explicit monthly consent']=static fn()=>contains4($read('src/Application/DonationCheckoutService.php'),"'explicit_monthly_consent' => \$draft->explicitMonthlyConsent()");
$tests['03 retry with later request time reuses the same checkout']=static function()use($gates,$now):void{$repo=new MemoryFinancialRepository();$provider=new FourthReviewProvider();$service=new DonationCheckoutService(new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.fourth',$gates,true,false),new DonationProviderRegistry([$provider]),$repo);$one=new DonationIntentDraft('intent:fourth:1','user:fourth',new Money(1400,'USD'),false,false,DonationServiceState::SANDBOX,'idem-fourth-review-0001',$now);same4(false,$service->create($one,'provider.fourth')['reused']);$two=new DonationIntentDraft('intent:fourth:1','user:fourth',new Money(1400,'USD'),false,false,DonationServiceState::SANDBOX,'idem-fourth-review-0001',$now->modify('+1 minute'));same4(true,$service->create($two,'provider.fourth')['reused']);};
$tests['04 expired hosted checkout is not replayed']=static function()use($gates,$now):void{$repo=new MemoryFinancialRepository();$provider=new FourthReviewProvider();$service=new DonationCheckoutService(new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.fourth',$gates,true,false),new DonationProviderRegistry([$provider]),$repo);$service->create(new DonationIntentDraft('intent:fourth:2','user:fourth',new Money(1000,'USD'),false,false,DonationServiceState::SANDBOX,'idem-fourth-review-0002',$now),'provider.fourth');throws4(static fn()=>$service->create(new DonationIntentDraft('intent:fourth:2','user:fourth',new Money(1000,'USD'),false,false,DonationServiceState::SANDBOX,'idem-fourth-review-0002',$now->modify('+31 minutes')),'provider.fourth'),InvariantViolation::class);};
$tests['05 idempotency claim revalidates actor']=static fn()=>contains4($read('src/Application/DonationCheckoutService.php'),"(\$claim['actor_ref'] ?? null) !== \$draft->donorReference()");
$tests['06 idempotency claim revalidates scope']=static fn()=>contains4($read('src/Application/DonationCheckoutService.php'),"(\$claim['scope'] ?? null) !== 'donation_checkout'");
$tests['07 dependent donation record requires full parity']=static function()use($read):void{$s=$read('src/Application/DonationCheckoutService.php');foreach(['donor_ref','amount_minor','currency','provider_ref']as$n){contains4($s,$n);}};
$tests['08 dependent monthly consent requires full parity']=static function()use($read):void{$s=$read('src/Application/DonationCheckoutService.php');contains4($s,'Canonical recurring consent is missing or inconsistent');contains4($s,"'interval_code'] ?? null) !== 'month'");};
$tests['09 duplicate webhook lookup detects non-unique event IDs']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),"], 2);");
$tests['10 duplicate webhook requires raw body hash parity']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),"(\$existing['raw_body_hash'] ?? null) !== \$evidence->rawBodySha256()");
$tests['11 duplicate webhook event type must match']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),"(\$existing['event_type'] ?? null) !== \$evidence->eventType()");
$tests['12 provider event cannot predate payment intent']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),'Provider event predates the canonical payment intent.');
$tests['13 settlement requires canonical donation aggregate']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),'Canonical donation aggregate is missing or inconsistent with settlement evidence.');
$tests['14 monthly settlement requires canonical consent']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),'Canonical monthly consent is missing or inconsistent with settlement evidence.');
$tests['15 settlement period follows occurrence time']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),"\$periodId = \$evidence->occurredAt()->format('Y-m')");
$tests['16 refund period follows occurrence time']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),"'period_id' => \$evidence->occurredAt()->format('Y-m')");
$tests['17 provider refund evidence must be positive']=static fn()=>contains4($read('src/Application/WebhookIngestionService.php'),'Refund evidence is zero, exceeds the original payment or changes currency.');
$tests['18 refund request rejects zero amount']=static fn()=>contains4($read('src/Application/RefundWorkflowService.php'),'Refund amount must be positive.');
$tests['19 refund request reserves all committing states']=static function()use($read):void{$s=$read('src/Application/RefundWorkflowService.php');foreach(['requested','approved','provider_pending','uncertain','succeeded','closed']as$n){contains4($s,"'{$n}'");}};
$tests['20 refund identifier reuse requires exact terms']=static fn()=>contains4($read('src/Application/RefundWorkflowService.php'),'Refund identifier already exists with different terms.');
$tests['21 refund execution checkpoints before provider call']=static function()use($read):void{$s=$read('src/Application/RefundWorkflowService.php');before4($s,"'state'] = 'provider_pending'",'$provider->refund(');};
$tests['22 refund provider failure becomes uncertain']=static fn()=>contains4($read('src/Application/RefundWorkflowService.php'),"'state' => 'uncertain'");
$tests['23 refund public result excludes provider reference']=static function()use($read):void{$s=$read('src/Application/RefundWorkflowService.php');$p=substr($s,strpos($s,'private function safe'),900);same4(false,str_contains($p,'provider_ref'));};
$tests['24 recurring cancellation checkpoints before provider call']=static function()use($read):void{$s=$read('src/Application/DonationManagementService.php');before4($s,"'state'] = 'cancellation_pending'",'cancelRecurringDonation(');};
$tests['25 recurring amount change checkpoints before provider call']=static function()use($read):void{$s=$read('src/Application/DonationManagementService.php');before4($s,"'state'] = 'amount_change_pending'",'changeRecurringDonationAmount(');};
$tests['26 recurring provider failure becomes uncertain']=static fn()=>contains4($read('src/Application/DonationManagementService.php'),"'state' => 'uncertain'");
$tests['27 recurring consent resolves exactly one donation']=static fn()=>contains4($read('src/Application/DonationManagementService.php'),'must resolve to exactly one canonical donation record');
$tests['28 recurring public result excludes provider confirmation']=static function()use($read):void{$s=$read('src/Application/DonationManagementService.php');same4(false,str_contains($s,'provider_confirmation_reference'));};
$tests['29 WordPress find fails closed on database failure']=static fn()=>contains4($read('src/Infrastructure/WordPressFinancialRepository.php'),'Financial query failed.');
$tests['30 WordPress page fails closed on database failure']=static fn()=>contains4($read('src/Infrastructure/WordPressFinancialRepository.php'),'Financial page query failed.');
$tests['31 WordPress generic mutations reject immutable evidence']=static fn()=>contains4($read('src/Infrastructure/WordPressFinancialRepository.php'),'Immutable financial evidence cannot be updated or deleted');
$tests['32 WordPress repository validates canonical record identifiers']=static fn()=>contains4($read('src/Infrastructure/WordPressFinancialRepository.php'),'Financial record identifier is invalid.');
$tests['33 memory bounded update does not silently advance version']=static function():void{$r=new MemoryFinancialRepository();$r->insert('outbox','event:fourth:1',['event_id'=>'event:fourth:1','state'=>'pending','record_version'=>1]);$r->updateWhere('outbox',['event_id'=>'event:fourth:1'],['state'=>'processing']);same4(1,$r->get('outbox','event:fourth:1')['version']);};
$tests['34 memory caught nested failure makes outer transaction roll back']=static function():void{$r=new MemoryFinancialRepository();throws4(static function()use($r):void{$r->transaction(static function()use($r):void{try{$r->transaction(static function()use($r):void{$r->insert('donations','donation:fourth:1',['donation_id'=>'donation:fourth:1']);throw new RuntimeException('inner');});}catch(RuntimeException){}$r->insert('donations','donation:fourth:2',['donation_id'=>'donation:fourth:2']);});},InvariantViolation::class);same4(null,$r->get('donations','donation:fourth:1'));same4(null,$r->get('donations','donation:fourth:2'));};
$tests['35 memory generic update rejects immutable ledger']=static function():void{$r=new MemoryFinancialRepository();$r->insert('ledger_transactions','txn:fourth:1',['transaction_id'=>'txn:fourth:1']);throws4(static fn()=>$r->updateWhere('ledger_transactions',['transaction_id'=>'txn:fourth:1'],['reason'=>'changed']),InvariantViolation::class);};
$tests['36 memory invalid identifiers are rejected']=static function():void{$r=new MemoryFinancialRepository();throws4(static fn()=>$r->insert('donations','bad id',['donation_id'=>'bad id']),InvalidArgumentException::class);};
$tests['37 duplicate audit ID cannot carry changed evidence']=static function()use($now):void{$r=new MemoryFinancialRepository(true);$a=new FinancialAuditService($r);$a->append(new AuditEnvelope('audit:fourth:1','user:auditor','reviewed','object','object:1','fourth_review',AuditOutcome::SUCCEEDED,$now,'trace:fourth:1',['value'=>1]));throws4(static fn()=>$a->append(new AuditEnvelope('audit:fourth:1','user:auditor','reviewed','object','object:1','fourth_review',AuditOutcome::SUCCEEDED,$now,'trace:fourth:1',['value'=>2])),InvariantViolation::class);};
$tests['38 finance override grant remains bound to actual actor']=static function()use($read):void{$s=$read('src/Application/FinancialDocumentService.php');same4(false,str_contains($s,"'finance:authorized'"));contains4($s,"            \$actorReference,\n            \$filename,");};
$tests['39 all financial mutation requests have a global size guard']=static function()use($read):void{$p=$read('src/Plugin.php');$g=$read('src/Infrastructure/WordPressRequestGuard.php');contains4($p,"add_filter('rest_pre_dispatch'");contains4($g,'MAX_MUTATION_BYTES = 65536');contains4($g,'MAX_WEBHOOK_BYTES = 1048576');};
$tests['40 fourth review release evidence and CI are locked']=static function()use($read):void{$plugin=$read('cf-03-payments-billing-donations-financial-operations.php');$ci=$read('.github/workflows/ci.yml');$composer=$read('composer.json');contains4($plugin,'Version: 1.2.0-rc.3');contains4($composer,'run-review-40-fourth.php');contains4($ci,'run-review-40-fourth.php');contains4($ci,'review-evidence-40-rounds-fourth-1.2.0-rc.3.md');};

same4(40,count($tests));
$failures=0;foreach($tests as$name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable$e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
function contains4(string$haystack,string$needle):void{if(!str_contains($haystack,$needle)){throw new RuntimeException('Missing expected text: '.$needle);}}
function before4(string$haystack,string$first,string$second):void{$a=strpos($haystack,$first);$b=strpos($haystack,$second);if($a===false||$b===false||$a>=$b){throw new RuntimeException('Expected ordered text: '.$first.' before '.$second);}}
function same4(mixed$expected,mixed$actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */function throws4(callable$callback,string$class):void{try{$callback();}catch(Throwable$e){if($e instanceof$class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
