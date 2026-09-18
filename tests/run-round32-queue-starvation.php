<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressScheduler;

final class Round32Transport implements OutboxTransport
{
    public array $ids=[];
    public function publish(string $eventId,string $eventType,array $payload):void{$this->ids[]=$eventId;}
}
function round32Outbox(string $id,string $state,DateTimeImmutable $available,?DateTimeImmutable $lease=null):array{
    return [
        'event_id'=>$id,'event_type'=>'Round32','aggregate_id'=>'agg.round32',
        'aggregate_version'=>'1','schema_version'=>'1.1','trace_id'=>'trace.round32',
        'payload_json'=>['ok'=>true],'payload_hash'=>hash('sha256','round32'),
        'state'=>$state,'attempts'=>0,'available_at'=>$available,'leased_until'=>$lease,
        'last_error_code'=>null,'created_at'=>$available,'delivered_at'=>null,
    ];
}
$now=new DateTimeImmutable('2026-09-18T18:00:00+00:00');

$repo=new MemoryFinancialRepository();
for($i=0;$i<200;$i++){
    $id='event.future.'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
    $repo->insert('outbox',$id,round32Outbox($id,'pending',$now->modify('+1 hour')));
}
$due='event.due.after.first.page';
$repo->insert('outbox',$due,round32Outbox($due,'pending',$now->modify('-1 minute')));
$transport=new Round32Transport();
$result=(new OutboxDispatcher($repo,$transport))->dispatch($now,1);
if($transport->ids!==[$due]||($result['delivered']??0)!==1){
    throw new RuntimeException('Due outbox event beyond the first page must not starve behind future events.');
}

$repo2=new MemoryFinancialRepository();
for($i=0;$i<200;$i++){
    $id='event.lease.future.'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
    $repo2->insert('outbox',$id,round32Outbox($id,'processing',$now->modify('-1 hour'),$now->modify('+1 hour')));
}
$expired='event.lease.expired.after.first.page';
$repo2->insert('outbox',$expired,round32Outbox($expired,'processing',$now->modify('-1 hour'),$now->modify('-1 minute')));
$transport2=new Round32Transport();
$result2=(new OutboxDispatcher($repo2,$transport2))->dispatch($now,1);
if($transport2->ids!==[$expired]||($result2['recovered_leases']??0)!==1){
    throw new RuntimeException('Expired processing lease beyond the first page must be recoverable.');
}

$repo3=new MemoryFinancialRepository();
for($i=0;$i<501;$i++){
    $key='idem.'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
    $repo3->insert('idempotency',$key,[
        'idempotency_key'=>$key,'actor_ref'=>'user:32','scope'=>'donation',
        'request_hash'=>str_repeat('a',64),'response_json'=>null,'state'=>'pending',
        'expires_at'=>$i===500?$now->modify('-1 minute'):$now->modify('+1 hour'),
        'created_at'=>$now->modify('-1 day'),'completed_at'=>null,
    ]);
}
$m=new ReflectionMethod(WordPressScheduler::class,'expirePendingIdempotency');
$m->setAccessible(true);
$count=$m->invoke(null,$repo3,$now);
if($count!==1||($repo3->get('idempotency','idem.0500')['state']??null)!=='failed'){
    throw new RuntimeException('Expired idempotency evidence beyond 500 rows must be completed fail-closed.');
}

fwrite(STDOUT,"PASS: ready outbox work and expired idempotency evidence cannot starve behind earlier future rows\n");
