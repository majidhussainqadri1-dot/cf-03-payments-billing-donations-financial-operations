<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\CatalogDisclosureService;
use Sabri\CF03\Application\ExpenseTransparencyService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\IncidentOperationsService;
use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\DonationExpenseCategory;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Support\InvariantViolation;

final class CompletionArtifactStore implements SecureArtifactStore
{
    /** @var array<string,string> */ public array $objects=[];
    public function put(string $filename,string $mediaType,string $contents,DateTimeImmutable $expiresAt):array{$ref='vault://finance/'.$filename;$this->objects[$ref]=$contents;return ['object_ref'=>$ref,'sha256'=>hash('sha256',$contents),'size_bytes'=>strlen($contents)];}
    public function delete(string $objectReference):void{unset($this->objects[$objectReference]);}
}
final class CompletionRetentionExecutor implements RetentionActionExecutor
{
    public array $actions=[];
    public function archive(string $recordType,string $recordReference):string{return $this->record('archive',$recordType,$recordReference);}
    public function anonymize(string $recordType,string $recordReference):string{return $this->record('anonymize',$recordType,$recordReference);}
    public function delete(string $recordType,string $recordReference):string{return $this->record('delete',$recordType,$recordReference);}
    private function record(string $action,string $type,string $reference):string{$this->actions[]=$action.':'.$type.':'.$reference;return 'evidence:'.$action.':'.substr(hash('sha256',$type.'|'.$reference),0,20);}
}
final class CompletionIncidentStore implements IncidentStateStore
{
    private array $state=['state'=>'normal','incident_id'=>null,'severity'=>null,'reason_code'=>null,'checkout_enabled'=>false,'refunds_enabled'=>false,'webhooks_enabled'=>false,'declared_at'=>null,'declared_by'=>null,'recovered_at'=>null,'recovered_by'=>null,'resolution_evidence_ref'=>null,'record_version'=>1];
    public function get():array{return $this->state;}
    public function save(array $state):void{$this->state=$state;}
}

$now=new DateTimeImmutable('2026-08-05T16:18:00+05:00');
$gates=['founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,'webhook_endpoint'=>true,'secure_delivery'=>true,'download_delivery'=>true];
$runtime=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,true,true);
$repo=new MemoryFinancialRepository(true);
$audit=new FinancialAuditService($repo);
$tests=[];

$tests['01 complete schema is runtime 3.1.0 with 31 canonical tables']=static function():void{sameSC('3.1.0',CompleteSchema::VERSION);sameSC('3.0.0',CompleteSchema::BASE_VERSION);sameSC(31,count(CompleteSchema::tables('wp_')));};
$tests['02 runtime schema adds recurring consent version']=static function():void{$sql=CompleteSchema::tables('wp_')['recurring_consents'];sameSC(true,str_contains($sql,'record_version bigint unsigned NOT NULL DEFAULT 1'));};
$tests['03 runtime schema makes ledger source unique']=static function():void{$sql=CompleteSchema::tables('wp_')['ledger_entries'];sameSC(true,str_contains($sql,'UNIQUE KEY source_once(source_ref)'));};
$tests['04 runtime schema stores export specification and exception resolution']=static function():void{$tables=CompleteSchema::tables('wp_');sameSC(true,str_contains($tables['exports'],'specification_json longtext NOT NULL'));sameSC(true,str_contains($tables['reconciliation_exceptions'],'resolution_ref varchar(191) NULL'));};
$tests['05 schema verifier parses composite keys without fake columns']=static function():void{$columns=WordPressSchemaInstaller::requiredColumns(CompleteSchema::tables('wp_')['settlement_lines']);sameSC(true,in_array('line_ref',$columns,true));sameSC(false,in_array('batch_id,line_ref',$columns,true));};
$tests['06 public catalog exposes free core services and donation only']=static function()use($repo,$audit,$now):void{$catalog=(new CatalogDisclosureService($repo,$audit))->publicCatalog($now);sameSC('free',$catalog['core_services']['membership']);sameSC('free',$catalog['core_services']['ai']);sameSC(0,$catalog['clinic_marketplace_commission_basis_points']);sameSC(false,$catalog['donation']['default_recurring']);};
$tests['07 audit chain supports same-time insertion order']=static function()use($audit,$now):void{foreach(['one','two'] as $suffix){$audit->append(new AuditEnvelope('audit:test:'.$suffix,'user:auditor','audit_tested','financial_test','object:'.$suffix,'source_completion',AuditOutcome::SUCCEEDED,$now,'trace:test:'.$suffix,['sequence'=>$suffix]));}sameSC(true,$audit->verifyChain());};

