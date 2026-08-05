<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressIncidentStateStore;
use Sabri\CF03\Infrastructure\WordPressRestApi;

final class SecondReviewRequest
{
    /** @param array<string,mixed> $params @param array<string,mixed> $headers */
    public function __construct(
        private readonly array $params = [],
        private readonly string $body = '',
        private readonly array $headers = [],
        private readonly string $method = 'POST'
    ) {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
    public function get_body(): string { return $this->body; }
    /** @return array<string,mixed> */ public function get_headers(): array { return $this->headers; }
    public function get_header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return is_array($value) ? implode(',', $value) : (string)$value;
            }
        }
        return null;
    }
    public function get_method(): string { return $this->method; }
}

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$contents=file_get_contents($root.'/'.$path);if(!is_string($contents)){throw new RuntimeException('Could not read '.$path);}return$contents;};
$tests=[];
$tests['01 plugin source version is current']=static fn()=>containsR2($read('cf-03-payments-billing-donations-financial-operations.php'),'Version: 1.2.0-rc.2');
$tests['02 plugin schema version is current']=static fn()=>containsR2($read('cf-03-payments-billing-donations-financial-operations.php'),"SABRI_CF03_SCHEMA_VERSION', '3.3.0'");
$tests['03 README names all three governing plans']=static function()use($read):void{$s=$read('README.md');foreach(['SSH-PMP-2026-v3.0','All-Chats Recovered Directive Register 2026 v2.1','CF-03 Integrated Final Plan 2026 v2.0']as$n){containsR2($s,$n);}};
$tests['04 README current release identity has no stale source status']=static function()use($read):void{$s=$read('README.md');containsR2($s,'1.2.0-rc.2');containsR2($s,'3.3.0');sameR2(false,str_contains($s,'> **Source status:** `1.1.0-rc.3`'));};
$tests['05 README records third-review QA target']=static function()use($read):void{$s=$read('README.md');containsR2($s,'404 tests per PHP version');containsR2($s,'1,212 test executions');};
$tests['06 security document reflects routes but no approved live adapter']=static function()use($read):void{$s=$read('SECURITY.md');containsR2($s,'source routes and provider-neutral contracts');containsR2($s,'does **not** contain an approved Live payment-provider adapter');};
$tests['07 architecture identity is current']=static fn()=>containsR2($read('docs/architecture.md'),'# CF-03 Architecture — 1.2.0-rc.2');
$tests['08 architecture schema is 3.3.0']=static function()use($read):void{$s=$read('docs/architecture.md');containsR2($s,'RuntimeSchemaExtension::VERSION = 3.3.0');containsR2($s,'CompleteSchema::VERSION = 3.3.0');};
$tests['09 temporary probe artifact is absent']=static fn()=>sameR2(false,file_exists($root.'/docs/push-files-probe.tmp'));
$tests['10 package script excludes temporary artifacts']=static function()use($read):void{$s=$read('scripts/build-package.sh');foreach(["! -name '*.tmp'","! -name '*.log'","! -name '*.map'","! -name '.DS_Store'"]as$n){containsR2($s,$n);}};
$tests['11 public policy runtime projection is minimal']=static fn()=>sameR2(['state','live_collection_enabled','operational_details_redacted'],array_keys(WordPressRestApi::policy()['runtime']));
$tests['12 public policy does not expose provider identity']=static function():void{$e=json_encode(WordPressRestApi::policy(),JSON_THROW_ON_ERROR);sameR2(false,str_contains($e,'provider.unconfigured'));sameR2(false,str_contains($e,'provider_selected'));};
$tests['13 public policy does not expose incident evidence']=static function():void{$p=WordPressRestApi::policy();sameR2(false,array_key_exists('incident',$p));$e=json_encode($p,JSON_THROW_ON_ERROR);sameR2(false,str_contains($e,'incident_id'));sameR2(false,str_contains($e,'resolution_evidence_ref'));};
$tests['14 public policy remains fail closed in preparing state']=static fn()=>sameR2(false,WordPressRestApi::policy()['live_collection_enabled']);
$tests['15 normal incident state represents available paths']=static function():void{$s=WordPressIncidentStateStore::normal();sameR2(true,$s['checkout_enabled']);sameR2(true,$s['refunds_enabled']);sameR2(true,$s['webhooks_enabled']);};
$tests['16 live policy calculation consults incident webhook and provider']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');containsR2($s,"\$incident['checkout_enabled']");containsR2($s,"\$incident['webhooks_enabled']");containsR2($s,'self::providerReady($runtime)');};
$tests['17 public donation errors use redacted handler']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');sameR2(true,substr_count($s,'return self::safePublicError($error);')>=3);containsR2($s,'The financial service is unavailable or the request conflicts with its current safe state.');};
$tests['18 public donation body is bounded']=static function():void{$r=new SecondReviewRequest(['amount_minor'=>'1000','currency'=>'USD','monthly'=>'0'],str_repeat('a',65537));$v=WordPressRestApi::donationPreparing($r);sameR2(422,$v['status']);containsR2((string)$v['message'],'size limit');};
$tests['19 webhook body is bounded before provider processing']=static function():void{$r=new SecondReviewRequest(['provider'=>'provider.sandbox'],str_repeat('a',1048577));$v=WordPressRestApi::webhook($r);sameR2(true,in_array($v['status'],[409,422],true));};
$tests['20 webhook header count is bounded']=static function()use($read):void{containsR2($read('src/Infrastructure/WordPressRestApi.php'),'MAX_WEBHOOK_HEADERS = 64');};
$tests['21 webhook rejects CRLF header injection']=static function()use($read):void{containsR2($read('src/Infrastructure/WordPressRestApi.php'),"preg_match('/[\\x00\\r\\n]/'");};
$tests['22 guest financial reference lifetime is thirty days']=static fn()=>containsR2($read('src/Infrastructure/WordPressRestApi.php'),'GUEST_REFERENCE_TTL_SECONDS = 2592000');
$tests['23 donation front end locks duplicate submissions']=static function()use($read):void{$s=$read('assets/js/public.js');foreach(["form.dataset.submitting==='1'","form.dataset.submitting='1'","form.dataset.submitting='0'"]as$n){containsR2($s,$n);}};
$tests['24 donation front end disables busy submit button']=static function()use($read):void{$s=$read('assets/js/public.js');containsR2($s,'submit.disabled=true');containsR2($s,'submit.disabled=false');};
$tests['25 browser sends one idempotency key in header and body']=static function()use($read):void{$s=$read('assets/js/public.js');foreach(["'Idempotency-Key':idempotency",'idempotency_key:idempotency','form.elements.idempotency_key.value=idempotency']as$n){containsR2($s,$n);}};
$tests['26 browser fails closed without secure randomness']=static fn()=>containsR2($read('assets/js/public.js'),'globalThis.crypto?.getRandomValues');
$tests['27 suggested and custom amounts are mutually exclusive']=static function()use($read):void{$s=$read('assets/js/public.js');containsR2($s,"custom.value=''");containsR2($s,'input.checked=false');};
$tests['28 shortcode renders approved bilingual copy contract']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressPublicUi.php');containsR2($s,'DonationAppealCopy::contract()');containsR2($s,"str_starts_with((string)get_locale(), 'ur')");};
$tests['29 donation amount controls have fieldset and legend']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressPublicUi.php');containsR2($s,'<fieldset class="sabri-cf03-amounts"');containsR2($s,'<legend>');};
$tests['30 donation status is an atomic live region']=static fn()=>containsR2($read('src/Infrastructure/WordPressPublicUi.php'),'role="status" aria-live="polite" aria-atomic="true"');
$tests['31 visual layer has compatibility border fallback']=static function()use($read):void{$s=$read('assets/css/public.css');$a=strpos($s,'border:1px solid #b9c9bf');$b=strpos($s,'color-mix(');sameR2(true,is_int($a)&&is_int($b)&&$a<$b);};
$tests['32 visual layer exposes disabled and reduced-motion states']=static function()use($read):void{$s=$read('assets/css/public.css');containsR2($s,'button:disabled');containsR2($s,'@media(prefers-reduced-motion:reduce)');};
$tests['33 finance exports retain dedicated capability']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressFinanceAdminApi.php');containsR2($s,"['/exports', 'POST', 'requestExport', 'exports']");containsR2($s,"'exports' => static fn (): bool => self::cap('sabri_manage_finance_exports')");};
$tests['34 refund and webhook mutations retain incident guards']=static function()use($read):void{$s=$read('src/Infrastructure/WordPressRestApi.php');sameR2(true,substr_count($s,"assertAvailable('refunds')")>=3);sameR2(true,substr_count($s,"assertAvailable('webhooks')")>=1);};
$tests['35 audit metadata still rejects toxic financial keys']=static function()use($read):void{$s=strtolower($read('src/Domain/AuditEnvelope.php'));foreach(['pan','cvv','pin','otp','password','raw_body']as$n){containsR2($s,$n);}};
$tests['36 File 00 remains entitlement owner and donation is not ranking']=static function()use($read):void{$s=$read('src/Domain/DonationNeutralityPolicy.php');containsR2($s,"'file00_entitlement_owner'=>true");containsR2($s,"'file26_ranking_must_ignore_donation'=>true");};
$tests['37 contracts and migration documents describe active runtime']=static function()use($read):void{containsR2($read('docs/contracts-and-events.md'),'durable WordPress repository');containsR2($read('docs/database-migration-design.md'),'implemented additive 31-table schema');};
$tests['38 ordinary uninstall remains non-destructive']=static function()use($read):void{$s=$read('uninstall.php');containsR2($s,'Financial and audit data must never be purged by ordinary uninstall.');sameR2(false,str_contains($s,'DROP TABLE'));};
$tests['39 second review evidence contains exactly forty rounds']=static function()use($read):void{$s=$read('docs/review-evidence-40-rounds-1.2.0-rc.1.md');sameR2(40,preg_match_all('/^\| (?:0[1-9]|[1-3][0-9]|40) \|/m',$s));};
$tests['40 second review remains wired beside third review']=static function()use($read):void{$c=$read('composer.json');$ci=$read('.github/workflows/ci.yml');containsR2($c,'php tests/run-review-40-second.php');containsR2($ci,'php tests/run-review-40-second.php');};

$failures=0;foreach($tests as$name=>$test){try{$test();fwrite(STDOUT,"PASS: {$name}\n");}catch(Throwable$e){$failures++;fwrite(STDERR,"FAIL: {$name}: {$e->getMessage()}\n");}}fwrite(STDOUT,sprintf("%d tests, %d failures\n",count($tests),$failures));exit($failures===0?0:1);
function containsR2(string$haystack,string$needle):void{if(!str_contains($haystack,$needle)){throw new RuntimeException('Missing expected text: '.$needle);}}
function sameR2(mixed$expected,mixed$actual):void{if($expected!==$actual){throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}}
