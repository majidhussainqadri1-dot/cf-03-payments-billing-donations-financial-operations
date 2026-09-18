<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\PaymentExposureService;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FinancialReceiptIdentity;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

function round33Runtime():RuntimeConfiguration{
    return new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.test',[
        'founder_change_control'=>true,'legal_tax_accounting'=>true,'receipt_identity'=>true,
        'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,
        'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,
        'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,
        'webhook_endpoint'=>true,
    ],true,false,new FinancialReceiptIdentity('Sabri Social Homeopathy Platform','PK'));
}
function round33Intent(MemoryFinancialRepository $repo,string $id,int $amount,DateTimeImmutable $now):void{
    $repo->insert('intents',$id,[
        'intent_id'=>$id,'actor_ref'=>'user:33','product_id'=>'donation.one_time',
        'price_version_id'=>null,'amount_minor'=>$amount,'currency'=>'USD','provider'=>'provider.test',
        'provider_ref'=>'provider.payment.33','state'=>'settled','failure_code'=>null,
        'idempotency_key'=>'idem.'.substr(hash('sha256',$id),0,40),'request_hash'=>str_repeat('a',64),
        'expires_at'=>$now->modify('+1 hour'),'record_version'=>1,'trace_id'=>'trace.round33',
        'created_at'=>$now->modify('-1 hour'),'updated_at'=>$now,
    ]);
}
function round33Refund(MemoryFinancialRepository $repo,string $id,string $intent,int $amount,DateTimeImmutable $now):void{
    $repo->insert('refunds',$id,[
        'refund_id'=>$id,'intent_id'=>$intent,'amount_minor'=>$amount,'currency'=>'USD',
        'refundable_balance_minor'=>0,'requester_ref'=>'user:33','reviewer_ref'=>'user:r',
        'executor_ref'=>'user:e','reason'=>'test_refund','decision_reason'=>'done',
        'policy_version'=>'refund.current.v1','state'=>'closed','provider_ref'=>'provider.ref.33',
        'record_version'=>1,'requested_at'=>$now,'updated_at'=>$now,
    ]);
}
$now=new DateTimeImmutable('2026-09-18T19:00:00+00:00');

$repo=new MemoryFinancialRepository();
round33Intent($repo,'intent.cross.refund',100,$now);
round33Refund($repo,'refund.cross.60','intent.cross.refund',60,$now);
$risk=new RiskOperationsService($repo,round33Runtime());
$failed=false;
try{
    $risk->openChargeback(new ChargebackCase(
        'case.cross.50','provider.test','provider.case.cross.50','intent.cross.refund',
        new Money(50,'USD'),'fraudulent',$now,$now->modify('+30 days')
    ),$now);
}catch(Throwable){$failed=true;}
if(!$failed){throw new RuntimeException('Chargeback must include already-committed refund exposure.');}

$repo2=new MemoryFinancialRepository();
round33Intent($repo2,'intent.cross.chargeback',100,$now);
$risk2=new RiskOperationsService($repo2,round33Runtime());
$risk2->openChargeback(new ChargebackCase(
    'case.cross.60','provider.test','provider.case.cross.60','intent.cross.chargeback',
    new Money(60,'USD'),'fraudulent',$now,$now->modify('+30 days')
),$now);
$refunds=new RefundWorkflowService($repo2,new ProviderRegistry(),round33Runtime());
$failed=false;
try{
    $refunds->request('refund.cross.50','intent.cross.chargeback','user:33',new Money(50,'USD'),'duplicate_charge',$now);
}catch(Throwable){$failed=true;}
if(!$failed){throw new RuntimeException('Refund must include already-committed chargeback exposure.');}

$exposure=new PaymentExposureService($repo2);
if($exposure->combinedExposure('intent.cross.chargeback','USD')!==60){
    throw new RuntimeException('Shared payment exposure calculator returned the wrong reservation total.');
}
$riskSource=(string)file_get_contents(dirname(__DIR__).'/src/Application/RiskOperationsService.php');
if(!str_contains($riskSource,"compareAndSwap(\n                'intents'")
    || !str_contains($riskSource,'->combinedExposure(')
){
    throw new RuntimeException('Chargeback reservation must be fenced on the canonical intent and shared exposure.');
}
$webhook=(string)file_get_contents(dirname(__DIR__).'/src/Application/WebhookIngestionService.php');
if(!str_contains($webhook,'->chargebackExposure(')){
    throw new RuntimeException('Provider-initiated refunds must also include chargeback exposure.');
}

fwrite(STDOUT,"PASS: refunds and chargebacks share one concurrency-fenced canonical payment exposure ceiling\n");
