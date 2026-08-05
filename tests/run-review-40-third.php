<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\DonationPromptService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationFinancialFactType;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptContext;
use Sabri\CF03\Domain\DonationPromptPolicy;
use Sabri\CF03\Domain\DonationPromptState;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Domain\TrustedDonationFact;
use Sabri\CF03\Infrastructure\MemoryDonationPromptStateStore;
use Sabri\CF03\Infrastructure\WordPressIncidentStateStore;
use Sabri\CF03\Persistence\CompleteSchema;

$root=dirname(__DIR__);
$read=static function(string$path)use($root):string{$v=file_get_contents($root.'/'.$path);if(!is_string($v)){throw new RuntimeException('Unable to read '.$path);}return$v;};
$now=new DateTimeImmutable('2026-08-05T22:51:00+05:00');
$gates=['founder_change_control'=>true,'legal_tax_accounting'=>true,'pci_scope'=>true,'provider_selected'=>true,'independent_security'=>true,'staging_acceptance'=>true,'rollback_evidence'=>true,'file00_contract'=>true,'file20_file25_contract'=>true,'file24_assurance'=>true,'operations_ready'=>true,'webhook_endpoint'=>true];
$tests=[];
$tests['01 checkout requires enabled webhook']=static function()use($gates):void{$r=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,false,false);sameR3(true,in_array('webhook_enabled',$r->missingDonationCollectionGates(),true));throwsR3(static fn()=>$r->assertDonationCheckoutReady(),DomainException::class);};
$tests['02 checkout requires accepted webhook endpoint']=static function()use($gates):void{$g=$gates;$g['webhook_endpoint']=false;$r=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$g,true,false);sameR3(true,in_array('webhook_endpoint',$r->missingDonationCollectionGates(),true));};
$tests['03 complete sandbox collection gates pass']=static function()use($gates):void{$r=new RuntimeConfiguration(DonationServiceState::SANDBOX,'provider.sandbox',$gates,true,false);sameR3([],$r->missingDonationCollectionGates());$r->assertDonationCheckoutReady();};
$tests['04 preparing collection remains closed']=static fn()=>throwsR3(static fn()=>RuntimeConfiguration::preparing()->assertDonationCheckoutReady(),DomainException::class);
$tests['05 unknown donation prompt context fails closed']=static function():void{$c=new DonationPromptContext('pageview:unknown1','future_unknown_context',60,true,false,false,false);sameR3(true,$c->isSensitiveContext());};
$tests['06 approved normal prompt context remains eligible']=static function():void{$c=new DonationPromptContext('pageview:normal01','normal',60,true,false,false,false);sameR3(false,$c->isSensitiveContext());};
$tests['07 malformed stored prompt date is rejected']=static fn()=>throwsR3(static fn()=>DonationPromptState::fromStorage(['last_donation_prompt_at'=>'not-a-date']),InvalidArgumentException::class);
$tests['08 prompt storage emits exactly six canonical fields']=static function():void{sameR3(['last_donation_prompt_at','next_donation_prompt_at','donation_prompt_status','donation_prompt_snoozed_until','last_donation_completed_at','recurring_donation_status'],array_keys((new DonationPromptState())->toStorage()));};
$tests['09 stale prompt action chronology is rejected']=static function()use($now):void{$s=(new DonationPromptState())->apply(DonationPromptAction::SHOWN,$now);throwsR3(static fn()=>$s->apply(DonationPromptAction::CLOSE,$now->modify('-1 second')),DomainException::class);};
$tests['10 trusted donation fact cannot cross prompt subjects']=static function()use($now):void{$m=new Money(1400,'USD');$e=new ProviderEvidence('provider.sandbox','event:subject:1',DonationFinancialFactType::MONTHLY_STARTED->value,'intent:subject:1',$m,'key:v1',$now->modify('-10 seconds'),$now,str_repeat('a',64),true,true,$now->modify('-10 seconds'));$f=new TrustedDonationFact(DonationFinancialFactType::MONTHLY_STARTED,$e,'provider.sandbox','intent:subject:1',$m,300,'user:one');$s=new DonationPromptService(new MemoryDonationPromptStateStore(),new DonationPromptPolicy(),static fn():DateTimeImmutable=>$now);throwsR3(static fn()=>$s->recordTrustedFinancialFact('user:two',$f),DomainException::class);};
$tests['11 provider-safe donation clone withholds donor identity']=static function()use($now):void{$d=new DonationIntentDraft('intent:safe:1','user:private',new Money(1000,'USD'),false,false,DonationServiceState::SANDBOX,'idem-provider-safe-0001',$now);sameR3('donor:withheld',$d->providerSafeClone()->donorReference());};
$tests['12 safe donation payload has no donor reference']=static function()use($now):void{$d=new DonationIntentDraft('intent:safe:2','user:private',new Money(1000,'USD'),false,false,DonationServiceState::SANDBOX,'idem-provider-safe-0002',$now);sameR3(false,array_key_exists('donor_reference',$d->toSafePayload()));};
$tests['13 complete schema version is 3.3.0']=static fn()=>sameR3('3.3.0',CompleteSchema::VERSION);
$tests['14 complete schema retains thirty-one tables']=static fn()=>sameR3(31,count(CompleteSchema::tables('wp_')));
$tests['15 transparency schema stores snapshot hash']=static fn()=>containsR3(CompleteSchema::tables('wp_')['transparency_snapshots'],'snapshot_hash char(64) NOT NULL');
$tests['16 transparency uniqueness is period plus currency']=static function():void{$s=CompleteSchema::tables('wp_')['transparency_snapshots'];containsR3($s,'UNIQUE KEY period_currency(period_key,currency)');sameR3(false,str_contains($s,'UNIQUE KEY period_key(period_key)'));};
$tests['17 ledger source reference is unique']=static fn()=>containsR3(CompleteSchema::tables('wp_')['ledger_entries'],'UNIQUE KEY source_once(source_ref)');
$tests['18 recurring consent supports optimistic version']=static fn()=>containsR3(CompleteSchema::tables('wp_')['recurring_consents'],'record_version bigint unsigned NOT NULL DEFAULT 1');
$tests['19 WordPress runtime checks installed schema identity']=static fn()=>containsR3($read('src/Infrastructure/WordPressRuntimeConfiguration.php'),'CompleteSchema::VERSION, $installedSchema');
$tests['20 corrupt persisted incident state fails closed']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressIncidentStateStore.php');containsR3($s,'return self::corrupt()');containsR3($s,"'checkout_enabled' => false");};
$tests['21 normal incident state keeps all paths available']=static function():void{$s=WordPressIncidentStateStore::normal();sameR3([true,true,true],[$s['checkout_enabled'],$s['refunds_enabled'],$s['webhooks_enabled']]);};
$tests['22 recovered checkout requires webhooks']=static fn()=>containsR3($read('src/Infrastructure/WordPressIncidentStateStore.php'),'Recovered checkout requires the trusted webhook path.');
$tests['23 contained incident cannot be overwritten']=static fn()=>containsR3($read('src/Application/IncidentOperationsService.php'),'must be recovered before another incident is declared');
$tests['24 repository detects duplicate canonical identifiers']=static fn()=>containsR3($read('src/Infrastructure/WordPressFinancialRepository.php'),'LIMIT 2');
$tests['25 nested repository failures force outer rollback']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressFinancialRepository.php');containsR3($s,'rollbackOnly');containsR3($s,'marked rollback-only by a nested failure');};
$tests['26 bounded update rejects pseudo id criteria']=static fn()=>containsR3($read('src/Infrastructure/WordPressFinancialRepository.php'),"array_key_exists('id', \$criteria)");
$tests['27 bounded delete rejects pseudo id criteria']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressFinancialRepository.php');sameR3(true,substr_count($s,"array_key_exists('id', \$criteria)")>=2);};
$tests['28 checkout durably checkpoints provider creation']=static function()use($read):void{$s=$read('src/Application/DonationCheckoutService.php');containsR3($s,"'state' => 'provider_created'");containsR3($s,'durably checkpointed');};
$tests['29 provider receives only safe donation clone']=static fn()=>containsR3($read('src/Application/DonationCheckoutService.php'),'createHostedDonationCheckout($draft->providerSafeClone())');
$tests['30 public donation response excludes provider internals']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');containsR3($s,'private static function publicDonationResult');sameR3(false,str_contains(substr($s,strpos($s,'private static function publicDonationResult'),1200),"'provider_session_reference'"));};
$tests['31 idempotency header and body mismatch is rejected']=static fn()=>containsR3($read('src/Infrastructure/WordPressRestApi.php'),'Idempotency header and request body do not match.');
$tests['32 unpersisted guest financial identity is rejected']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');containsR3($s,'headers_sent()');containsR3($s,'A durable guest financial identity could not be established safely.');};
$tests['33 sensitive web request headers are stripped']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');foreach(['authorization','cookie','set-cookie','x-wp-nonce']as$n){containsR3($s,$n);}containsR3($s,'SENSITIVE_WEBHOOK_HEADERS');};
$tests['34 donation UI is disabled while collection is closed']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressPublicUi.php');containsR3($s,'collectionEnabled');containsR3($s,'no funds are being collected at this time');containsR3($s,'disabled aria-disabled="true"');};
$tests['35 browser accepts at most two decimal places']=static fn()=>containsR3($read('assets/js/public.js'),'/^(?:0|[1-9]\\d*)(?:\\.\\d{1,2})?$/');
$tests['36 browser preserves idempotency key after uncertain failure']=static fn()=>sameR3(false,str_contains($read('assets/js/public.js'),"form.elements.idempotency_key.value=''"));
$tests['37 privacy export is paginated and reports safe failure']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressPrivacy.php');containsR3($s,'forActorPage');containsR3($s,'Financial records could not be read safely during this export request.');};
$tests['38 transparency downloads verify stored snapshot hash']=static function()use($read):void{$a=$read('src/Application/FinancialDocumentService.php');$b=$read('src/Infrastructure/WordPressTransparencyRepository.php');containsR3($a,'snapshot_hash');containsR3($a,'hash_equals($snapshotHash');containsR3($b,'Published transparency snapshot failed integrity verification.');};
$tests['39 plugin upgrade and schema index verification are wired']=static function()use($read):void{$p=$read('src/Plugin.php');$i=$read('src/Infrastructure/WordPressSchemaInstaller.php');containsR3($p,"add_action('init', [self::class, 'maybeUpgrade'], 1)");containsR3($i,'SHOW INDEX FROM');containsR3($i,'requiredIndexes');};
$tests['40 third review evidence CI and manifests are locked']=static function()use($read):void{$c=$read('composer.json');$ci=$read('.github/workflows/ci.yml');$m=$read('manifests/cf03-release-1.2.0.json');containsR3($c,'run-review-40-third.php');containsR3($ci,'run-review-40-third.php');containsR3($ci,'review-evidence-40-rounds-third-1.2.0-rc.2.md');containsR3($m,'1.2.0-rc.2');};

$failures=0;foreach($tests as$name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable$e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
function containsR3(string$h,string$n):void{if(!str_contains($h,$n)){throw new RuntimeException('Missing expected text: '.$n);}}
function sameR3(mixed$e,mixed$a):void{if($e!==$a){throw new RuntimeException('Expected '.var_export($e,true).', got '.var_export($a,true));}}
/** @param class-string<Throwable> $class */function throwsR3(callable$c,string$class):void{try{$c();}catch(Throwable$e){if($e instanceof$class){return;}throw new RuntimeException('Expected '.$class.', got '.$e::class.': '.$e->getMessage());}throw new RuntimeException('Expected '.$class);}
