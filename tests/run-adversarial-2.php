<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\PaymentConfirmationService;
use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\FinancialEventEnvelope;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntent;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\ProductLifecycle;
use Sabri\CF03\Domain\ProviderEvidence;

$tests = [];
$now = new DateTimeImmutable('2026-08-04T14:27:00+05:00');

$tests['paid activation has no boolean policy bypass parameter'] = static function (): void {
    $method = new ReflectionMethod(ProductLifecycle::class, 'activate');
    sameA2(3, $method->getNumberOfParameters());
};
$tests['non donation activation remains prohibited after full approval'] = static function () use ($now): void {
    $product = new FinancialProduct('education.membership.monthly',ProductKind::EDUCATION_MEMBERSHIP,BillingType::RECURRING,'file00.membership','education.structured','refund.education.v1','cancel.education.v1',true,true,'approval:1');
    $life = new ProductLifecycle($product);
    $life->stage('stager:1',1);
    $life->approve('approver:1',2);
    throwsA2(static fn () => $life->activate('activator:1',$now,3),DomainException::class);
};
$tests['balanced but wrong amount ledger cannot settle payment'] = static function () use ($now): void {
    [$intent,$evidence] = readyIntentA2($now,'payment.settled');
    $ledger = ledgerA2(1000);
    $service = serviceA2();
    throwsA2(static fn () => $service->confirmSettled($intent,$evidence,$ledger,3,$now,'event:settled:1'),DomainException::class);
};
$tests['signed non settlement event cannot settle payment'] = static function () use ($now): void {
    [$intent,$evidence] = readyIntentA2($now,'payment.authorized');
    $service = serviceA2();
    throwsA2(static fn () => $service->confirmSettled($intent,$evidence,ledgerA2(1400),3,$now,'event:settled:1'),DomainException::class);
};
$tests['exact settlement event and ledger parity still succeeds'] = static function () use ($now): void {
    [$intent,$evidence] = readyIntentA2($now,'payment.settled');
    $event = serviceA2()->confirmSettled($intent,$evidence,ledgerA2(1400),3,$now,'event:settled:1');
    sameA2('PaymentSettled',$event->type());
};
$tests['financial event envelope rejects arbitrary imperative event'] = static function () use ($now): void {
    throwsA2(static fn () => new FinancialEventEnvelope('event:1','PaymentProcess','intent:1','1','1.0','trace:1',$now,[]),InvalidArgumentException::class);
};
$tests['financial event envelope accepts normalized fact'] = static function () use ($now): void {
    $event = new FinancialEventEnvelope('event:1','PaymentSettled','intent:1','1','1.0','trace:1',$now,[]);
    sameA2('PaymentSettled',$event->toArray()['event_type']);
};
$tests['oversized webhook body rejected before parsing'] = static function () use ($now): void {
    $verifier = new ProviderWebhookVerifier(static fn ():string=>str_repeat('s',32),static fn ():bool=>true);
    throwsA2(static fn () => $verifier->verify('provider.sandbox','key:v1',str_repeat('x',1048577),str_repeat('0',64),$now,$now),InvalidArgumentException::class);
};
$tests['ledger representation rejects multiple currencies'] = static function (): void {
    $ledger = new LedgerTransaction('ledger:multi',[
        new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money(1400,'USD'),'intent:1'),
        new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money(1400,'USD'),'intent:1'),
        new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money(100,'PKR'),'intent:1'),
        new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money(100,'PKR'),'intent:1'),
    ]);
    throwsA2(static fn () => $ledger->assertRepresents(new Money(1400,'USD')),DomainException::class);
};
$tests['ledger representation accepts exact single currency amount'] = static function (): void {
    ledgerA2(1400)->assertRepresents(new Money(1400,'USD'));
};

$failures=0;
foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));
exit($failures===0?0:1);

/** @return array{0:PaymentIntent,1:ProviderEvidence} */
function readyIntentA2(DateTimeImmutable $now,string $eventType):array
{
    $intent=new PaymentIntent('intent:1','user:1','donation.general',null,new Money(1400,'USD'),'provider.sandbox','idem-payment-intent-0001',str_repeat('a',64),$now,$now->modify('+1 hour'));
    $intent->attachProviderReference('provider-payment:1',1,$now);
    $intent->transition(PaymentIntentState::PROVIDER_PENDING,2,$now);
    $evidence=new ProviderEvidence('provider.sandbox','event:provider:1',$eventType,'intent:1',new Money(1400,'USD'),'key:v1',$now->modify('-10 seconds'),$now,str_repeat('b',64),true,true);
    return [$intent,$evidence];
}
function ledgerA2(int $amount):LedgerTransaction
{
    return new LedgerTransaction('ledger:1',[
        new LedgerEntry('asset.cash',LedgerEntry::DEBIT,new Money($amount,'USD'),'intent:1'),
        new LedgerEntry('income.donation',LedgerEntry::CREDIT,new Money($amount,'USD'),'intent:1'),
    ]);
}
function serviceA2():PaymentConfirmationService
{
    return new PaymentConfirmationService(static fn(callable $work):mixed=>$work(),static fn(LedgerTransaction $transaction):null=>null,static fn($event):null=>null);
}
function sameA2(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */
function throwsA2(callable $callback,string $class):void{try{$callback();}catch(Throwable $error){if($error instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());}throw new RuntimeException('Expected '.$class.' to be thrown.');}
