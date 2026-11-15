<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$now = new DateTimeImmutable('2026-10-21T10:00:00+05:00');
$assertions = 0;
$expect = static function(bool $condition,string $description) use (&$assertions): void {
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: '.$description); }
};
$transport = new class implements OutboxTransport {
    public array $events = [];
    public function publish(string $eventId,string $eventType,array $payload): void {
        $this->events[] = $eventId;
    }
};
$add = static function(MemoryFinancialRepository $repo,string $id,string $state,DateTimeImmutable $at,
    ?DateTimeImmutable $lease = null): void {
    $repo->insert('outbox',$id,[
        'event_id'=>$id,'event_type'=>'DonationSettled.v1','payload_json'=>['one_time'=>true],
        'state'=>$state,'attempts'=>0,'available_at'=>$at,
        'leased_until'=>$lease,
    ]);
};

// Regression 1: a future-dated pending backlog must not hide due work.
$repo = new MemoryFinancialRepository();
for($i=0;$i<80;$i++){
    $add($repo,'evt:future:'.sprintf('%03d',$i),'pending',$now->modify('+1 day'));
}
$add($repo,'evt:due:pending','pending',$now->modify('-2 hours'));
$add($repo,'evt:due:retry','retry',$now->modify('-3 hours'));
$run = (new OutboxDispatcher($repo,$transport))->dispatch($now,2);
$expect($run['delivered']===2,'Future-dated head-of-line candidates cannot starve due work');
$expect($transport->events===['evt:due:retry','evt:due:pending'],'Oldest eligible retry is served before newer pending');
$expect(count($repo->dueOutbox('pending',$now,100))===0,'No future pending is erroneously considered due');

// Regression 2: more than fifty live leases cannot hide expired leases.
$repo2 = new MemoryFinancialRepository();
for($i=0;$i<80;$i++){
    $add($repo2,'evt:live:'.sprintf('%03d',$i),'processing',$now,$now->modify('+5 minutes'));
}
$add($repo2,'evt:expired:tail','processing',$now,$now->modify('-30 seconds'));
$transport2 = new class implements OutboxTransport {
    public array $events = [];
    public function publish(string $eventId,string $eventType,array $payload): void { $this->events[]=$eventId; }
};
$run2 = (new OutboxDispatcher($repo2,$transport2))->dispatch($now,1);
$expect($run2['delivered']===1,'Expired lease beyond fifty active leases is recovered and published');
$expect($run2['retried']===1,'Recovered expired lease is recorded as retry');
$expect($transport2->events===['evt:expired:tail'],'Only expired event is published');
$expect(($repo2->get('outbox','evt:live:000')['state']??null)==='processing','Valid worker lease remains in processing');
$expect(($repo2->get('outbox','evt:expired:tail')['state']??null)==='delivered','Recovered lease is acknowledged');

// Regression 3: bounded due scanning and invalid parameters.
$expect($repo2->dueOutbox('processing',$now,200)===[],'No active processing lease is due');
$bad = false;
try {$repo2->dueOutbox('unknown',$now,1);}catch(InvalidArgumentException){$bad=true;}
$expect($bad,'Only recognized queue states are accepted');
fwrite(STDOUT,"CF-03 outbox due/fairness regression: {$assertions}/{$assertions} checks passed.\n");
