<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class Round6ArtifactStore implements SecureArtifactStore
{
    /** @var array<string,string> */ public array $objects = [];
    public bool $failNextDelete = false;
    public function put(string $filename,string $mediaType,string $contents,DateTimeImmutable $expiresAt):array
    {
        $ref='vault://finance/'.$filename;
        $this->objects[$ref]=$contents;
        return ['object_ref'=>$ref,'sha256'=>hash('sha256',$contents),'size_bytes'=>strlen($contents)];
    }
    public function delete(string $objectReference):void
    {
        if ($this->failNextDelete) {
            $this->failNextDelete=false;
            throw new InvariantViolation('simulated artifact deletion outage');
        }
        unset($this->objects[$objectReference]);
    }
}

$gates=[
    'founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,
    'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,
    'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,
    'file24_assurance'=>true,'operations_ready'=>true,'secure_delivery'=>true,
];
$runtime=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,false,true);
$repo=new MemoryFinancialRepository();
$store=new Round6ArtifactStore();
$audit=new FinancialAuditService($repo);
$service=new SecureExportService($repo,$store,$runtime,$audit);
$now=new DateTimeImmutable('2026-09-16T10:00:00+05:00');
$fields=['transaction_id','source_type','amount_minor','currency','effective_at','period_id'];
$tests=[];

$tests['privacy exporter uses billing receipt group and erasure is bounded-page complete']=static function():void{
    $source=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressPrivacy.php');
    if(!is_string($source)||!str_contains($source,"['receipts','donations','refunds','exports']")){
        throw new RuntimeException('Privacy exporter does not consume the canonical receipt group.');
    }
    foreach([
        'ERASURE_ACKNOWLEDGMENT_BATCH = 100',
        "['donor_ref' => \$actor, 'state' => 'active']",
        'count($acknowledgments) < self::ERASURE_ACKNOWLEDGMENT_BATCH',
    ] as $needle){
        if(!str_contains($source,$needle)){throw new RuntimeException('Privacy erasure pagination control missing: '.$needle);}
    }
    if(str_contains($source,"find('donor_acknowledgments', ['donor_ref' => \$actor], 500")){
        throw new RuntimeException('Privacy erasure still uses a truncating fixed acknowledgment query.');
    }
};

$tests['secure export lifecycle emits immutable audit evidence and uses operator actor']=static function()use($service,$repo,$now,$fields):void{
    $requested=$service->request('export:round6:1','user:10',$fields,[],10,$now->modify('+1 day'),$now);
    same6(false,$requested['reused']);
    $ready=$service->process('export:round6:1','user:20',1,$now->modify('+1 minute'));
    same6('ready',$ready['state']);
    $grant=$service->grant('export:round6:1','user:30',true,$now->modify('+2 minutes'),$now->modify('+12 minutes'));
    $grant->assertUsableBy('user:30',$now->modify('+3 minutes'));
    $events=$repo->all('audit');
    $actions=array_column($events,'action');
    foreach(['finance_export_requested','finance_export_processed','finance_export_granted'] as $action){
        if(!in_array($action,$actions,true)){throw new RuntimeException('Missing export audit action: '.$action);}
    }
    $processed=array_values(array_filter($events,static fn(array $e):bool=>($e['action']??null)==='finance_export_processed'));
    same6('user:20',$processed[0]['actor_ref']??null);
};

$tests['same export request safely replays without duplicate audit failure']=static function()use($service,$now,$fields):void{
    $again=$service->request('export:round6:1','user:10',$fields,[],10,$now->modify('+1 day'),$now);
    same6(true,$again['reused']);
};

$tests['revocation becomes canonical before external deletion and cleanup is retryable']=static function()use($service,$repo,$store,$now,$fields):void{
    $service->request('export:round6:2','user:10',$fields,[],10,$now->modify('+1 day'),$now->modify('+5 minutes'));
    $service->process('export:round6:2','user:20',1,$now->modify('+6 minutes'));
    $store->failNextDelete=true;
    try{$service->revoke('export:round6:2','user:10',false,3,$now->modify('+7 minutes'));}
    catch(InvariantViolation){}
    $afterFailure=$repo->get('exports','export:round6:2');
    same6('revoked',$afterFailure['state']??null);
    if(!is_string($afterFailure['encrypted_object_ref']??null)||$afterFailure['encrypted_object_ref']===''){
        throw new RuntimeException('Failed external cleanup lost its retryable object reference.');
    }
    $clean=$service->revoke('export:round6:2','user:10',false,4,$now->modify('+8 minutes'));
    same6('revoked',$clean['state']);
    $latest=$repo->get('exports','export:round6:2');
    same6(null,$latest['encrypted_object_ref']??null);
};

$failures=0;
foreach($tests as$name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable$error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}
fwrite(STDOUT,count($tests)." tests, {$failures} failures\n");
exit($failures===0?0:1);

function same6(mixed$expected,mixed$actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
