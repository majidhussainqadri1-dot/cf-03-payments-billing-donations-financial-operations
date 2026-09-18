<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RestoreReconciliation;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$repo=new MemoryFinancialRepository();
$repo->insert('provider_events','event.restore.1',[
    'provider'=>'provider.test','provider_event_id'=>'event.restore.1','event_type'=>'payment.settled',
    'raw_body_hash'=>str_repeat('a',64),'signature_key_version'=>'key.v1',
    'signature_timestamp'=>new DateTimeImmutable(),'received_at'=>new DateTimeImmutable(),
    'mapped_state'=>'settled','status'=>'processed','intent_id'=>'intent.restore.1',
    'trace_id'=>'trace.restore.1','processed_at'=>new DateTimeImmutable(),
]);
$config=[
    'sabri_cf03_provider_id'=>'provider.test',
    'sabri_cf03_runtime_mode'=>'live',
    'sabri_cf03_webhook_enabled'=>true,
];
$service=new SystemIntegrityService($repo,new FinancialAuditService($repo),$config);
$manifest=$service->buildBackupManifest();
if(!isset($manifest['runtime_configuration'])
    || $manifest['runtime_configuration']['count']!==3
    || $manifest['provider_events']['count']!==1
){
    throw new RuntimeException('Backup manifest must include canonical runtime configuration and provider-event evidence.');
}

$failed=false;
try {
    (new RestoreReconciliation())->verify($manifest,$manifest,[],[]);
} catch(Throwable){$failed=true;}
if(!$failed){
    throw new RuntimeException('Restore must not accept an empty provider-authoritative comparison when provider events were backed up.');
}

$result=(new RestoreReconciliation())->verify($manifest,$manifest,['event.restore.1'],['event.restore.1']);
if(($result['balanced']??false)!==true){
    throw new RuntimeException('Matching provider-authoritative restore evidence should reconcile.');
}

$source=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinanceAdminApi.php');
if(!str_contains($source,'WordPressRuntimeConfiguration::backupSnapshot()')){
    throw new RuntimeException('Operational integrity backup manifest must include WordPress runtime configuration.');
}

fwrite(STDOUT,"PASS: backup/restore binds runtime configuration and authoritative provider event evidence\n");
