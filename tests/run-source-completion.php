<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\IncidentPathGuard;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Support\InvariantViolation;

final class SourceArtifactStore implements SecureArtifactStore
{
    /** @var array<string,string> */ public array $objects=[];
    public function put(string $filename,string $mediaType,string $contents,DateTimeImmutable $expiresAt):array{$ref='vault://finance/'.$filename;$this->objects[$ref]=$contents;return ['object_ref'=>$ref,'sha256'=>hash('sha256',$contents),'size_bytes'=>strlen($contents)];}
    public function delete(string $objectReference):void{unset($this->objects[$objectReference]);}
}
final class SourceIncidentStore implements IncidentStateStore
{
    /** @param array<string,mixed> $state */ public function __construct(private array $state){}
    public function get():array{return $this->state;}
    public function save(array $state):void{$this->state=$state;}
}

$now=new DateTimeImmutable('2026-08-05T16:18:00+05:00');
$gates=['founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,'webhook_endpoint'=>true,'secure_delivery'=>true,'download_delivery'=>true];
$runtime=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,true,true);
$repo=new MemoryFinancialRepository(true);
$audit=new FinancialAuditService($repo);
$tests=[];

$tests['01 schema 3.3.0 keeps 31 canonical tables']=static function():void{sameSC('3.3.0',CompleteSchema::VERSION);sameSC(31,count(CompleteSchema::tables('wp_')));};
$tests['02 schema contains serialized audit and duty fields']=static function():void{$t=CompleteSchema::tables('wp_');sameSC(true,str_contains($t['audit'],'UNIQUE KEY previous_hash(previous_hash)'));sameSC(true,str_contains($t['settlements'],'imported_by varchar(191) NOT NULL'));sameSC(true,str_contains($t['finance_periods'],'reviewed_at datetime(6) NULL'));sameSC(true,str_contains($t['adjustments'],'debit_account varchar(128) NOT NULL'));};
$tests['03 schema parser does not invent composite columns']=static function():void{$columns=WordPressSchemaInstaller::requiredColumns(CompleteSchema::tables('wp_')['settlement_lines']);sameSC(true,in_array('line_ref',$columns,true));sameSC(false,in_array('batch_id,line_ref',$columns,true));};
$tests['04 audit chain remains valid with same timestamp entries']=static function()use($audit,$now):void{foreach(['one','two'] as$suffix){$audit->append(new AuditEnvelope('audit:source:'.$suffix,'user:auditor','source_verified','source_test','object:'.$suffix,'source_completion',AuditOutcome::SUCCEEDED,$now,'trace:source:'.$suffix,['sequence'=>$suffix]));}sameSC(true,$audit->verifyChain());};
$tests['05 source transaction is immutable balanced evidence']=static function()use($repo,$now):void{$repo->insert('ledger_transactions','txn:source:1',['transaction_id'=>'txn:source:1','source_type'=>'provider_settlement','source_ref'=>'provider:event:source1','effective_at'=>$now,'recorded_at'=>$now,'actor_ref'=>'system:provider','reason'=>'trusted_provider_settlement','period_id'=>'2026-08','reversal_of'=>null,'trace_id'=>'trace:source:1']);$repo->insert('ledger_entries','source:asset',['transaction_id'=>'txn:source:1','account'=>'asset.provider_clearing','direction'=>'debit','amount_minor'=>1000,'currency'=>'USD','source_ref'=>'source:asset']);$repo->insert('ledger_entries','source:income',['transaction_id'=>'txn:source:1','account'=>'income.donation','direction'=>'credit','amount_minor'=>1000,'currency'=>'USD','source_ref'=>'source:income']);};
$tests['06 settlement importer cannot post own batch']=static function()use($repo,$runtime,$audit,$now):void{$lines=[['reference'=>'pay:source','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],['reference'=>'refund:source','type'=>'refund','amount_minor'=>100,'currency'=>'USD'],['reference'=>'fee:source','type'=>'fee','amount_minor'=>50,'currency'=>'USD']];$batch=new SettlementBatch('batch:source:1','provider.sandbox',new Money(1000,'USD'),new Money(50,'USD'),new Money(100,'USD'),new Money(850,'USD'),$now,str_repeat('a',64),$lines);$service=new SettlementOperationsService($repo,$runtime,$audit);$service->importAndReconcile($batch,$lines,['USD'=>1],'user:importer',$now);throwsSC(static fn()=>$service->postResolvedBatch('batch:source:1','user:importer',$now->modify('+1 minute')),InvariantViolation::class);};
$tests['07 independent settlement poster succeeds']=static function()use($repo,$runtime,$audit,$now):void{$result=(new SettlementOperationsService($repo,$runtime,$audit))->postResolvedBatch('batch:source:1','user:poster',$now->modify('+2 minutes'));sameSC('posted',$result['status']);sameSC('user:poster',$result['posted_by']);};
$tests['08 finance close is blocked before persisted review']=static function()use($repo,$runtime,$audit,$now):void{throwsSC(static fn()=>(new SettlementOperationsService($repo,$runtime,$audit))->closePeriod('2026-08','user:reviewer','user:approver',$now),InvariantViolation::class);};
$tests['09 independent review and approval lock period']=static function()use($repo,$runtime,$audit,$now):void{$service=new SettlementOperationsService($repo,$runtime,$audit);$service->reviewPeriod('2026-08','user:reviewer',$now->modify('+3 minutes'));throwsSC(static fn()=>$service->closePeriod('2026-08','user:reviewer','user:reviewer',$now),InvariantViolation::class);$closed=$service->closePeriod('2026-08','user:reviewer','user:approver',$now->modify('+4 minutes'));sameSC('locked',$closed['state']);};
$tests['10 normal incident overlay does not block configured runtime']=static function():void{$guard=new IncidentPathGuard(new SourceIncidentStore(['state'=>'normal','checkout_enabled'=>false,'refunds_enabled'=>false,'webhooks_enabled'=>false]));$guard->assertAvailable('checkout');$guard->assertAvailable('refunds');$guard->assertAvailable('webhooks');};
$tests['11 contained incident selectively blocks disabled paths']=static function():void{$guard=new IncidentPathGuard(new SourceIncidentStore(['state'=>'contained','checkout_enabled'=>false,'refunds_enabled'=>true,'webhooks_enabled'=>false]));throwsSC(static fn()=>$guard->assertAvailable('checkout'),InvariantViolation::class);$guard->assertAvailable('refunds');throwsSC(static fn()=>$guard->assertAvailable('webhooks'),InvariantViolation::class);};
$tests['12 recovered incident keeps unrecovered path disabled']=static function():void{$guard=new IncidentPathGuard(new SourceIncidentStore(['state'=>'recovered','checkout_enabled'=>true,'refunds_enabled'=>false,'webhooks_enabled'=>true]));$guard->assertAvailable('checkout');throwsSC(static fn()=>$guard->assertAvailable('refunds'),InvariantViolation::class);};
$tests['13 adjustment request requires canonical source and accounts']=static function()use($repo,$runtime,$audit,$now):void{$service=new FinancialAdjustmentService($repo,$runtime,$audit);throwsSC(static fn()=>$service->request('adjustment:bad','txn:missing',new Money(10,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('b',64),'user:requester',$now),InvariantViolation::class);$result=$service->request('adjustment:1','txn:source:1',new Money(100,'USD'),'expense.financial_adjustment','asset.provider_clearing','correction',str_repeat('c',64),'user:requester',$now);sameSC('requested',$result['state']);};
$tests['14 adjustment requester cannot self approve']=static function()use($repo,$runtime,$audit,$now):void{throwsSC(static fn()=>(new FinancialAdjustmentService($repo,$runtime,$audit))->decide('adjustment:1',true,'user:requester',1,$now),InvariantViolation::class);};
$tests['15 adjustment approver cannot execute own decision']=static function()use($repo,$runtime,$audit,$now):void{$service=new FinancialAdjustmentService($repo,$runtime,$audit);sameSC('approved',$service->decide('adjustment:1',true,'user:adjuster-reviewer',1,$now)['state']);throwsSC(static fn()=>$service->execute('adjustment:1','user:adjuster-reviewer',2,$now->modify('+1 month')),InvariantViolation::class);};
$tests['16 independent adjustment execution posts next open period']=static function()use($repo,$runtime,$audit,$now):void{$before=$repo->get('ledger_transactions','txn:source:1');$result=(new FinancialAdjustmentService($repo,$runtime,$audit))->execute('adjustment:1','user:adjuster-executor',2,new DateTimeImmutable('2026-09-01T10:00:00+05:00'));sameSC('executed',$result['state']);sameSC('2026-09',$result['period_id']);sameSC($before,$repo->get('ledger_transactions','txn:source:1'));};
$tests['17 adjustment ledger transaction is balanced']=static function()use($repo):void{$entries=$repo->find('ledger_entries',['transaction_id'=>'txn.adjustment.'.substr(hash('sha256','adjustment:1'),0,32)],10);[$d,$c]=totalsSC($entries);sameSC(100,$d);sameSC($d,$c);};
$tests['18 global finance export routes require finance capability']=static function():void{$source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressFinanceAdminApi.php');sameSC(true,is_string($source));sameSC(true,str_contains($source,"['/exports', 'POST', 'requestExport', 'exports']"));sameSC(false,str_contains($source,"['/exports', 'POST', 'requestExport', 'authenticated']"));};
$tests['19 runtime endpoints enforce incident path guard']=static function():void{$source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressRestApi.php');sameSC(true,is_string($source));foreach(["assertAvailable('checkout')","assertAvailable('refunds')","assertAvailable('webhooks')"]as$needle){sameSC(true,str_contains($source,$needle));}};
$store=new SourceArtifactStore();
$tests['20 secure export remains bounded encrypted and revocable']=static function()use($repo,$runtime,$audit,$store,$now):void{$service=new SecureExportService($repo,$store,$runtime,$audit);$service->request('export:source','user:finance',['transaction_id','source_type','amount_minor','currency','effective_at','period_id'],['currency'=>'USD'],200,$now->modify('+1 day'),$now);$ready=$service->process('export:source','user:finance',1,$now->modify('+1 minute'));sameSC('ready',$ready['state']);$grant=$service->grant('export:source','user:finance',true,$now->modify('+2 minutes'),$now->modify('+12 minutes'));sameSC('finance_export',$grant->toPresentationContract()['asset_type']);$service->revoke('export:source','user:finance',true,3,$now->modify('+3 minutes'));sameSC(0,count($store->objects));};
$tests['21 integrity validates ledger audit and backup manifest']=static function()use($repo,$audit):void{$service=new SystemIntegrityService($repo,$audit);$health=$service->health();sameSC(true,$health['ledger_balanced']);sameSC(true,$health['audit_chain_valid']);$manifest=$service->buildBackupManifest();sameSC(true,$service->verifyRestore($manifest,$manifest,['event:1'],['event:1'])['accepted']);};

$failures=0;foreach($tests as$n=>$t){try{$t();fwrite(STDOUT,"PASS: {$n}\n");}catch(Throwable$e){$failures++;fwrite(STDERR,"FAIL: {$n}: {$e->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
/** @param list<array<string,mixed>> $entries @return array{0:int,1:int} */function totalsSC(array$entries):array{$d=0;$c=0;foreach($entries as$e){if(($e['direction']??null)==='debit'){$d+=(int)$e['amount_minor'];}elseif(($e['direction']??null)==='credit'){$c+=(int)$e['amount_minor'];}}return[$d,$c];}
function sameSC(mixed$e,mixed$a):void{if($e!==$a){throw new RuntimeException('Expected '.var_export($e,true).', got '.var_export($a,true));}}
/** @param class-string<Throwable> $class */function throwsSC(callable$c,string$class):void{try{$c();}catch(Throwable$e){if($e instanceof$class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
