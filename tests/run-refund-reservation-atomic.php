<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$now = new DateTimeImmutable('2026-10-21T12:00:00+05:00');
$checks = 0;
$expect = static function (bool $yes, string $msg) use (&$checks): void {
    $checks++;
    if (!$yes) { throw new RuntimeException("FAIL {$msg}"); }
};
$denies = static function (callable $action, string $msg) use ($expect): void {
    $failed = false;
    try { $action(); } catch (InvariantViolation) { $failed = true; }
    $expect($failed, $msg);
};
$repo = new MemoryFinancialRepository();
$service = new RefundWorkflowService($repo, new ProviderRegistry(), RuntimeConfiguration::preparing());
$denies(static fn() => $repo->getForUpdate('intents', 'intent:missing'), 'Row lock only legal inside a transaction');
$repo->insert('intents', 'intent:atomic:1', [
    'intent_id' => 'intent:atomic:1','actor_ref'=>'user:1001',
    'state'=>'settled','currency'=>'USD','amount_minor'=>100,
    'record_version'=>1,
]);
$locked = $repo->transaction(static fn()=>$repo->getForUpdate('intents', 'intent:atomic:1'));
$expect(is_array($locked) && ($locked['actor_ref']??null)==='user:1001','Owner intent locked in transaction');
$first = $service->request('refund:atomic:one','intent:atomic:1','user:1001',new Money(60,'USD'),'duplicate_payment',$now);
$expect($first['reused']===false,'First refund reserves 60 minor units');
$expect(($repo->get('refunds','refund:atomic:one')['amount_minor']??0)===60,'First reservation persisted');
$denies(static fn()=>$service->request('refund:atomic:two','intent:atomic:1','user:1001',new Money(50,'USD'),'duplicate_payment',$now),
    'Remaining 40 cannot be over-reserved');
$expect($repo->get('refunds','refund:atomic:two')===null,'Overdraft rejected without a phantom refund');
$reused=$service->request('refund:atomic:one','intent:atomic:1','user:1001',new Money(60,'USD'),'duplicate_payment',$now);
$expect($reused['reused']===true,'Identical idempotent refund reuses existing record');
$denies(static fn()=>$service->request('refund:atomic:one','intent:atomic:1','user:1001',new Money(1,'USD'),'duplicate_payment',$now),
    'Existing ID with changed amount denied');
$denies(static fn()=>$service->request('refund:atomic:other','intent:atomic:1','user:evil',new Money(1,'USD'),'duplicate_payment',$now),
    'Nonowner denied even with correct intent ID');
$second=$service->request('refund:atomic:rest','intent:atomic:1','user:1001',new Money(40,'USD'),'duplicate_payment',$now);
$expect($second['reused']===false,'Exact remaining balance permitted');
$denies(static fn()=>$service->request('refund:atomic:excess','intent:atomic:1','user:1001',new Money(1,'USD'),'duplicate_payment',$now),
    'Fully reserved balance denies further request');

$cap = new MemoryFinancialRepository();
$cap->insert('intents','intent:cap:1',[
    'intent_id'=>'intent:cap:1','actor_ref'=>'user:2002',
    'state'=>'captured','currency'=>'USD','amount_minor'=>10000,'record_version'=>1,
]);
for($i=0;$i<500;$i++){
    $id='refund:cap:'.sprintf('%04d',$i);
    $cap->insert('refunds',$id,[
        'refund_id'=>$id,'intent_id'=>'intent:cap:1','requester_ref'=>'user:2002',
        'state'=>'rejected','currency'=>'USD','amount_minor'=>1,
    ]);
}
$capService = new RefundWorkflowService($cap,new ProviderRegistry(),RuntimeConfiguration::preparing());
$denies(static fn()=>$capService->request('refund:cap:new','intent:cap:1','user:2002',
    new Money(1,'USD'),'duplicate_payment',$now),
    'Exactly 500 records stop truncated scan even if all are rejected');
$expect($cap->get('refunds','refund:cap:new')===null,'Safety cap fails closed without an insert');
$expect(count($cap->find('refunds',['intent_id'=>'intent:cap:1'],500))===500,'Original evidence retained intact');

$wp = file_get_contents(__DIR__.'/../src/Infrastructure/WordPressFinancialRepository.php');
$serviceCode = file_get_contents(__DIR__.'/../src/Application/RefundWorkflowService.php');
$expect(str_contains($wp,'LIMIT 2 FOR UPDATE'),'InnoDB adapter obtains locking read');
$expect(strpos($serviceCode,"getForUpdate('intents',")<strpos($serviceCode,"get('refunds',"),
    'Row lock precedes refund balance and replay reads');
$expect(str_contains($serviceCode,"$"."this->repository->transaction(function"),
    'Reservations occur inside transaction');
$expect(str_contains($serviceCode,'count($existingRefunds) >= 500'),
    'Incomplete max-sized refund scan cannot silently pass');

fwrite(STDOUT,"CF-03 atomic refund safety: {$checks}/{$checks} checks passed (SQL concurrency requires staging test).\n");
