<?php

declare(strict_types=1);

$GLOBALS['cf03_test_caps'] = [];
$GLOBALS['cf03_test_recent_auth'] = false;
$GLOBALS['cf03_test_user_id'] = 101;

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool)($GLOBALS['cf03_test_caps'][$capability] ?? false);
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int)($GLOBALS['cf03_test_user_id'] ?? 0);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if ($hook === 'sabri_cf03_recent_auth_approved') {
            return (bool)($GLOBALS['cf03_test_recent_auth'] ?? false);
        }
        return $value;
    }
}

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\CanonicalSettlementImportService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\FinancialControlRequestService;
use Sabri\CF03\Application\IncidentOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressRequestGuard;
use Sabri\CF03\Infrastructure\WordPressSensitiveActionGuard;
use Sabri\CF03\Support\InvariantViolation;

final class TestIncidentStore9 implements IncidentStateStore
{
    /** @param array<string,mixed> $state */
    public function __construct(private array $state) {}
    public function get(): array { return $this->state; }
    public function save(array $state): void { $this->state = $state; }
}

final class TestRestRequest9
{
    public function __construct(
        private readonly string $route,
        private readonly string $method,
        private readonly string $body = ''
    ) {}
    public function get_route(): string { return $this->route; }
    public function get_method(): string { return $this->method; }
    public function get_body(): string { return $this->body; }
}

$tests = [];

$tests['period reopen uses persisted authenticated requester and two distinct actors'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $repo->insert('finance_periods', '2026-08', [
        'period_id' => '2026-08',
        'state' => 'locked',
        'reviewed_by' => 'user:77',
        'reviewed_at' => new DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        'approved_by' => 'user:88',
        'accepted_risk_ref' => null,
        'closed_at' => new DateTimeImmutable('2026-09-02T10:00:00+00:00'),
        'record_version' => 1,
    ]);
    $audit = new FinancialAuditService($repo);
    $store = new TestIncidentStore9(normalIncident9());
    $runtime = fullRuntime9();
    $incidents = new IncidentOperationsService($store, $audit, $runtime);
    $controls = new FinancialControlRequestService($repo, $audit, $store, $incidents);
    $requestedAt = new DateTimeImmutable('2026-09-16T05:00:00+00:00');

    $controls->requestPeriodReopen('2026-08', 'user:101', 'reason.controlled-reopen', $requestedAt);
    expectInvariant9(static fn () => $controls->approvePeriodReopen(
        '2026-08', 'user:101', $requestedAt->modify('+5 minutes')
    ));

    $approved = $controls->approvePeriodReopen('2026-08', 'user:202', $requestedAt->modify('+6 minutes'));
    assertSame9('exception_review', $approved['state'] ?? null, 'period state');
    assertSame9('user:101', $approved['requester_ref'] ?? null, 'persisted requester');
    assertSame9('user:202', $approved['approver_ref'] ?? null, 'authenticated approver');
    $actions = array_column($repo->all('audit'), 'action');
    assertTrue9(in_array('finance_period_reopen_requested', $actions, true), 'reopen request audit missing');
    assertTrue9(in_array('finance_period_reopened', $actions, true), 'reopen approval audit missing');
};

$tests['incident recovery proposal is persisted and cannot be approved by requester or silently changed'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $audit = new FinancialAuditService($repo);
    $declaredAt = new DateTimeImmutable('2026-09-16T04:00:00+00:00');
    $state = array_replace(normalIncident9(), [
        'state' => 'contained',
        'incident_id' => 'incident.900',
        'severity' => 2,
        'reason_code' => 'provider_outage',
        'checkout_enabled' => false,
        'refunds_enabled' => false,
        'webhooks_enabled' => false,
        'declared_at' => $declaredAt->format(DATE_ATOM),
        'declared_by' => 'user:900',
        'record_version' => 2,
    ]);
    $store = new TestIncidentStore9($state);
    $runtime = fullRuntime9();
    $incidents = new IncidentOperationsService($store, $audit, $runtime);
    $controls = new FinancialControlRequestService($repo, $audit, $store, $incidents);
    $requestedAt = $declaredAt->modify('+30 minutes');

    $controls->requestIncidentRecovery(
        'incident.900', 'user:101', 'evidence.recovery.900', $requestedAt, true, true, true
    );
    expectInvariant9(static fn () => $controls->approveIncidentRecovery(
        'incident.900', 'user:101', 'evidence.recovery.900', $requestedAt->modify('+5 minutes'), true, true, true
    ));
    expectInvariant9(static fn () => $controls->approveIncidentRecovery(
        'incident.900', 'user:202', 'evidence.recovery.900', $requestedAt->modify('+6 minutes'), true, false, true
    ));

    $recovered = $controls->approveIncidentRecovery(
        'incident.900', 'user:202', 'evidence.recovery.900', $requestedAt->modify('+7 minutes'), true, true, true
    );
    assertSame9('recovered', $recovered['state'] ?? null, 'incident state');
    assertSame9('user:101', $recovered['requester_ref'] ?? null, 'incident requester');
    assertSame9('user:202', $recovered['approver_ref'] ?? null, 'incident approver');
};