$tests['08 seed donation settlement ledger']=static function()use($repo,$now):void{$repo->insert('ledger_transactions','txn:donation:1',['transaction_id'=>'txn:donation:1','source_type'=>'provider_settlement','source_ref'=>'provider:event:donation1','effective_at'=>$now,'recorded_at'=>$now,'actor_ref'=>'system:provider','reason'=>'trusted_provider_settlement','period_id'=>'2026-08','reversal_of'=>null,'trace_id'=>'trace:donation:1']);$repo->insert('ledger_entries','donation1:asset',['transaction_id'=>'txn:donation:1','account'=>'asset.provider_clearing','direction'=>'debit','amount_minor'=>1000,'currency'=>'USD','source_ref'=>'donation1:asset']);$repo->insert('ledger_entries','donation1:income',['transaction_id'=>'txn:donation:1','account'=>'income.donation','direction'=>'credit','amount_minor'=>1000,'currency'=>'USD','source_ref'=>'donation1:income']);};
$tests['09 balanced settlement imports reconciles and posts']=static function()use($repo,$runtime,$audit,$now):void{$batch=new SettlementBatch('batch:2026-08-01','provider.sandbox',$now,'USD',1000,100,50,850,str_repeat('a',64),[['reference'=>'pay:1','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],['reference'=>'refund:1','type'=>'refund','amount_minor'=>100,'currency'=>'USD'],['reference'=>'fee:1','type'=>'fee','amount_minor'=>50,'currency'=>'USD'],['reference'=>'payout:1','type'=>'payout','amount_minor'=>850,'currency'=>'USD']]);$internal=[['reference'=>'pay:1','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],['reference'=>'refund:1','type'=>'refund','amount_minor'=>100,'currency'=>'USD'],['reference'=>'fee:1','type'=>'fee','amount_minor'=>50,'currency'=>'USD'],['reference'=>'payout:1','type'=>'payout','amount_minor'=>850,'currency'=>'USD']];$service=new SettlementOperationsService($repo,$runtime,$audit);$result=$service->importAndReconcile($batch,$internal,['USD'=>1],'user:finance1',$now->modify('+1 minute'));sameSC(0,$result['exception_count']);$posted=$service->postResolvedBatch($batch->batchId(),'user:finance2',$now->modify('+2 minutes'));sameSC('posted',$posted['status']);};
$tests['10 settlement posting creates balanced payout and provider fee entries']=static function()use($repo):void{$entries=$repo->find('ledger_entries',['transaction_id'=>'txn.settlement.'.substr(hash('sha256','provider.sandbox|batch:2026-08-01'),0,32)],20);$debit=0;$credit=0;foreach($entries as $entry){$entry['direction']==='debit'?$debit+=(int)$entry['amount_minor']:$credit+=(int)$entry['amount_minor'];}sameSC(900,$debit);sameSC($debit,$credit);};
$tests['11 provider fee becomes approved transparency expense']=static function()use($repo):void{$expenses=$repo->find('expenses',['category'=>DonationExpenseCategory::ADMINISTRATION_PAYMENT_CHARGES],10);sameSC(1,count($expenses));sameSC(50,(int)$expenses[0]['amount_minor']);};
$tests['12 finance period close enforces independent reviewer and approver']=static function()use($repo,$runtime,$audit,$now):void{$service=new SettlementOperationsService($repo,$runtime,$audit);throwsSC(static fn()=>$service->closePeriod('2026-08','user:same','user:same',$now),InvariantViolation::class);$closed=$service->closePeriod('2026-08','user:reviewer','user:approver',$now->modify('+3 minutes'));sameSC('locked',$closed['state']);};
$tests['13 finance period reopen requires dual control']=static function()use($repo,$runtime,$audit,$now):void{$service=new SettlementOperationsService($repo,$runtime,$audit);$result=$service->reopenPeriod('2026-08','user:requester','user:approver','reason:correction1');sameSC('exception_review',$result['state']);};

$tests['14 manual expense records approved category and private payee']=static function()use($repo,$audit,$now):void{$expense=new DonationExpense('expense:operations:1',$now,new Money(100,'USD'),DonationExpenseCategory::INSTITUTIONAL_OPERATIONS,'Hosting and essential operations','vendor:hosting','approval:founder:1','verified',false,'institutional_operations');$result=(new ExpenseTransparencyService($repo,$audit))->recordExpense($expense,'user:finance3',$now->modify('+4 minutes'));sameSC(100,$result['amount_minor']);};
$tests['15 transparency snapshot is derived not fabricated']=static function()use($repo,$audit,$now):void{$snapshot=(new ExpenseTransparencyService($repo,$audit))->buildAndPublishSnapshot('2026-08','USD','user:publisher',$now->modify('+5 minutes'));sameSC(1000,$snapshot['total_donations_minor']);sameSC(850,$snapshot['current_balance_minor']);sameSC(false,$snapshot['donor_identity_public']);sameSC(64,strlen($snapshot['source_hash']));};
$tests['16 immutable transparency period rejects changed republication']=static function()use($repo,$audit,$now):void{$repo->insert('expenses','expense:late',['expense_id'=>'expense:late','occurred_at'=>$now,'amount_minor'=>1,'currency'=>'USD','category'=>DonationExpenseCategory::INSTITUTIONAL_OPERATIONS,'purpose'=>'Late item','payee_ref'=>'vendor:late','approval_ref'=>'approval:late','receipt_status'=>'verified','founder_related'=>false,'public_disclosure_category'=>'institutional_operations','source_transaction_id'=>null,'record_version'=>1,'created_at'=>$now,'updated_at'=>$now]);throwsSC(static fn()=>(new ExpenseTransparencyService($repo,$audit))->buildAndPublishSnapshot('2026-08','USD','user:publisher',$now->modify('+6 minutes')),InvariantViolation::class);};
$tests['17 donor acknowledgment requires settled owner donation']=static function()use($repo,$audit,$now):void{$repo->insert('donations','donation:ack1',['donation_id'=>'donation:ack1','donor_ref'=>'user:donor1','amount_minor'=>1000,'currency'=>'USD','purpose_code'=>'institutional_sustainability_and_homeopathy_advancement','recurring'=>false,'recurring_consent_id'=>null,'provider_ref'=>'provider:masked','receipt_ref'=>'invoice:ack1','state'=>'settled','anonymous_public'=>true,'record_version'=>1,'created_at'=>$now,'updated_at'=>$now]);$result=(new ExpenseTransparencyService($repo,$audit))->consentToAcknowledgment('ack:1','donation:ack1','user:donor1','A Donor','I consent to public acknowledgment.',$now);sameSC('active',$result['state']);};
$tests['18 donor acknowledgment is revocable']=static function()use($repo,$audit,$now):void{$result=(new ExpenseTransparencyService($repo,$audit))->revokeAcknowledgment('ack:1','user:donor1',$now->modify('+1 day'));sameSC('revoked',$result['state']);sameSC('Anonymous donor',$repo->get('donor_acknowledgments','ack:1')['display_name']);};

$artifactStore=new CompletionArtifactStore();
$tests['19 secure export request preserves bounded specification']=static function()use($repo,$artifactStore,$runtime,$audit,$now):void{$service=new SecureExportService($repo,$artifactStore,$runtime,$audit);$result=$service->request('export:1','user:finance4',['transaction_id','source_type','amount_minor','currency','effective_at','period_id'],['currency'=>'USD','period_id'=>'2026-08'],100,$now->modify('+1 day'),$now);sameSC('queued',$result['state']);};
$tests['20 secure export processing stores encrypted opaque artifact']=static function()use($repo,$artifactStore,$runtime,$audit,$now):void{$service=new SecureExportService($repo,$artifactStore,$runtime,$audit);$result=$service->process('export:1','user:finance4',1,$now->modify('+1 minute'));sameSC('ready',$result['state']);sameSC(1,count($artifactStore->objects));sameSC(64,strlen((string)$result['manifest_hash']));};
$tests['21 secure export grant is audience and expiry bound']=static function()use($repo,$artifactStore,$runtime,$audit,$now):void{$grant=(new SecureExportService($repo,$artifactStore,$runtime,$audit))->grant('export:1','user:finance4',false,$now->modify('+2 minutes'),$now->modify('+12 minutes'));$grant->assertUsableBy('user:finance4',$now->modify('+3 minutes'));sameSC('finance_export',$grant->toPresentationContract()['asset_type']);};
$tests['22 secure export rejects another requester']=static function()use($repo,$artifactStore,$runtime,$audit,$now):void{throwsSC(static fn()=>(new SecureExportService($repo,$artifactStore,$runtime,$audit))->grant('export:1','user:other',false,$now,$now->modify('+10 minutes')),InvariantViolation::class);};
$tests['23 secure export revocation removes artifact reference']=static function()use($repo,$artifactStore,$runtime,$audit,$now):void{$result=(new SecureExportService($repo,$artifactStore,$runtime,$audit))->revoke('export:1','user:finance4',false,3,$now->modify('+4 minutes'));sameSC('revoked',$result['state']);sameSC(0,count($artifactStore->objects));};

$tests['24 fraud review uses bounded allowed signals']=static function()use($repo,$runtime,$now):void{$case=new FraudReviewCase('fraud:1','user:subject1',[['code'=>'velocity','weight'=>40,'evidence_ref'=>'evidence:velocity1']],$now,$now->modify('+2 days'));$result=(new RiskOperationsService($repo,$runtime))->openFraudReview($case,$now);sameSC(40,$result['risk_score']);};
$tests['25 fraud decline appeal and fresh review are versioned']=static function()use($repo,$runtime,$now):void{$service=new RiskOperationsService($repo,$runtime);$declined=$service->decideFraudReview('fraud:1',false,'user:reviewer1','Risk not cleared',$now->modify('+1 hour'),1);sameSC('declined',$declined['state']);$appealed=$service->appealFraudReview('fraud:1','user:subject1','New evidence available',$now->modify('+2 hours'),2);sameSC('appealed',$appealed['state']);throwsSC(static fn()=>$service->decideFraudReview('fraud:1',true,'user:reviewer1','Same reviewer',$now->modify('+3 hours'),3),InvariantViolation::class);$approved=$service->decideFraudReview('fraud:1',true,'user:reviewer2','Evidence verified',$now->modify('+3 hours'),3);sameSC('approved',$approved['state']);sameSC('closed',$service->closeFraudReview('fraud:1',$now->modify('+4 hours'),4)['state']);};
$tests['26 chargeback opens only against settled canonical intent']=static function()use($repo,$runtime,$now):void{$repo->insert('intents','intent:charge1',['intent_id'=>'intent:charge1','actor_ref'=>'user:donor1','product_id'=>'donation.one_time','price_version_id'=>null,'amount_minor'=>1000,'currency'=>'USD','provider'=>'provider.sandbox','provider_ref'=>'provider:payment1','state'=>'settled','failure_code'=>null,'idempotency_key'=>'idem:charge1','request_hash'=>str_repeat('b',64),'expires_at'=>$now->modify('+1 day'),'record_version'=>1,'trace_id'=>'trace:charge1','created_at'=>$now,'updated_at'=>$now]);$case=new ChargebackCase('chargeback:1','provider.sandbox','provider-case:1','intent:charge1',new Money(1000,'USD'),'fraudulent',$now,$now->modify('+30 days'));$result=(new RiskOperationsService($repo,$runtime))->openChargeback($case,$now);sameSC('notified',$result['state']);};
$tests['27 chargeback evidence outcome ledger and close are ordered']=static function()use($repo,$runtime,$now):void{$service=new RiskOperationsService($repo,$runtime);sameSC('evidence_due',$service->requireChargebackEvidence('chargeback:1',$now->modify('+1 day'),1)['state']);sameSC('submitted',$service->submitChargebackEvidence('chargeback:1',str_repeat('c',64),$now->modify('+2 days'),2)['state']);sameSC('accepted',$service->acceptChargebackEvidence('chargeback:1',$now->modify('+3 days'),3)['state']);sameSC('lost',$service->recordChargebackOutcome('chargeback:1',false,new Money(20,'USD'),$now->modify('+4 days'),4)['state']);sameSC('ledger_adjusted',$service->adjustChargebackLedger('chargeback:1','user:risk1',$now->modify('+4 days'),5)['state']);sameSC('closed',$service->closeChargeback('chargeback:1',$now->modify('+5 days'),6)['state']);};
$tests['28 chargeback ledger adjustment is balanced']=static function()use($repo):void{$transaction='txn.chargeback.'.substr(hash('sha256','chargeback:1'),0,32);$entries=$repo->find('ledger_entries',['transaction_id'=>$transaction],20);$debit=0;$credit=0;foreach($entries as $entry){$entry['direction']==='debit'?$debit+=(int)$entry['amount_minor']:$credit+=(int)$entry['amount_minor'];}sameSC(1020,$debit);sameSC($debit,$credit);};

$retentionExecutor=new CompletionRetentionExecutor();
$tests['29 retention immutable evidence cannot be deleted']=static function()use($repo,$retentionExecutor,$now):void{throwsSC(static fn()=>(new RetentionOperationsService($repo,$retentionExecutor))->schedule('ledger_entry','ledger:1','C3',$now,$now->modify('+1 day'),'delete'),InvariantViolation::class);};
$tests['30 retention legal hold blocks action']=static function()use($repo,$retentionExecutor,$now):void{$service=new RetentionOperationsService($repo,$retentionExecutor);$service->schedule('donor_preference','preference:1','C2',$now,$now->modify('+1 day'),'anonymize');$service->placeLegalHold('preference:1','hold:legal1');throwsSC(static fn()=>$service->executeDue('preference:1',$now->modify('+2 days')),InvariantViolation::class);};
$tests['31 retention action runs only after matching hold release']=static function()use($repo,$retentionExecutor,$now):void{$service=new RetentionOperationsService($repo,$retentionExecutor);$service->releaseLegalHold('preference:1','hold:legal1');$result=$service->executeDue('preference:1',$now->modify('+2 days'));sameSC('anonymize',$result['status']);sameSC(1,count($retentionExecutor->actions));};

$incidentStore=new CompletionIncidentStore();
$tests['32 incident declaration immediately disables selected financial paths']=static function()use($repo,$runtime,$audit,$incidentStore,$now):void{$state=(new IncidentOperationsService($incidentStore,$audit,$runtime))->declare('incident:1',1,'provider_compromise','user:commander',$now);sameSC('contained',$state['state']);sameSC(false,$state['checkout_enabled']);sameSC(false,$state['webhooks_enabled']);};
$tests['33 incident recovery requires independent approval and evidence']=static function()use($runtime,$audit,$incidentStore,$now):void{$service=new IncidentOperationsService($incidentStore,$audit,$runtime);throwsSC(static fn()=>$service->recover('incident:1','user:same','user:same','evidence:recovery1',$now,true,true,true),InvariantViolation::class);$state=$service->recover('incident:1','user:requester','user:approver','evidence:recovery1',$now->modify('+1 hour'),true,true,true);sameSC('recovered',$state['state']);sameSC(true,$state['webhooks_enabled']);};

$tests['34 system integrity verifies all ledger transaction currency pairs']=static function()use($repo,$audit):void{$health=(new SystemIntegrityService($repo,$audit))->health();sameSC(true,$health['ledger_balanced']);sameSC(true,$health['audit_chain_valid']);};
$tests['35 backup manifest includes all critical financial datasets']=static function()use($repo,$audit):void{$manifest=(new SystemIntegrityService($repo,$audit))->buildBackupManifest();sameSC(true,isset($manifest['ledger_entries'],$manifest['audit'],$manifest['transparency_snapshots']));sameSC(64,strlen($manifest['ledger_entries']['hash']));};
$tests['36 restore reconciliation accepts identical manifest and provider events']=static function()use($repo,$audit):void{$service=new SystemIntegrityService($repo,$audit);$manifest=$service->buildBackupManifest();$result=$service->verifyRestore($manifest,$manifest,['event:1'],['event:1']);sameSC(true,$result['accepted']);};
$tests['37 restore reconciliation rejects a missing dataset']=static function()use($repo,$audit):void{$service=new SystemIntegrityService($repo,$audit);$manifest=$service->buildBackupManifest();$bad=$manifest;unset($bad['ledger_entries']);throwsSC(static fn()=>$service->verifyRestore($manifest,$bad,[],[]),InvariantViolation::class);};

$failures=0;foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
function sameSC(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */function throwsSC(callable $callback,string $class):void{try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}throw new RuntimeException('Expected '.$class);}
