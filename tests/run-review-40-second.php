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

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root.'/'.$path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read '.$path);
    }
    return $contents;
};
$tests = [];

$tests['01 plugin source version is current'] = static function () use ($read): void {
    containsR2($read('cf-03-payments-billing-donations-financial-operations.php'), "Version: 1.2.0-rc.1");
};
$tests['02 plugin schema version is current'] = static function () use ($read): void {
    containsR2($read('cf-03-payments-billing-donations-financial-operations.php'), "SABRI_CF03_SCHEMA_VERSION', '3.2.0'");
};
$tests['03 README names all three governing plans'] = static function () use ($read): void {
    $source = $read('README.md');
    foreach (['SSH-PMP-2026-v3.0', 'All-Chats Recovered Directive Register 2026 v2.1', 'CF-03 Integrated Final Plan 2026 v2.0'] as $needle) {
        containsR2($source, $needle);
    }
};
$tests['04 README current release identity has no stale source status'] = static function () use ($read): void {
    $source = $read('README.md');
    containsR2($source, '1.2.0-rc.1');
    containsR2($source, '3.2.0');
    sameR2(false, str_contains($source, '> **Source status:** `1.1.0-rc.3`'));
};
$tests['05 README records second-review QA target'] = static function () use ($read): void {
    $source = $read('README.md');
    containsR2($source, '364 tests per PHP version');
    containsR2($source, '1,092 test executions');
};
$tests['06 security document reflects routes but no approved live adapter'] = static function () use ($read): void {
    $source = $read('SECURITY.md');
    containsR2($source, 'source routes and provider-neutral contracts');
    containsR2($source, 'does **not** contain an approved Live payment-provider adapter');
    sameR2(false, str_contains($source, 'Version `1.0.0-rc.2` contains no live provider implementation'));
};
$tests['07 architecture identity is current'] = static function () use ($read): void {
    containsR2($read('docs/architecture.md'), '# CF-03 Architecture — 1.2.0-rc.1');
};
$tests['08 architecture schema is 3.2.0'] = static function () use ($read): void {
    $source = $read('docs/architecture.md');
    containsR2($source, 'RuntimeSchemaExtension::VERSION = 3.2.0');
    containsR2($source, 'CompleteSchema::VERSION = 3.2.0');
};
$tests['09 temporary probe artifact is absent'] = static function () use ($root): void {
    sameR2(false, file_exists($root.'/docs/push-files-probe.tmp'));
};
$tests['10 package script excludes temporary artifacts'] = static function () use ($read): void {
    $source = $read('scripts/build-package.sh');
    foreach (["! -name '*.tmp'", "! -name '*.log'", "! -name '*.map'", "! -name '.DS_Store'"] as $needle) {
        containsR2($source, $needle);
    }
};
$tests['11 public policy runtime projection is minimal'] = static function (): void {
    $runtime = WordPressRestApi::policy()['runtime'];
    sameR2(['state', 'live_collection_enabled', 'operational_details_redacted'], array_keys($runtime));
};
$tests['12 public policy does not expose provider identity'] = static function (): void {
    $encoded = json_encode(WordPressRestApi::policy(), JSON_THROW_ON_ERROR);
    sameR2(false, str_contains($encoded, 'provider.unconfigured'));
    sameR2(false, str_contains($encoded, 'provider_selected'));
};
$tests['13 public policy does not expose incident evidence'] = static function (): void {
    $policy = WordPressRestApi::policy();
    sameR2(false, array_key_exists('incident', $policy));
    $encoded = json_encode($policy, JSON_THROW_ON_ERROR);
    sameR2(false, str_contains($encoded, 'incident_id'));
    sameR2(false, str_contains($encoded, 'resolution_evidence_ref'));
};
$tests['14 public policy remains fail closed in preparing state'] = static function (): void {
    sameR2(false, WordPressRestApi::policy()['live_collection_enabled']);
};
$tests['15 normal incident state represents available paths'] = static function (): void {
    $state = WordPressIncidentStateStore::normal();
    sameR2(true, $state['checkout_enabled']);
    sameR2(true, $state['refunds_enabled']);
    sameR2(true, $state['webhooks_enabled']);
};
$tests['16 live policy calculation consults incident checkout flag'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressRestApi.php');
    containsR2($source, "(bool)(\$incident['checkout_enabled'] ?? false)");
};
$tests['17 public donation errors use redacted handler'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressRestApi.php');
    sameR2(true, substr_count($source, 'return self::safePublicError($error);') >= 3);
    containsR2($source, 'The financial service is unavailable or the request conflicts with its current safe state.');
};
$tests['18 public donation body is bounded'] = static function (): void {
    $request = new SecondReviewRequest(
        ['amount_minor' => '1000', 'currency' => 'USD', 'monthly' => '0'],
        str_repeat('a', 65537)
    );
    $response = WordPressRestApi::donationPreparing($request);
    sameR2(422, $response['status']);
    containsR2((string)$response['message'], 'size limit');
};
$tests['19 webhook body is bounded before provider processing'] = static function (): void {
    $request = new SecondReviewRequest(['provider' => 'provider.sandbox'], str_repeat('a', 1048577));
    $response = WordPressRestApi::webhook($request);
    sameR2(422, $response['status']);
    containsR2((string)$response['message'], 'one-megabyte limit');
};
$tests['20 webhook header count is bounded'] = static function (): void {
    $headers = [];
    for ($index = 0; $index < 65; $index++) {
        $headers['x-test-'.$index] = 'v';
    }
    $request = new SecondReviewRequest(['provider' => 'provider.sandbox'], '{}', $headers);
    $response = WordPressRestApi::webhook($request);
    sameR2(422, $response['status']);
    containsR2((string)$response['message'], 'too many headers');
};
$tests['21 webhook rejects CRLF header injection'] = static function (): void {
    $request = new SecondReviewRequest(
        ['provider' => 'provider.sandbox'],
        '{}',
        ['x-signature' => "valid\r\ninjected: yes"]
    );
    $response = WordPressRestApi::webhook($request);
    sameR2(422, $response['status']);
    containsR2((string)$response['message'], 'invalid or oversized header value');
};
$tests['22 guest financial reference lifetime is thirty days'] = static function () use ($read): void {
    containsR2($read('src/Infrastructure/WordPressRestApi.php'), 'GUEST_REFERENCE_TTL_SECONDS = 2592000');
};
$tests['23 donation front end locks duplicate submissions'] = static function () use ($read): void {
    $source = $read('assets/js/public.js');
    containsR2($source, "form.dataset.submitting==='1'");
    containsR2($source, "form.dataset.submitting='1'");
    containsR2($source, "form.dataset.submitting='0'");
};
$tests['24 donation front end disables busy submit button'] = static function () use ($read): void {
    $source = $read('assets/js/public.js');
    containsR2($source, 'submit.disabled=true');
    containsR2($source, 'submit.disabled=false');
};
$tests['25 browser sends one idempotency key in header and body'] = static function () use ($read): void {
    $source = $read('assets/js/public.js');
    containsR2($source, "'Idempotency-Key':idempotency");
    containsR2($source, 'idempotency_key:idempotency');
    containsR2($source, "form.elements.idempotency_key.value=idempotency");
};
$tests['26 browser fails closed without secure randomness'] = static function () use ($read): void {
    containsR2($read('assets/js/public.js'), 'globalThis.crypto?.getRandomValues');
};
$tests['27 suggested and custom amounts are mutually exclusive'] = static function () use ($read): void {
    $source = $read('assets/js/public.js');
    containsR2($source, "custom.value=''");
    containsR2($source, 'input.checked=false');
};
$tests['28 shortcode renders approved bilingual copy contract'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressPublicUi.php');
    containsR2($source, 'DonationAppealCopy::contract()');
    containsR2($source, "str_starts_with((string)get_locale(), 'ur')");
};
$tests['29 donation amount controls have fieldset and legend'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressPublicUi.php');
    containsR2($source, '<fieldset class="sabri-cf03-amounts">');
    containsR2($source, '<legend>');
};
$tests['30 donation status is an atomic live region'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressPublicUi.php');
    containsR2($source, 'role="status" aria-live="polite" aria-atomic="true"');
};
$tests['31 visual layer has compatibility border fallback'] = static function () use ($read): void {
    $source = $read('assets/css/public.css');
    $fallback = strpos($source, 'border:1px solid #b9c9bf');
    $enhanced = strpos($source, 'color-mix(');
    sameR2(true, is_int($fallback) && is_int($enhanced) && $fallback < $enhanced);
};
$tests['32 visual layer exposes disabled and reduced-motion states'] = static function () use ($read): void {
    $source = $read('assets/css/public.css');
    containsR2($source, 'button:disabled');
    containsR2($source, '@media(prefers-reduced-motion:reduce)');
};
$tests['33 finance exports retain dedicated capability'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressFinanceAdminApi.php');
    containsR2($source, "['/exports', 'POST', 'requestExport', 'exports']");
    containsR2($source, "'exports' => static fn (): bool => self::cap('sabri_manage_finance_exports')");
};
$tests['34 refund and webhook mutations retain incident guards'] = static function () use ($read): void {
    $source = $read('src/Infrastructure/WordPressRestApi.php');
    sameR2(true, substr_count($source, "assertAvailable('refunds')") >= 3);
    sameR2(true, substr_count($source, "assertAvailable('webhooks')") >= 1);
};
$tests['35 audit metadata still rejects toxic financial keys'] = static function () use ($read): void {
    $source = $read('src/Domain/AuditEnvelope.php');
    foreach (['pan', 'cvv', 'pin', 'otp', 'password', 'raw_body'] as $needle) {
        containsR2(strtolower($source), $needle);
    }
};
$tests['36 File 00 remains entitlement owner and donation is not ranking'] = static function () use ($read): void {
    $source = $read('src/Domain/DonationNeutralityPolicy.php');
    containsR2($source, "'file00_entitlement_owner'=>true");
    containsR2($source, "'file26_ranking_must_ignore_donation'=>true");
};
$tests['37 contracts and migration documents describe active runtime'] = static function () use ($read): void {
    containsR2($read('docs/contracts-and-events.md'), 'durable WordPress repository');
    containsR2($read('docs/database-migration-design.md'), 'implemented additive 31-table schema');
};
$tests['38 ordinary uninstall remains non-destructive'] = static function () use ($read): void {
    $source = $read('uninstall.php');
    containsR2($source, 'Financial and audit data must never be purged by ordinary uninstall.');
    sameR2(false, str_contains($source, 'DROP TABLE'));
};
$tests['39 second review evidence contains exactly forty rounds'] = static function () use ($read): void {
    $source = $read('docs/review-evidence-40-rounds-1.2.0-rc.1.md');
    $count = preg_match_all('/^\| (?:0[1-9]|[1-3][0-9]|40) \|/m', $source);
    sameR2(40, $count);
};
$tests['40 second review is wired for exact post-fix CI'] = static function () use ($read): void {
    $composer = $read('composer.json');
    $ci = $read('.github/workflows/ci.yml');
    containsR2($composer, 'php tests/run-review-40-second.php');
    containsR2($ci, 'php tests/run-review-40-second.php');
    containsR2($ci, 'docs/review-evidence-40-rounds-1.2.0-rc.1.md');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function containsR2(string $haystack, string $needle): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Missing expected text: '.$needle);
    }
}

function sameR2(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}