$tests['manual settlement import trusts canonical records rather than caller-provided internal truth'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $audit = new FinancialAuditService($repo);
    $runtime = fullRuntime9();
    $settlements = new SettlementOperationsService($repo, $runtime, $audit);
    $canonical = new CanonicalSettlementImportService($repo, $runtime, $settlements);
    $at = new DateTimeImmutable('2026-09-15T00:00:00+00:00');

    $foreign = settlement9('batch.foreign', 'provider.other', 'provider.ref.foreign', 100, $at);
    expectInvariant9(static fn () => $canonical->import($foreign, ['USD' => 0], 'user:303', $at));

    $repo->insert('intents', 'intent.canonical', [
        'intent_id' => 'intent.canonical',
        'actor_ref' => 'user:500',
        'product_id' => 'donation.one_time',
        'amount_minor' => 80,
        'currency' => 'USD',
        'provider' => 'provider.test',
        'provider_ref' => 'provider.ref.canonical',
        'state' => 'settled',
        'record_version' => 1,
    ]);
    $batch = settlement9('batch.canonical', 'provider.test', 'provider.ref.canonical', 100, $at);
    $internal = $canonical->canonicalInternalLines($batch);
    assertSame9(1, count($internal), 'canonical line count');
    assertSame9(80, $internal[0]['amount_minor'] ?? null, 'canonical amount must come from local intent');
};

$tests['privileged route guard requires recent authentication and rejects toxic capability combinations'] = static function (): void {
    $GLOBALS['cf03_test_user_id'] = 101;
    $GLOBALS['cf03_test_caps'] = ['sabri_manage_finance_exports' => true];
    $GLOBALS['cf03_test_recent_auth'] = false;
    assertSame9(false, WordPressSensitiveActionGuard::can('sabri_manage_finance_exports', 'finance_export_request'), 'recent-auth fail closed');

    $GLOBALS['cf03_test_recent_auth'] = true;
    assertSame9(true, WordPressSensitiveActionGuard::can('sabri_manage_finance_exports', 'finance_export_request'), 'recent-auth approved');

    $GLOBALS['cf03_test_caps'] = [
        'sabri_review_refunds' => true,
        'sabri_execute_refunds' => true,
    ];
    assertSame9(false, WordPressSensitiveActionGuard::can('sabri_review_refunds', 'refund_review'), 'toxic capability pair');
};

$tests['request guard step-up protects legacy privileged refund and diagnostics routes'] = static function (): void {
    $GLOBALS['cf03_test_user_id'] = 101;
    $GLOBALS['cf03_test_caps'] = ['sabri_review_refunds' => true];
    $GLOBALS['cf03_test_recent_auth'] = false;
    $denied = WordPressRequestGuard::guard(
        null,
        null,
        new TestRestRequest9('/sabri-finance/v1/admin/refunds/refund.100', 'POST', '{}')
    );
    assertSame9(403, is_array($denied) ? ($denied['status'] ?? null) : null, 'refund recent-auth denial');

    $GLOBALS['cf03_test_recent_auth'] = true;
    $allowed = WordPressRequestGuard::guard(
        null,
        null,
        new TestRestRequest9('/sabri-finance/v1/admin/refunds/refund.100', 'POST', '{}')
    );
    assertSame9(null, $allowed, 'refund recent-auth approval');

    $GLOBALS['cf03_test_caps'] = ['sabri_manage_finance' => true];
    $GLOBALS['cf03_test_recent_auth'] = false;
    $healthDenied = WordPressRequestGuard::guard(
        null,
        null,
        new TestRestRequest9('/sabri-finance/v1/admin/health', 'GET')
    );
    assertSame9(403, is_array($healthDenied) ? ($healthDenied['status'] ?? null) : null, 'admin health recent-auth denial');
};

