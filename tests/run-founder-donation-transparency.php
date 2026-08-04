<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Application\RouteCatalogue;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\DonationExpenseCategory;
use Sabri\CF03\Domain\FinancialTransparencySnapshot;
use Sabri\CF03\Domain\FounderOwnershipPolicy;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Persistence\Schema;
use Sabri\CF03\Persistence\TransparencySchema;

$tests=[];$now=new DateTimeImmutable('2026-08-04T22:02:00+05:00');
$tests['policy identity and effective time are final']=static function():void{sameFT('SSH-FIN-DONATION-2026-08-04-01',PlatformFinancialPolicy::DECISION_ID);sameFT('2026-08-04T22:02:00+05:00',PlatformFinancialPolicy::EFFECTIVE_AT);};
$tests['platform is founder owned and not a trust']=static function():void{$p=new PlatformFinancialPolicy();sameFT(true,$p->founderOwned());sameFT(false,$p->isTrust());};
$tests['ownership disclosure names founder']=static function():void{$d=(new FounderOwnershipPolicy())->toPublicDisclosure();sameFT('Dr. Allamah Majid Hussain Sabri Muhaddith Murshid',$d['owner']);sameFT(false,$d['is_trust']);};
$tests['donation creates no ownership right']=static function():void{$p=new FounderOwnershipPolicy();foreach(array_keys($p->donorRights()) as $right){$p->assertNoDonorRight($right);sameFT(false,$p->donorRights()[$right]);}};
$tests['approved expense categories are exact']=static function():void{sameFT(8,count(DonationExpenseCategory::allowed()));sameFT(true,in_array(DonationExpenseCategory::OWNER_WITHDRAWAL,DonationExpenseCategory::allowed(),true));};
$tests['unknown expense category rejected']=static fn()=>throwsFT(static fn()=>DonationExpenseCategory::assertAllowed('unrelated_charity'),InvalidArgumentException::class);
$tests['founder category requires founder flag']=static function()use($now):void{throwsFT(static fn()=>new DonationExpense('expense:1001',$now,new Money(100,'USD'),DonationExpenseCategory::FOUNDER_COMPENSATION,'Executive work','founder:1','approval:1','received',false,'founder_compensation'),InvalidArgumentException::class);};
$tests['non founder category rejects founder flag']=static function()use($now):void{throwsFT(static fn()=>new DonationExpense('expense:1002',$now,new Money(100,'USD'),DonationExpenseCategory::TECHNICAL_DEVELOPMENT,'Hosting','vendor:1','approval:1','received',true,'technical_development'),InvalidArgumentException::class);};
$tests['public expense projection hides payee and approval']=static function()use($now):void{$e=new DonationExpense('expense:1003',$now,new Money(100,'USD'),DonationExpenseCategory::TECHNICAL_DEVELOPMENT,'Hosting','vendor:1','approval:1','received',false,'technical_development');$p=$e->toPublicProjection();sameFT(false,array_key_exists('payee_ref',$p));sameFT(false,array_key_exists('approval_ref',$p));};
$tests['snapshot calculates totals balance and founder amount']=static function()use($now):void{$s=snapshotFT($now);sameFT(4500,$s->totalExpenses()->minorUnits());sameFT(5500,$s->currentBalance()->minorUnits());sameFT(1000,$s->founderPaidOrWithdrawn()->minorUnits());};
$tests['snapshot public projection contains required aggregates']=static function()use($now):void{$p=snapshotFT($now)->toPublicProjection();sameFT(10000,$p['total_donations_minor']);sameFT(5500,$p['current_balance_minor']);sameFT(1000,$p['founder_paid_or_withdrawn_minor']);sameFT(false,$p['donor_identity_public']);};
$tests['snapshot rejects expense beyond donations']=static function()use($now):void{throwsFT(static fn()=>new FinancialTransparencySnapshot('snapshot:bad',$now,new Money(100,'USD'),new Money(10,'USD'),new Money(50,'USD'),[DonationExpenseCategory::INSTITUTIONAL_OPERATIONS=>new Money(101,'USD')],$now,str_repeat('a',64)),DomainException::class);};
$tests['snapshot rejects cross currency expense']=static function()use($now):void{throwsFT(static fn()=>new FinancialTransparencySnapshot('snapshot:bad2',$now,new Money(100,'USD'),new Money(10,'USD'),new Money(50,'USD'),[DonationExpenseCategory::INSTITUTIONAL_OPERATIONS=>new Money(10,'PKR')],$now,str_repeat('a',64)),InvalidArgumentException::class);};
$tests['snapshot rejects invalid source hash']=static function()use($now):void{throwsFT(static fn()=>new FinancialTransparencySnapshot('snapshot:bad3',$now,new Money(100,'USD'),new Money(10,'USD'),new Money(50,'USD'),[],$now,'bad'),InvalidArgumentException::class);};
$tests['snapshot rejects month exceeding year']=static function()use($now):void{throwsFT(static fn()=>new FinancialTransparencySnapshot('snapshot:bad4',$now,new Money(100,'USD'),new Money(80,'USD'),new Money(70,'USD'),[],$now,str_repeat('a',64)),InvalidArgumentException::class);};
$tests['appeal copy links transparency and trust statement']=static function():void{$c=DonationAppealCopy::contract();sameFT('/transparency/',$c['transparency_path']);sameFT(false,$c['is_trust']);sameFT('Donate',$c['actions'][0]);};
$tests['policy response exposes final governance']=static function():void{$p=WordPressRestApi::policy();sameFT('SSH-FIN-DONATION-2026-08-04-01',$p['decision_id']);sameFT(true,$p['founder_owned']);sameFT(false,$p['is_trust']);sameFT(true,$p['transparency_required']);};
$tests['public disclosure never grants rights']=static function():void{$d=WordPressRestApi::publicDisclosure();sameFT(false,$d['donor_rights']['ownership']);sameFT('/transparency/',$d['transparency_path']);};
$tests['transparency endpoint never fabricates amounts']=static function():void{$r=WordPressRestApi::transparency();sameFT('not_published',$r['status']);sameFT(null,$r['snapshot']);};
$tests['donation management remains fail closed']=static function():void{$r=WordPressRestApi::donationManagement();sameFT(false,$r['live_mutations_enabled']);sameFT(true,$r['cancellation_must_be_easy']);};
$tests['route catalogue contains public transparency']=static function():void{$routes=array_column(RouteCatalogue::definitions(),'route');sameFT(true,in_array('/transparency/',$routes,true));sameFT(true,in_array('/billing/donations',$routes,true));};
$tests['base schema remains immutable historical layer']=static function():void{sameFT('2.0.0',Schema::VERSION);sameFT(28,count(Schema::tables('wp_')));};
$tests['transparency extension declares three tables']=static function():void{$tables=TransparencySchema::tables('wp_');sameFT(['expenses','transparency_snapshots','donor_acknowledgments'],array_keys($tables));};
$tests['complete schema version and table count are final']=static function():void{sameFT('3.0.0',CompleteSchema::VERSION);sameFT(31,count(CompleteSchema::tables('wp_')));};
$tests['complete schema includes founder expense disclosure fields']=static function():void{$sql=CompleteSchema::tables('wp_')['expenses'];sameFT(true,str_contains($sql,'founder_related'));sameFT(true,str_contains($sql,'public_disclosure_category'));};
$tests['complete schema includes privacy consent acknowledgment']=static function():void{$sql=CompleteSchema::tables('wp_')['donor_acknowledgments'];sameFT(true,str_contains($sql,'consent_hash'));sameFT(true,str_contains($sql,'revoked_at'));};
$tests['prohibited uses include politics luxury and privileges']=static function():void{$p=(new PlatformFinancialPolicy())->prohibitedUses();foreach(['political_or_election_activity','personal_luxury','donor_ranking_or_privilege'] as $v){sameFT(true,in_array($v,$p,true));}};
$tests['public statements exist in Urdu and English']=static function():void{$d=(new PlatformFinancialPolicy())->publicDisclosure();sameFT(true,isset($d['ur'],$d['en-US']));};

