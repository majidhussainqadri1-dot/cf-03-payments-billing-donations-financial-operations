<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

final class Round34Store implements SecureArtifactStore
{
    public array $deleted=[];
    public bool $failFirst=false;
    private int $attempts=0;
    public function put(string $filename,string $mediaType,string $contents,DateTimeImmutable $expiresAt):array{
        throw new LogicException('not used');
    }
    public function delete(string $objectReference):void{
        $this->attempts++;
        if($this->failFirst&&$this->attempts===1){throw new RuntimeException('simulated delete outage');}
        $this->deleted[]=$objectReference;
    }
}
function round34Export(MemoryFinancialRepository $repo,string $id,DateTimeImmutable $expires,string $object):void{
    $repo->insert('exports',$id,[
        'job_id'=>$id,'requester_ref'=>'user:34','specification_hash'=>str_repeat('a',64),
        'specification_json'=>['job_id'=>$id,'fields'=>['transaction_id'],'filters'=>[],'maximum_rows'=>10,'expires_at'=>$expires->format(DATE_ATOM),'state'=>'ready','record_version'=>1],
        'maximum_rows'=>10,'state'=>'ready','encrypted_object_ref'=>$object,'manifest_hash'=>str_repeat('b',64),
        'expires_at'=>$expires,'record_version'=>1,'created_at'=>$expires->modify('-1 day'),'updated_at'=>$expires->modify('-1 hour'),
    ]);
}
$now=new DateTimeImmutable('2026-09-18T20:00:00+00:00');
$repo=new MemoryFinancialRepository();$store=new Round34Store();
round34Export($repo,'export.round34.ok',$now->modify('-1 minute'),'vault://finance/round34-ok');
$service=new SecureExportService($repo,$store,RuntimeConfiguration::preparing(),new FinancialAuditService($repo));
$result=$service->expire('export.round34.ok',1,$now);
$final=$repo->get('exports','export.round34.ok');
if(($result['state']??null)!=='expired'||(!array_key_exists('encrypted_object_ref',$final) || $final['encrypted_object_ref']!==null)||$store->deleted!==['vault://finance/round34-ok']){
    throw new RuntimeException('Expired finance export must delete the encrypted artifact and clear its durable reference.');
}
if(count($repo->all('audit'))!==1){throw new RuntimeException('Export expiry must create one immutable audit event.');}

$repo2=new MemoryFinancialRepository();$store2=new Round34Store();$store2->failFirst=true;
round34Export($repo2,'export.round34.retry',$now->modify('-1 minute'),'vault://finance/round34-retry');
$service2=new SecureExportService($repo2,$store2,RuntimeConfiguration::preparing(),new FinancialAuditService($repo2));
$failed=false;
try{$service2->expire('export.round34.retry',1,$now);}catch(Throwable){$failed=true;}
if(!$failed){throw new RuntimeException('Simulated storage outage must remain visible.');}
$after=$repo2->get('exports','export.round34.retry');
if(($after['state']??null)!=='expired'||($after['encrypted_object_ref']??null)!=='vault://finance/round34-retry'){
    throw new RuntimeException('Expiry must fail closed while retaining artifact reference for cleanup retry.');
}
$service2->expire('export.round34.retry',(int)$after['version'],$now->modify('+1 minute'));
if((!array_key_exists('encrypted_object_ref',$repo2->get('exports','export.round34.retry')) || $repo2->get('exports','export.round34.retry')['encrypted_object_ref']!==null)||count($repo2->all('audit'))!==1){
    throw new RuntimeException('Expiry cleanup retry must clear artifact without duplicating expiry audit evidence.');
}

fwrite(STDOUT,"PASS: temporary finance exports expire with retryable artifact deletion and immutable audit evidence\n");
