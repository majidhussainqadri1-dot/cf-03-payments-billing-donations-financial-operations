<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\WebhookIngestionService;
use Sabri\CF03\Contracts\PaymentProvider;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Application\DonationCheckoutService;

final class QuarantineRecoveryProvider implements PaymentProvider
{
    public function __construct(private readonly ProviderEvidence $evidence) {}
    public function providerId(): string { return 'provider.test'; }
    public function currencies(): array { return ['USD']; }
    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference { throw new LogicException('not used'); }
    public function verifyWebhook(string $rawBody, array $headers, int $receivedAt): ProviderEvidence { return $this->evidence; }
    public function refund(string $providerPaymentReference, Money $amount, string $idempotencyKey): string { throw new LogicException('not used'); }
    public function settlements(string $fromDate, string $toDate): iterable { return []; }
    public function health(): string { return 'healthy'; }
}

$now = new DateTimeImmutable('2026-09-16T08:30:00+05:00');
$rawBody = '{"event":"payment.settled"}';
$intentId = 'intent:quarantine:recovery:001';
$eventId = 'event:provider:recovery:001';
$amount = new Money(1000, 'USD');
$evidence = new ProviderEvidence(
    'provider.test',
    $eventId,
    'payment.settled',
    $intentId,
    $amount,
    'key:v1',
    $now,
    $now,
    hash('sha256', $rawBody),
    true,
    true,
    $now
);

$gates = [];
foreach ([
    'founder_change_control', 'legal_tax_accounting', 'pci_scope', 'provider_selected',
    'independent_security', 'staging_acceptance', 'rollback_evidence', 'file00_contract',
    'file20_file25_contract', 'file24_assurance', 'operations_ready', 'webhook_endpoint',
] as $gate) {
    $gates[$gate] = true;
}
$config = new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', $gates, true, false);
$repo = new MemoryFinancialRepository();
$service = new WebhookIngestionService($config, new ProviderRegistry([new QuarantineRecoveryProvider($evidence)]), $repo);

$first = $service->ingest('provider.test', $rawBody, [], $now->getTimestamp());
assertSameValue('quarantined', $first['status']);
assertSameValue('missing_intent', $first['reason']);
assertSameValue('quarantined_missing_intent', $repo->get('provider_events', $eventId)['status']);

$repo->insert('intents', $intentId, [
    'intent_id' => $intentId,
    'actor_ref' => 'user:42',
    'product_id' => 'donation.one_time',
    'price_version_id' => null,
    'amount_minor' => 1000,
    'currency' => 'USD',
    'provider' => 'provider.test',
    'provider_ref' => 'session:test:001',
    'state' => 'provider_pending',
    'failure_code' => null,
    'idempotency_key' => 'idem:test:quarantine:recovery:001',
    'request_hash' => str_repeat('a', 64),
    'expires_at' => $now->modify('+1 hour'),
    'record_version' => 1,
    'trace_id' => 'trace:test:quarantine:recovery:001',
    'created_at' => $now->modify('-1 minute'),
    'updated_at' => $now->modify('-1 minute'),
]);
$donationId = DonationCheckoutService::donationIdForIntent($intentId);
$repo->insert('donations', $donationId, [
    'donation_id' => $donationId,
    'donor_ref' => 'user:42',
    'amount_minor' => 1000,
    'currency' => 'USD',
    'purpose_code' => 'institutional_sustainability_and_homeopathy_advancement',
    'recurring' => false,
    'recurring_consent_id' => null,
    'provider_ref' => 'session:test:001',
    'receipt_ref' => null,
    'state' => 'provider_pending',
    'anonymous_public' => true,
    'record_version' => 1,
    'created_at' => $now->modify('-1 minute'),
    'updated_at' => $now->modify('-1 minute'),
]);

$second = $service->ingest('provider.test', $rawBody, [], $now->getTimestamp());
assertSameValue('processed', $second['status']);
assertSameValue('settled', $repo->get('intents', $intentId)['state']);
assertSameValue('settled', $repo->get('donations', $donationId)['state']);
assertSameValue('processed', $repo->get('provider_events', $eventId)['status']);
assertSameValue(2, count($repo->all('ledger_entries')));
assertSameValue(1, count($repo->all('invoices')));

$third = $service->ingest('provider.test', $rawBody, [], $now->getTimestamp());
assertSameValue('duplicate_acknowledged', $third['status']);
assertSameValue(2, count($repo->all('ledger_entries')));
assertSameValue(1, count($repo->all('invoices')));

fwrite(STDOUT, "PASS: quarantined missing-intent webhook safely recovers exactly once\n");

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(var_export($expected, true).' !== '.var_export($actual, true));
    }
}