$failures=0;foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);

function snapshotFT(DateTimeImmutable $now):FinancialTransparencySnapshot{return new FinancialTransparencySnapshot('snapshot:2026-08',$now,new Money(10000,'USD'),new Money(1000,'USD'),new Money(5000,'USD'),[DonationExpenseCategory::INSTITUTIONAL_OPERATIONS=>new Money(1500,'USD'),DonationExpenseCategory::TECHNICAL_DEVELOPMENT=>new Money(1000,'USD'),DonationExpenseCategory::HOMEOPATHY_ADVANCEMENT=>new Money(500,'USD'),DonationExpenseCategory::ADMINISTRATION_PAYMENT_CHARGES=>new Money(500,'USD'),DonationExpenseCategory::FOUNDER_COMPENSATION=>new Money(400,'USD'),DonationExpenseCategory::FOUNDER_EXPENSE_REIMBURSEMENT=>new Money(300,'USD'),DonationExpenseCategory::FOUNDER_ADVANCE_REPAYMENT=>new Money(200,'USD'),DonationExpenseCategory::OWNER_WITHDRAWAL=>new Money(100,'USD')],$now,str_repeat('a',64));}
function sameFT(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */function throwsFT(callable $callback,string $class):void{try{$callback();}catch(Throwable $e){if($e instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