$tests['financial dashboard and cross-owner documents require audit step-up'] = static function (): void {
    $dashboard = file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinancialDashboardApi.php');
    $documents = file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinancialDocumentApi.php');
    if (!is_string($dashboard) || !is_string($documents)) {
        throw new RuntimeException('Financial endpoint source unavailable.');
    }
    assertTrue9(str_contains($dashboard, 'WordPressSensitiveActionGuard::can'), 'dashboard must use step-up guard');
    assertTrue9(str_contains($dashboard, 'finance_dashboard_read'), 'dashboard step-up purpose missing');
    assertTrue9(str_contains($documents, "'sabri_view_finance_audit'"), 'document override requires audit capability');
    assertTrue9(str_contains($documents, 'financial_document_override'), 'document override step-up purpose missing');
    assertSame9(false, str_contains($documents, "current_user_can('sabri_manage_finance')"), 'broad finance capability must not override document ownership');
};

$tests['admin source removes requester spoof and client internal reconciliation inputs'] = static function (): void {
    $source = file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinanceAdminApi.php');
    if (!is_string($source)) { throw new RuntimeException('Admin API source unavailable.'); }
    assertSame9(false, str_contains($source, "param(\$request,'requester_reference')"), 'requester reference must not come from body');
    assertSame9(false, str_contains($source, "param(\$request,'internal_lines')"), 'internal reconciliation lines must not come from body');
    assertTrue9(str_contains($source, '/reopen-request'), 'period reopen request route missing');
    assertTrue9(str_contains($source, '/recover-request'), 'incident recovery request route missing');
};

$tests['operations dashboard never queries retired subscription collection'] = static function (): void {
    $source = file_get_contents(dirname(__DIR__).'/src/Application/FinancialOperationsDashboard.php');
    if (!is_string($source)) { throw new RuntimeException('Dashboard source unavailable.'); }
    assertSame9(false, str_contains($source, "stateCounts('subscriptions'"), 'retired subscriptions collection queried');
    assertTrue9(str_contains($source, "'subscriptions' => true"), 'retirement state not exposed');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, count($tests)." tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);

function fullRuntime9(): RuntimeConfiguration
{
    return new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', [
        'founder_change_control' => true,
        'legal_tax_accounting' => true,
        'pci_scope' => true,
        'provider_selected' => true,
        'independent_security' => true,
        'staging_acceptance' => true,
        'rollback_evidence' => true,
        'file00_contract' => true,
        'file20_file25_contract' => true,
        'file24_assurance' => true,
        'operations_ready' => true,
        'webhook_endpoint' => true,
        'secure_delivery' => true,
    ], true, true);
}

function settlement9(string $id, string $provider, string $reference, int $amount, DateTimeImmutable $at): SettlementBatch
{
    return new SettlementBatch(
        $id,
        $provider,
        new Money($amount, 'USD'),
        Money::zero('USD'),
        Money::zero('USD'),
        new Money($amount, 'USD'),
        $at,
        str_repeat('a', 64),
        [['reference' => $reference, 'type' => 'payment', 'amount_minor' => $amount, 'currency' => 'USD']]
    );
}

/** @return array<string,mixed> */
function normalIncident9(): array
{
    return [
        'state' => 'normal', 'incident_id' => null, 'severity' => null, 'reason_code' => null,
        'checkout_enabled' => true, 'refunds_enabled' => true, 'webhooks_enabled' => true,
        'declared_at' => null, 'declared_by' => null, 'recovered_at' => null, 'recovered_by' => null,
        'resolution_evidence_ref' => null, 'record_version' => 1,
    ];
}

function expectInvariant9(callable $operation): void
{
    try { $operation(); }
    catch (InvariantViolation) { return; }
    throw new RuntimeException('Expected InvariantViolation.');
}

function assertSame9(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label.' mismatch: expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

function assertTrue9(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
