<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$now=new DateTimeImmutable('2026-10-21T13:00:00+00:00');
$assertions=0;
$assert=static function(bool $state,string $why)use(&$assertions):void {
    $assertions++;if(!$state){throw new RuntimeException('FAIL: '.$why);}
};
$reject=static function(callable $fn,string $why)use($assert):void {
    $blocked=false;try{$fn();}catch(InvariantViolation){$blocked=true;}
    $assert($blocked,$why);
};
for($round=1;$round<=40;$round++){
    // Distinct per-round actors, amounts, idempotency tokens, time windows.
    $repo=new MemoryFinancialRepository();
    $actor='user:'.(10000+$round);
    $intent='intent:review:'.sprintf('%02d',$round);
    $paid=300+$round*37;
    $reserve=(int)floor($paid*($round%5+1)/7);
    $remaining=$paid-$reserve;
    $reason='duplicate_payment';
    $repo->insert('intents',$intent,[
        'intent_id'=>$intent,'actor_ref'=>$actor,
        'state'=>($round%2===0?'settled':'captured'),
        'amount_minor'=>$paid,'currency'=>'USD','record_version'=>1,
    ]);
    $service=new RefundWorkflowService($repo,new ProviderRegistry(),RuntimeConfiguration::preparing());
    $id='refund:review:'.sprintf('%02d',$round).':one';
    $one=$service->request($id,$intent,$actor,new Money($reserve,'USD'),$reason,$now);
    $assert($one['reused']===false, "round {$round}: independent initial refund");
    $dupe=$service->request($id,$intent,$actor,new Money($reserve,'USD'),$reason,$now);
    $assert($dupe['reused']===true, "round {$round}: replay does not double reserve");
    $reject(static fn()=>$service->request($id,$intent,$actor,new Money(1,'USD'),$reason,$now),
        "round {$round}: changed replay terms denied");
    $reject(static fn()=>$service->request("refund:review:".sprintf('%02d',$round).":foreign",$intent,
        'user:foreign',new Money(1,'USD'),$reason,$now),
        "round {$round}: user scope protected");
    $reject(static fn()=>$service->request("refund:review:".sprintf('%02d',$round).":excess",$intent,$actor,
        new Money($remaining+1,'USD'),$reason,$now),
        "round {$round}: excess refund prevented");
    $last=$service->request("refund:review:".sprintf('%02d',$round).":rest",$intent,$actor,
        new Money($remaining,'USD'),$reason,$now);
    $assert($last['reused']===false,"round {$round}: remaining balance can be reserved exactly");
    $reject(static fn()=>$service->request("refund:review:".sprintf('%02d',$round).":extra",$intent,$actor,
        new Money(1,'USD'),$reason,$now),
        "round {$round}: fully reserved balance cannot be exceeded");

    // Separate WordPress-compatible due queue semantics checked against variable deadlines.
    $transport=new class implements OutboxTransport {
        public array $ids=[];
        public function publish(string $eventId,string $eventType,array $payload):void{$this->ids[]=$eventId;}
    };
    $due='evt:review:'.sprintf('%02d',$round).':retry';
    $future='evt:review:'.sprintf('%02d',$round).':future';
    $repo->insert('outbox',$future,[
        'event_id'=>$future,'event_type'=>'DonationSettled.v1',
        'payload_json'=>['source_round'=>$round],
        'state'=>'pending','attempts'=>0,'available_at'=>$now->modify('+'.($round+1).' hours'),
        'leased_until'=>null,
    ]);
    $repo->insert('outbox',$due,[
        'event_id'=>$due,'event_type'=>'DonationSettled.v1',
        'payload_json'=>['source_round'=>$round],
        'state'=>'retry','attempts'=>0,'available_at'=>$now->modify('-'.($round+1).' minutes'),
        'leased_until'=>null,
    ]);
    $run=(new OutboxDispatcher($repo,$transport))->dispatch($now,1);
    $assert($run['delivered']===1 && $transport->ids===[$due],
        "round {$round}: overdue retry dispatched, future transaction untouched");
    $assert(($repo->get('outbox',$future)['state']??'')==='pending',
        "round {$round}: future pending remains pending");
}
fwrite(STDOUT,"CF-03 forty independent seeded finance review scenarios: 40/40 scenarios, {$assertions} assertions passed. These are unit/repository fixtures, not Live payment tests.\n");
