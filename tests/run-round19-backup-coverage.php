<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\CompleteSchema;

$repo=new MemoryFinancialRepository();
$service=new SystemIntegrityService($repo,new FinancialAuditService($repo));
$manifest=$service->buildBackupManifest();
$expected=array_keys(CompleteSchema::tables(''));
sort($expected,SORT_STRING);
$actual=array_keys($manifest);
sort($actual,SORT_STRING);

if($actual!==$expected){
    throw new RuntimeException('Backup manifest must cover every active canonical CF-03 collection.');
}
foreach(['idempotency','provider_registry','adjustments','fraud_reviews','customer_refs','products','prices','donor_acknowledgments'] as $required){
    if(!array_key_exists($required,$manifest)){
        throw new RuntimeException('Restore-critical collection omitted from backup manifest: '.$required);
    }
}
if(count($manifest)!==27){
    throw new RuntimeException('Current schema backup manifest must cover all 27 active canonical tables.');
}

fwrite(STDOUT,"PASS: backup/restore manifest covers every active canonical financial collection\n");
