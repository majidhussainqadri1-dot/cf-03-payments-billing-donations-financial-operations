<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\IncidentPathGuard;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Support\InvariantViolation;

final class AdversarialIncidentStore implements IncidentStateStore
{
    /** @param array<string,mixed> $state */ public function __construct(private array $state){}
    public function get():array{return $this->state;}
    public function save(array $state):void{$this->state=$state;}
}

$now=new DateTimeImmutable('2026-08-05T20:00:00+05:00');
$gates=['founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,'webhook_endpoint'=>true];
$runtime=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,true,false);
$tests=[];
$tests['01 unknown incident state fails closed']=static function():void{throwsAS(static fn()=>(new IncidentPathGuard(new AdversarialIncidentStore(['state'=>'corrupt'])))->assertAvailable('checkout'),InvariantViolation::class);};
$tests['02 unknown incident path is rejected']=static function():void{throwsAS(static fn()=>(new IncidentPathGuard(new AdversarialIncidentStore(['state'=>'normal'])))->assertAvailable('payouts'),InvalidArgumentException::class);};
$tests['03 audit repository rejects chain fork']=static function()use($now):void{$repo=new MemoryFinancialRepository(true);$record=['actor_ref'=>'user:a','purpose'=>'test','action'=>'test','outcome'=>'succeeded','trace_id'=>'trace:a','metadata_json'=>[],'metadata_hash'=>str_repeat('a',64),'previous_hash'=>str_repeat('0',64),'entry_hash'=>str_repeat('b',64),'created_at'=>$now];$repo->insert('audit','audit:a',['audit_id'=>'audit:a']+$record);throwsAS(static fn()=>$repo->insert('audit','audit:b',['audit_id'=>'audit:b']+array_replace($record,['entry_hash'=>str_repeat('c',64)])),InvariantViolation::class);};
$tests['04 serialized audit service remains valid after retries']=static function()use($now):void{$repo=new MemoryFinancialRepository(true);$audit=new FinancialAuditService($repo);for($i=0;$i<5;$i++){$audit->append(new AuditEnvelope('audit:serial:'.$i,'user:a','serial_test','audit','object:'.$i,'adversarial',AuditOutcome::SUCCEEDED,$now,'trace:serial:'.$i,['i'=>$i]));}sameAS(true,$audit->verifyChain());};
$tests['05 schema makes audit fork impossible']=static function():void{sameAS(true,str_contains(CompleteSchema::tables('wp_')['audit'],'UNIQUE KEY previous_hash(previous_hash)'));};
$tests['06 settlement post rejects importer even after reconciliation']=static function()use($runtime,$now):void{$repo=new MemoryFinancialRepository(true);$audit=new FinancialAuditService($repo);$lines=[['reference'=>'pay:a','type'=>'payment','amount_minor'=>100,'currency'=>'USD']];$batch=new SettlementBatch('batch:a','provider.sandbox',new Money(100,'USD'),Money::zero('USD'),Money::zero('USD'),new Money(100,'USD'),$now,str_repeat('a',64),$lines);$service=new SettlementOperationsService($repo,$runtime,$audit);$service->importAndReconcile($batch,$lines,['USD'=>0],'user:same',$now);throwsAS(static fn()=>$service->postResolvedBatch('batch:a','user:same',$now),InvariantViolation::class);};
$tests['07 period close rejects caller supplied fake review']=static function()use($runtime,$now):void{$repo=new MemoryFinancialRepository(true);$audit=new FinancialAuditService($repo);$repo->insert('settlements','batch:b',['batch_id'=>'batch:b','provider'=>'provider.sandbox','gross_minor'=>1,'fee_minor'=>0,'refund_minor'=>0,'net_minor'=>1,'currency'=>'USD','source_hash'=>str_repeat('a',64),'settled_at'=>$now,'imported_at'=>$now,'imported_by'=>'user:importer','posted_by'=>'user:poster','posted_at'=>$now,'status'=>'posted']);throwsAS(static fn()=>(new SettlementOperationsService($repo,$runtime,$audit))->closePeriod('2026-08','user:invented','user:approver',$now),InvariantViolation::class);};
$tests['08 adjustment rejects identical accounts']=static function()use($runtime,$now):void{$repo=seedAS($now);$service=new FinancialAdjustmentService($repo,$runtime,new FinancialAuditService($repo));throwsAS(static fn()=>$service->request('adjustment:a','txn:a',new Money(10,'USD'),'asset.provider_clearing','asset.provider_clearing','correction',str_repeat('a',64),'user:r',$now),InvalidArgumentException::class);};
$tests['09 adjustment identifier cannot be reused with changed amount']=static function()use($runtime,$now):void{$repo=seedAS($now);$service=new FinancialAdjustmentService($repo,$runtime,new FinancialAuditService($repo));$service->request('adjustment:a','txn:a',new Money(10,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('a',64),'user:r',$now);throwsAS(static fn()=>$service->request('adjustment:a','txn:a',new Money(11,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('a',64),'user:r',$now),InvariantViolation::class);};
$tests['10 rejected adjustment cannot execute']=static function()use($runtime,$now):void{$repo=seedAS($now);$service=new FinancialAdjustmentService($repo,$runtime,new FinancialAuditService($repo));$service->request('adjustment:r','txn:a',new Money(10,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('a',64),'user:r',$now);$service->decide('adjustment:r',false,'user:reviewer',1,$now);throwsAS(static fn()=>$service->execute('adjustment:r','user:executor',2,$now),InvariantViolation::class);};
$tests['11 adjustment cannot post into locked current period']=static function()use($runtime,$now):void{$repo=seedAS($now);$repo->insert('finance_periods','2026-08',['period_id'=>'2026-08','state'=>'locked','reviewed_by'=>'user:x','reviewed_at'=>$now,'approved_by'=>'user:y','accepted_risk_ref'=>null,'closed_at'=>$now,'record_version'=>1]);$service=new FinancialAdjustmentService($repo,$runtime,new FinancialAuditService($repo));$service->request('adjustment:l','txn:a',new Money(10,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('a',64),'user:r',$now);$service->decide('adjustment:l',true,'user:reviewer',1,$now);throwsAS(static fn()=>$service->execute('adjustment:l','user:executor',2,$now),InvariantViolation::class);};
$tests['12 finance export endpoint is not merely authenticated']=static function():void{$source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressFinanceAdminApi.php');sameAS(false,str_contains((string)$source,"['/exports', 'POST', 'requestExport', 'authenticated']"));sameAS(true,str_contains((string)$source,"'exports' => static fn (): bool => self::cap('sabri_manage_finance_exports')"));};
$tests['13 close endpoint reads persisted reviewer rather than request field']=static function():void{$source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressFinanceAdminApi.php');sameAS(true,str_contains((string)$source,"$period['reviewed_by']"));sameAS(false,str_contains((string)$source,"self::param($request, 'reviewer_reference')"));};
$tests['14 refund and webhook routes consult incident guard']=static function():void{$source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressRestApi.php');sameAS(true,substr_count((string)$source,"assertAvailable('refunds')")>=3);sameAS(true,substr_count((string)$source,"assertAvailable('webhooks')")>=1);};

$failures=0;foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
function seedAS(DateTimeImmutable $now):MemoryFinancialRepository{$repo=new MemoryFinancialRepository(true);$repo->insert('ledger_transactions','txn:a',['transaction_id'=>'txn:a','source_type'=>'seed','source_ref'=>'seed:a','effective_at'=>$now,'recorded_at'=>$now,'actor_ref'=>'system:test','reason'=>'seed','period_id'=>'2026-08','reversal_of'=>null,'trace_id'=>'trace:a']);return$repo;}
function sameAS(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */function throwsAS(callable $callback,string $class):void{try{$callback();}catch(Throwable $e){if($e instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
