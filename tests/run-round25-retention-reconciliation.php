<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\NullRetentionActionExecutor;

foreach(['uncertain','executing'] as $state){
    $repo=new MemoryFinancialRepository();
    $repo->insert('retention_ledger','record.'.$state,[
        'record_type'=>'temporary_export',
        'record_ref'=>'record.'.$state,
        'data_class'=>'F1',
        'created_at'=>new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        'expires_at'=>new DateTimeImmutable('2026-09-02T00:00:00+00:00'),
        'delete_mode'=>'delete',
        'legal_hold'=>false,
        'legal_hold_ref'=>null,
        'action_state'=>$state,
        'action_evidence_ref'=>null,
        'actioned_at'=>null,
    ]);
    $result=(new RetentionOperationsService($repo,new NullRetentionActionExecutor()))
        ->reconcileUncertain('record.'.$state,'evidence.round25.'.$state,new DateTimeImmutable('2026-09-18T10:00:00+00:00'));
    if(($result['status']??null)!=='reconciled_completed'
        || ($repo->get('retention_ledger','record.'.$state)['action_state']??null)!=='completed'
    ){
        throw new RuntimeException('Verified reconciliation must recover '.$state.' retention claims.');
    }
}

$api=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinanceAdminApi.php');
if(!str_contains($api,"/reconcile','POST','reconcileRetention','retention','retention_reconcile'")
    || !str_contains($api,'function reconcileRetention(')
    || !str_contains($api,'->reconcileUncertain(')
){
    throw new RuntimeException('Privileged retention reconciliation must be operationally reachable and audited.');
}
$ret=(string)file_get_contents(dirname(__DIR__).'/src/Application/RetentionOperationsService.php');
if(!str_contains($ret,"['uncertain','executing']")){
    throw new RuntimeException('Externally completed executing claims must remain explicitly reconcilable.');
}

fwrite(STDOUT,"PASS: retention uncertain/executing external effects have a verified audited reconciliation path\n");
