<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class Round7Transport implements OutboxTransport
{
    /** @var list<string> */ public array $events=[];
    public function publish(string $eventId,string $eventType,array $payload):void{$this->events[]=$eventId;}
}

$tests=[];

$tests['refund balance reservation fences the canonical intent version']=static function():void{
    $repo=new MemoryFinancialRepository();
    $repo->insert('intents','intent:round7:1',[
        'intent_id'=>'intent:round7:1','actor_ref'=>'user:700','product_id'=>'donation.one_time',
        'amount_minor'=>1000,'currency'=>'USD','provider'=>'provider.test','provider_ref'=>'payment:round7:1',
        'state'=>'settled','record_version'=>1,'created_at'=>new DateTimeImmutable('2026-09-16T00:00:00Z'),
        'updated_at'=>new DateTimeImmutable('2026-09-16T00:00:00Z'),
    ]);
    $service=new RefundWorkflowService($repo,new ProviderRegistry([]),RuntimeConfiguration::preparing());
    $first=$service->request('refund:round7:1','intent:round7:1','user:700',new Money(700,'USD'),'duplicate_payment',new DateTimeImmutable('2026-09-16T01:00:00Z'));
    same7(false,$first['reused']);
    same7(2,$repo->get('intents','intent:round7:1')['version']);
    expectInvariant7(static fn()=>$service->request('refund:round7:2','intent:round7:1','user:700',new Money(400,'USD'),'duplicate_payment',new DateTimeImmutable('2026-09-16T01:01:00Z')));
    same7(2,$repo->get('intents','intent:round7:1')['version']);
    same7(null,$repo->get('refunds','refund:round7:2'));
    $replay=$service->request('refund:round7:1','intent:round7:1','user:700',new Money(700,'USD'),'duplicate_payment',new DateTimeImmutable('2026-09-16T01:02:00Z'));
    same7(true,$replay['reused']);
    same7(2,$repo->get('intents','intent:round7:1')['version']);
};

$tests['expired processing outbox lease is recovered and delivered']=static function():void{
    $repo=new MemoryFinancialRepository();
    $now=new DateTimeImmutable('2026-09-16T02:00:00Z');
    $repo->insert('outbox','event:round7:expired',[
        'event_id'=>'event:round7:expired','event_type'=>'Round7Fact','aggregate_id'=>'aggregate:1','aggregate_version'=>'1',
        'schema_version'=>'1.0','trace_id'=>'trace:round7','payload_json'=>['ok'=>true],
        'payload_hash'=>hash('sha256','round7'),'state'=>'processing','attempts'=>0,'available_at'=>$now->modify('-10 minutes'),
        'leased_until'=>$now->modify('-1 minute'),'last_error_code'=>null,'created_at'=>$now->modify('-20 minutes'),'delivered_at'=>null,
    ]);
    $repo->insert('outbox','event:round7:active',[
        'event_id'=>'event:round7:active','event_type'=>'Round7Fact','aggregate_id'=>'aggregate:2','aggregate_version'=>'1',
        'schema_version'=>'1.0','trace_id'=>'trace:round7:2','payload_json'=>['ok'=>true],
        'payload_hash'=>hash('sha256','round7-active'),'state'=>'processing','attempts'=>0,'available_at'=>$now->modify('-10 minutes'),
        'leased_until'=>$now->modify('+1 minute'),'last_error_code'=>null,'created_at'=>$now->modify('-20 minutes'),'delivered_at'=>null,
    ]);
    $transport=new Round7Transport();
    $result=(new OutboxDispatcher($repo,$transport))->dispatch($now,10);
    same7(1,$result['recovered_leases']);
    same7(1,$result['delivered']);
    same7(['event:round7:expired'],$transport->events);
    same7('delivered',$repo->get('outbox','event:round7:expired')['state']);
    same7('processing',$repo->get('outbox','event:round7:active')['state']);
};

$failures=0;
foreach($tests as$name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable$error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}
fwrite(STDOUT,count($tests)." tests, {$failures} failures\n");
exit($failures===0?0:1);

function expectInvariant7(callable$operation):void{try{$operation();}catch(InvariantViolation){return;}throw new RuntimeException('Expected InvariantViolation.');}
function same7(mixed$expected,mixed$actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
