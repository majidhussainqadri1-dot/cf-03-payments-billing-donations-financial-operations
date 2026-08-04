<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\BackupManifest;
use Sabri\CF03\Application\IncidentControl;
use Sabri\CF03\Contracts\FinancialRepository;
use Sabri\CF03\Domain\FinanceExport;
use Sabri\CF03\Domain\FinancePeriod;
use Sabri\CF03\Domain\Invoice;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PrePurchaseDisclosure;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\RefundRequest;
use Sabri\CF03\Domain\Subscription;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Persistence\MigrationRunner;
use Sabri\CF03\Persistence\Schema;

$tests = [];
$tests['recurring disclosure requires interval'] = static fn () => assertThrows(static fn () => new PrePurchaseDisclosure('p','v',new Money(40000,'PKR'),true,null,null,'exclusive','r1','c1','hosted','notice'), InvalidArgumentException::class);
$tests['one time disclosure rejects renewal'] = static fn () => assertThrows(static fn () => new PrePurchaseDisclosure('p','v',new Money(100,'PKR'),false,'month',null,'exclusive','r1','c1','hosted','notice'), InvalidArgumentException::class);
$tests['valid disclosure serializes exact amount'] = static function (): void { $d=new PrePurchaseDisclosure('education-membership','v1',new Money(40000,'PKR'),true,'month','2026-09-01','exclusive','r1','c1','hosted','notice'); assertSame(40000,$d->toArray()['total_minor']); };
$tests['invoice validates item total'] = static function (): void { new Invoice('i','INV-1','u','Sabri',[['description'=>'Membership','quantity'=>1,'unit_minor'=>40000]],new Money(40000,'PKR'),'issued',hash('sha256','snapshot')); };
$tests['invoice rejects mismatch'] = static fn () => assertThrows(static fn () => new Invoice('i','INV-1','u','Sabri',[['description'=>'Membership','quantity'=>1,'unit_minor'=>39999]],new Money(40000,'PKR'),'issued',hash('sha256','snapshot')), DomainException::class);
$tests['subscription optimistic transition'] = static function (): void { $s=new Subscription('s','u','p','v','pending'); $s->transition('active',1); assertSame('active',$s->state()); assertSame(2,$s->version()); };
$tests['subscription rejects stale version'] = static function (): void { $s=new Subscription('s','u','p','v','pending'); assertThrows(static fn () => $s->transition('active',2), DomainException::class); };
$tests['subscription rejects invalid transition'] = static function (): void { $s=new Subscription('s','u','p','v','pending'); assertThrows(static fn () => $s->transition('past_due',1), DomainException::class); };
$tests['refund separates requester reviewer executor'] = static function (): void { $r=new RefundRequest('r','pi','user',new Money(100,'PKR'),'duplicate'); $r->approve('reviewer'); $r->markExecuting('executor'); $r->succeed(); assertSame('succeeded',$r->status()); };
$tests['refund requester cannot approve'] = static function (): void { $r=new RefundRequest('r','pi','user',new Money(100,'PKR'),'duplicate'); assertThrows(static fn () => $r->approve('user'), DomainException::class); };
$tests['refund reviewer cannot execute'] = static function (): void { $r=new RefundRequest('r','pi','user',new Money(100,'PKR'),'duplicate'); $r->approve('reviewer'); assertThrows(static fn () => $r->markExecuting('reviewer'), DomainException::class); };
$tests['material reconciliation blocks close'] = static function (): void { $x=new ReconciliationResult([['type'=>'missing','reference'=>'p','expected'=>100,'actual'=>0,'currency'=>'PKR','material'=>true]]); assertThrows(static fn () => $x->assertClosable(), DomainException::class); };
$tests['non material reconciliation permits close'] = static function (): void { $x=new ReconciliationResult([['type'=>'rounding','reference'=>'p','expected'=>100,'actual'=>99,'currency'=>'PKR','material'=>false]]); $p=new FinancePeriod('2026-08'); $p->close($x,'approver'); assertSame(true,$p->closed()); };
$tests['closed period rejects mutation'] = static function (): void { $p=new FinancePeriod('2026-08'); $p->close(new ReconciliationResult([]),'approver'); assertThrows(static fn () => $p->assertWritable(), DomainException::class); };
$tests['export neutralizes formulas'] = static fn () => assertSame("'=2+2", FinanceExport::neutralizeSpreadsheetFormula('=2+2'));
$tests['export rejects secret fields'] = static fn () => assertThrows(static fn () => new FinanceExport('e',[['cvv'=>'123']],hash('sha256','m'),time()+60), DomainException::class);
$tests['export expiry works'] = static function (): void { $e=new FinanceExport('e',[['amount'=>100]],hash('sha256','m'),100); assertSame(true,$e->expired(100)); };
$tests['memory repository rejects duplicates'] = static function (): void { $r=new MemoryFinancialRepository(); $r->insert('x','1',['state'=>'a']); assertThrows(static fn () => $r->insert('x','1',['state'=>'b']), DomainException::class); };
$tests['memory repository compare and swap'] = static function (): void { $r=new MemoryFinancialRepository(); $r->insert('x','1',['state'=>'a']); $next=$r->compareAndSwap('x','1',1,static fn(array $v):array=>['state'=>'b']); assertSame(2,$next['version']); };
$tests['memory repository stale update rejected'] = static function (): void { $r=new MemoryFinancialRepository(); $r->insert('x','1',['state'=>'a']); assertThrows(static fn () => $r->compareAndSwap('x','1',2,static fn(array $v):array=>$v), DomainException::class); };
$tests['schema declares complete owner tables'] = static function (): void { $tables=Schema::tables('wp_'); foreach (['products','prices','intents','provider_events','ledger_transactions','ledger_entries','subscriptions','invoices','refunds','outbox','audit','migrations'] as $name) { assertSame(true,isset($tables[$name])); } };
$tests['migration runner is idempotency aware'] = static function (): void { $ran=[]; $m=new MigrationRunner(static function(string $sql) use (&$ran):void{$ran[]=$sql;},static fn(string $id):bool=>str_ends_with($id,'products')); $applied=$m->migrate('wp_'); assertSame(false,in_array('cf03-1.0.0-products',$applied,true)); assertSame(count(Schema::tables('wp_'))-1,count($applied)); };
$tests['incident controls are selective'] = static function (): void { $i=new IncidentControl(); $i->enableAfterApproval(); $i->killCheckout(); assertSame(['checkout'=>false,'refunds'=>true,'webhooks'=>true],$i->status()); };
$tests['backup manifests match'] = static function (): void { $a=new BackupManifest(['ledger'=>['count'=>2,'hash'=>hash('sha256','x')]]); $a->assertMatches(new BackupManifest(['ledger'=>['count'=>2,'hash'=>hash('sha256','x')]])); };
$tests['backup mismatch rejected'] = static function (): void { $a=new BackupManifest(['ledger'=>['count'=>2,'hash'=>hash('sha256','x')]]); assertThrows(static fn () => $a->assertMatches(new BackupManifest(['ledger'=>['count'=>1,'hash'=>hash('sha256','x')]])), DomainException::class); };

$failures=0; foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures)); exit($failures===0?0:1);

function assertSame(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Assertion failed: '.var_export($expected,true).' !== '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */ function assertThrows(callable $fn,string $class):void{try{$fn();}catch(Throwable $e){if($e instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class);}throw new RuntimeException('Expected exception '.$class);}
