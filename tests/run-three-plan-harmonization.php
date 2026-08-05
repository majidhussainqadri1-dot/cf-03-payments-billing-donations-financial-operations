<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialDownloadContract;
use Sabri\CF03\Application\RouteCatalogue;
use Sabri\CF03\Domain\FinancialDownloadGrant;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Support\InvariantViolation;

$tests=[];
$created=new DateTimeImmutable('2026-08-05T13:01:00+05:00');
$expires=$created->modify('+15 minutes');

$tests['01 current chat download directive is canonical']=static function():void{same3('CHAT-DL-001',FinancialDownloadContract::DIRECTIVE_ID);};
$tests['02 eligible financial asset types are exact']=static function():void{same3(['invoice','receipt','finance_export','transparency_snapshot'],FinancialDownloadContract::eligibleAssetTypes());};
$tests['03 download control delegates green Ionicons presentation']=static function():void{$c=FinancialDownloadContract::contract()['download_control'];same3('Download',$c['text_label']);same3('Ionicons',$c['icon_family']);same3('download-outline',$c['icon_name']);same3('platform-primary-green',$c['primary_accent_token']);};
$tests['04 canonical ownership boundaries are preserved']=static function():void{$c=FinancialDownloadContract::contract();same3('CF-03',$c['native_owner']);same3('File 20',$c['download_control']['shell_and_global_manager_owner']);same3('File 25',$c['download_control']['visual_owner']);same3('File 24',$c['assurance_owner']);same3('CF-04',$c['secure_delivery_owner_after_activation']);};
$tests['05 REST exposes the same fail closed contract']=static function():void{$c=WordPressRestApi::downloadContract();same3('CHAT-DL-001',$c['directive_id']);same3(false,$c['delivery']['live_delivery_enabled']);};
$tests['06 invoice download route exists']=static function():void{route3('/billing/invoices/{id}/download');};
$tests['07 receipt download route exists']=static function():void{route3('/billing/receipts/{id}/download');};
$tests['08 finance export download route exists']=static function():void{route3('/admin/finance/exports/{id}/download');};
$tests['09 public transparency snapshot download route exists']=static function():void{route3('/transparency/{snapshot}/download');};
$tests['10 owner scoped grant is usable']=static function()use($created,$expires):void{$g=grant3('receipt','user:1001',$created,$expires);$g->assertUsableBy('user:1001',$created->modify('+1 minute'));same3(true,$g->toPresentationContract()['download_allowed']);};
$tests['11 public aggregate grant permits public audience']=static function()use($created,$expires):void{$g=grant3('transparency_snapshot','public',$created,$expires);$g->assertUsableBy('guest:anonymous',$created->modify('+1 minute'));};
$tests['12 audience mismatch is denied']=static function()use($created,$expires):void{$g=grant3('invoice','user:1001',$created,$expires);throws3(static fn()=>$g->assertUsableBy('user:1002',$created->modify('+1 minute')),InvariantViolation::class);};
$tests['13 expired grant is denied']=static function()use($created,$expires):void{$g=grant3('finance_export','finance:operator1',$created,$expires);throws3(static fn()=>$g->assertUsableBy('finance:operator1',$expires),InvariantViolation::class);};
$tests['14 denied grant never exposes delivery reference']=static function()use($created,$expires):void{$g=new FinancialDownloadGrant('grant:deny1','receipt','receipt:1001','user:1001','receipt-1001.pdf','application/pdf',str_repeat('a',64),$created,$expires,false,null,'not_ready');same3(null,$g->toPresentationContract()['delivery_reference']);throws3(static fn()=>$g->assertUsableBy('user:1001',$created),InvariantViolation::class);};
$tests['15 unsafe filename is rejected']=static function()use($created,$expires):void{throws3(static fn()=>new FinancialDownloadGrant('grant:bad1','receipt','receipt:1001','user:1001','../receipt.pdf','application/pdf',str_repeat('a',64),$created,$expires,true,'vault://finance/receipt-1001.pdf'),InvalidArgumentException::class);};
$tests['16 unsupported asset type is rejected']=static function()use($created,$expires):void{throws3(static fn()=>new FinancialDownloadGrant('grant:bad2','bank_secret','asset:1001','user:1001','asset-1001.pdf','application/pdf',str_repeat('a',64),$created,$expires,true,'vault://finance/asset-1001.pdf'),InvalidArgumentException::class);};
$tests['17 unapproved media type is rejected']=static function()use($created,$expires):void{throws3(static fn()=>new FinancialDownloadGrant('grant:bad3','invoice','invoice:1001','user:1001','invoice-1001.exe','application/x-msdownload',str_repeat('a',64),$created,$expires,true,'vault://finance/invoice-1001.exe'),InvalidArgumentException::class);};
$tests['18 invalid checksum is rejected']=static function()use($created,$expires):void{throws3(static fn()=>new FinancialDownloadGrant('grant:bad4','invoice','invoice:1001','user:1001','invoice-1001.pdf','application/pdf','bad',$created,$expires,true,'vault://finance/invoice-1001.pdf'),InvalidArgumentException::class);};
$tests['19 unsafe delivery reference is rejected']=static function()use($created,$expires):void{throws3(static fn()=>new FinancialDownloadGrant('grant:bad5','invoice','invoice:1001','user:1001','invoice-1001.pdf','application/pdf',str_repeat('a',64),$created,$expires,true,'https://example.com/file?token=secret'),InvalidArgumentException::class);};
$tests['20 no live financial delivery is activated']=static function():void{$c=FinancialDownloadContract::contract();same3(true,$c['delivery']['signed_or_same_origin_expiring_grant']);same3(true,$c['delivery']['checksum_sha256']);same3(true,$c['delivery']['audit_required']);same3(false,$c['delivery']['live_delivery_enabled']);};

$failures=0;
foreach($tests as $name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable $e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}
fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));
exit($failures===0?0:1);

function grant3(string $type,string $audience,DateTimeImmutable $created,DateTimeImmutable $expires):FinancialDownloadGrant
{
    return new FinancialDownloadGrant('grant:1001',$type,$type.':1001',$audience,$type.'-1001.pdf','application/pdf',str_repeat('a',64),$created,$expires,true,'vault://finance/'.$type.'-1001.pdf');
}
function route3(string $route):void
{
    $definitions=RouteCatalogue::definitions();
    $match=array_values(array_filter($definitions,static fn(array $item):bool=>$item['route']===$route));
    same3(1,count($match));
    same3('CF-03',$match[0]['owner']);
    same3('File 20',$match[0]['shell_owner']);
    same3('File 25',$match[0]['visual_owner']);
    same3('noindex',$match[0]['index']);
}
function same3(mixed $expected,mixed $actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
/** @param class-string<Throwable> $class */
function throws3(callable $callback,string $class):void{try{$callback();}catch(Throwable $e){if($e instanceof $class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
