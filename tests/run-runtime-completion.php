<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\BillingQueryService;
use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\DonationCheckoutService;
use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\DonationManagementService;
use Sabri\CF03\Application\DonationProviderRegistry;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\WebhookIngestionService;
use Sabri\CF03\Contracts\PaymentProvider;
use Sabri\CF03\Contracts\RecurringDonationProvider;
use Sabri\CF03\Domain\DonationNeutralityPolicy;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FinancialDueProcessPolicy;
use Sabri\CF03\Domain\FinancialOutcomeProjection;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PaymentIntentTransition;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class RuntimeFakeProvider implements PaymentProvider, RecurringDonationProvider
{
    /** @var array<string,HostedCheckoutReference> */
    private array $sessions = [];

    public function providerId(): string { return 'provider.sandbox'; }
    public function providerCode(): string { return 'provider.sandbox'; }
    public function currencies(): array { return ['USD']; }
    public function health(): string { return 'healthy'; }

    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference
    {
        return $this->hosted($command->paymentIntentId(), new DateTimeImmutable('2026-08-05T16:00:00+05:00'));
    }

    public function createHostedDonationCheckout(DonationIntentDraft $intent): HostedCheckoutReference
    {
        return $this->hosted($intent->intentId(), $intent->createdAt());
    }

    public function resumeHostedDonationCheckout(string $providerSessionReference): HostedCheckoutReference
    {
        if (!isset($this->sessions[$providerSessionReference])) {
            throw new InvariantViolation('Unknown provider session.');
        }
        return $this->sessions[$providerSessionReference];
    }

    public function verifyWebhook(string $rawBody, array $headers, int $receivedAt): ProviderEvidence
    {
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $received = (new DateTimeImmutable('@'.$receivedAt))->setTimezone(new DateTimeZone('UTC'));
        $signed = (new DateTimeImmutable('@'.(int)$data['signed_at']))->setTimezone(new DateTimeZone('UTC'));
        return new ProviderEvidence(
            'provider.sandbox',
            (string)$data['event_id'],
            (string)$data['event_type'],
            (string)$data['intent_id'],
            new Money((int)$data['amount_minor'], (string)$data['currency']),
            'key:v1',
            $signed,
            $received,
            hash('sha256', $rawBody),
            ($headers['x-signature'] ?? '') === 'valid',
            true,
            $signed
        );
    }

    public function queryDonationEvidence(string $providerPaymentReference): ProviderEvidence
    {
        throw new InvariantViolation('Query not needed in runtime fixture.');
    }

    public function refund(string $providerPaymentReference, Money $amount, string $idempotencyKey): string
    {
        return 'provider-refund.'.substr(hash('sha256', $providerPaymentReference.'|'.$amount->minorUnits().'|'.$idempotencyKey), 0, 24);
    }

    public function settlements(string $fromDate, string $toDate): iterable
    {
        if (false) { yield []; }
    }

    public function cancelRecurringDonation(string $providerReference, string $idempotencyKey): string
    {
        return 'cancel.'.substr(hash('sha256', $providerReference.'|'.$idempotencyKey), 0, 24);
    }

    public function changeRecurringDonationAmount(string $providerReference, Money $amount, string $idempotencyKey): string
    {
        return 'change.'.substr(hash('sha256', $providerReference.'|'.$amount->minorUnits().'|'.$idempotencyKey), 0, 24);
    }

    private function hosted(string $intentId, DateTimeImmutable $issued): HostedCheckoutReference
    {
        $session = 'session.'.substr(hash('sha256', $intentId), 0, 32);
        $reference = new HostedCheckoutReference(
            'provider.sandbox',
            $session,
            'https://checkout.example.test/session/'.$session,
            $issued,
            $issued->modify('+30 minutes'),
            ['checkout.example.test']
        );
        $this->sessions[$session] = $reference;
        return $reference;
    }
}

final class RuntimeCaptureTransport implements OutboxTransport
{
    /** @var array<string,array{0:string,1:array<string,mixed>}> */
    public array $events = [];

    public function publish(string $eventId, string $eventType, array $payload): void
    {
        $this->events[$eventId] = [$eventType, $payload];
    }
}

final class RuntimeFailingTransport implements OutboxTransport
{
    public function publish(string $eventId, string $eventType, array $payload): void
    {
        throw new RuntimeException('transport down');
    }
}

$now = new DateTimeImmutable('2026-08-05T16:00:00+05:00');
$gates = [
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
];
$runtime = new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.sandbox', $gates, true, false);
$provider = new RuntimeFakeProvider();
$repo = new MemoryFinancialRepository();
$donations = new DonationProviderRegistry([$provider]);
$payments = new ProviderRegistry([$provider]);
$checkout = new DonationCheckoutService($runtime, $donations, $repo);
$context = [];
$tests = [];

$tests['01 preparing runtime fails closed'] = static fn () => throwsRuntime(
    static fn () => RuntimeConfiguration::preparing()->assertFinancialMutationReady(),
    InvariantViolation::class
);
$tests['02 accepted sandbox fixture opens source path'] = static function () use ($runtime): void {
    $runtime->assertFinancialMutationReady();
    sameRuntime([], $runtime->missingFinancialGates());
};
$tests['03 one-time hosted checkout persists canonical records'] = static function () use ($checkout, $repo, $now): void {
    $draft = new DonationIntentDraft('intent:runtime:1', 'user:1001', new Money(1400, 'USD'), false, false, DonationServiceState::SANDBOX, 'idem-runtime-one-0001', $now);
    $result = $checkout->create($draft, 'provider.sandbox');
    sameRuntime(false, $result['reused']);
    sameRuntime('provider_pending', $repo->get('intents', 'intent:runtime:1')['state']);
};
$tests['04 idempotent replay resumes hosted checkout'] = static function () use ($checkout, $now): void {
    $draft = new DonationIntentDraft('intent:runtime:1', 'user:1001', new Money(1400, 'USD'), false, false, DonationServiceState::SANDBOX, 'idem-runtime-one-0001', $now);
    $result = $checkout->create($draft, 'provider.sandbox');
    sameRuntime(true, $result['reused']);
    sameRuntime(true, str_starts_with((string)$result['hosted_url'], 'https://checkout.example.test/'));
};
$tests['05 changed replay is rejected'] = static function () use ($checkout, $now): void {
    $draft = new DonationIntentDraft('intent:runtime:1', 'user:1001', new Money(1500, 'USD'), false, false, DonationServiceState::SANDBOX, 'idem-runtime-one-0001', $now);
    throwsRuntime(static fn () => $checkout->create($draft, 'provider.sandbox'), InvariantViolation::class);
};
$tests['06 monthly consent remains pending until trusted settlement'] = static function () use ($checkout, $repo, $now): void {
    $draft = new DonationIntentDraft('intent:runtime:2', 'user:1001', new Money(1000, 'USD'), true, true, DonationServiceState::SANDBOX, 'idem-runtime-monthly-01', $now);
    $checkout->create($draft, 'provider.sandbox');
    $consent = $repo->get('recurring_consents', DonationCheckoutService::consentIdForIntent('intent:runtime:2'));
    sameRuntime('pending_provider', $consent['state']);
};
$tests['07 trusted webhook settles one-time donation'] = static function () use ($runtime, $payments, $repo, $now): void {
    $body = webhookRuntime('event:runtime:settle1', 'payment.settled', 'intent:runtime:1', 1400, $now);
    $result = (new WebhookIngestionService($runtime, $payments, $repo))->ingest('provider.sandbox', $body, ['x-signature' => 'valid'], $now->getTimestamp());
    sameRuntime('settled', $result['mapped_state']);
};
$tests['08 trusted webhook activates monthly consent'] = static function () use ($runtime, $payments, $repo, $now): void {
    $body = webhookRuntime('event:runtime:settle2', 'payment.settled', 'intent:runtime:2', 1000, $now);
    (new WebhookIngestionService($runtime, $payments, $repo))->ingest('provider.sandbox', $body, ['x-signature' => 'valid'], $now->getTimestamp());
    $consent = $repo->get('recurring_consents', DonationCheckoutService::consentIdForIntent('intent:runtime:2'));
    sameRuntime('active', $consent['state']);
};
$tests['09 settlement ledger remains balanced'] = static function () use ($repo): void {
    [$debit, $credit] = ledgerTotalsRuntime($repo);
    sameRuntime($debit, $credit);
    sameRuntime(2400, $debit);
};
$tests['10 immutable receipt snapshot is issued'] = static function () use ($repo): void {
    $invoices = $repo->find('invoices', ['actor_ref' => 'user:1001'], 10);
    sameRuntime(2, count($invoices));
    sameRuntime(true, preg_match('/^[a-f0-9]{64}$/', (string)$invoices[0]['snapshot_hash']) === 1);
};
$tests['11 donation state follows trusted settlement'] = static function () use ($repo): void {
    $donation = $repo->get('donations', DonationCheckoutService::donationIdForIntent('intent:runtime:1'));
    sameRuntime('settled', $donation['state']);
    sameRuntime(true, is_string($donation['receipt_ref']));
};
$tests['12 settlement creates durable outbox facts'] = static function () use ($repo): void {
    sameRuntime(2, count($repo->find('outbox', ['state' => 'pending'], 20)));
};
$tests['13 duplicate webhook is acknowledged without duplicate ledger'] = static function () use ($runtime, $payments, $repo, $now): void {
    $before = count($repo->all('ledger_transactions'));
    $body = webhookRuntime('event:runtime:settle1', 'payment.settled', 'intent:runtime:1', 1400, $now);
    $result = (new WebhookIngestionService($runtime, $payments, $repo))->ingest('provider.sandbox', $body, ['x-signature' => 'valid'], $now->getTimestamp());
    sameRuntime('duplicate_acknowledged', $result['status']);
    sameRuntime($before, count($repo->all('ledger_transactions')));
};
$tests['14 raw webhook body is never persisted'] = static function () use ($repo): void {
    $event = $repo->get('provider_events', 'event:runtime:settle1');
    sameRuntime(false, array_key_exists('raw_body', $event));
    sameRuntime(64, strlen((string)$event['raw_body_hash']));
};
$tests['15 billing projection excludes provider references'] = static function () use ($repo): void {
    $billing = (new BillingQueryService($repo))->forActor('user:1001');
    sameRuntime(false, str_contains((string)json_encode($billing), 'provider_ref'));
};
$tests['16 billing projection is actor scoped'] = static function () use ($repo): void {
    $billing = (new BillingQueryService($repo))->forActor('user:other');
    sameRuntime(0, $billing['counts']['donations']);
};
$tests['17 refund request enforces owner and refundable balance'] = static function () use ($repo, $payments, $runtime, $now, &$context): void {
    $service = new RefundWorkflowService($repo, $payments, $runtime);
    $context['refund_service'] = $service;
    $request = $service->request('refund:runtime:1', 'intent:runtime:1', 'user:1001', new Money(500, 'USD'), 'duplicate_payment', $now);
    sameRuntime('requested', $request['state']);
};
$tests['18 refund requester cannot self-review'] = static function () use (&$context): void {
    throwsRuntime(
        static fn () => $context['refund_service']->review('refund:runtime:1', 'user:1001', true, 'eligible', 1, new DateTimeImmutable('2026-08-05T16:01:00+05:00')),
        InvariantViolation::class
    );
};
$tests['19 refund review and execution require distinct actors'] = static function () use (&$context): void {
    $service = $context['refund_service'];
    $review = $service->review('refund:runtime:1', 'user:2002', true, 'eligible', 1, new DateTimeImmutable('2026-08-05T16:01:00+05:00'));
    sameRuntime(2, $review['version']);
    $execute = $service->execute('refund:runtime:1', 'user:3003', 2, 'idem-refund-runtime-01', new DateTimeImmutable('2026-08-05T16:02:00+05:00'));
    sameRuntime('provider_pending', $execute['state']);
    sameRuntime(3, $execute['version']);
};
$tests['20 trusted refund webhook closes refund without mutating settled intent'] = static function () use ($runtime, $payments, $repo, $now): void {
    $body = webhookRuntime('event:runtime:refund1', 'payment.refunded', 'intent:runtime:1', 500, $now->modify('+3 minutes'));
    $result = (new WebhookIngestionService($runtime, $payments, $repo))->ingest('provider.sandbox', $body, ['x-signature' => 'valid'], $now->modify('+3 minutes')->getTimestamp());
    sameRuntime('refunded', $result['mapped_state']);
    sameRuntime(true, $result['payment_intent_state_unchanged']);
    sameRuntime('settled', $repo->get('intents', 'intent:runtime:1')['state']);
    sameRuntime('closed', $repo->get('refunds', 'refund:runtime:1')['state']);
    sameRuntime('partially_refunded', $repo->get('donations', DonationCheckoutService::donationIdForIntent('intent:runtime:1'))['state']);
};
$tests['21 refund reversal ledger remains balanced'] = static function () use ($repo): void {
    [$debit, $credit] = ledgerTotalsRuntime($repo);
    sameRuntime($debit, $credit);
    sameRuntime(2900, $debit);
    sameRuntime(3, count($repo->all('ledger_transactions')));
};
$tests['22 generic settled to refunded transition remains prohibited'] = static fn () => throwsRuntime(
    static fn () => (new PaymentIntentTransition())->assertAllowed(PaymentIntentState::SETTLED, PaymentIntentState::REFUNDED),
    InvariantViolation::class
);
$tests['23 recurring amount change is provider confirmed and versioned'] = static function () use ($repo, $donations, $runtime): void {
    $service = new DonationManagementService($repo, $donations, $runtime);
    $id = DonationCheckoutService::consentIdForIntent('intent:runtime:2');
    $result = $service->changeAmount($id, 'user:1001', new Money(1400, 'USD'), 'idem-change-runtime-01', 2, new DateTimeImmutable('2026-08-05T16:04:00+05:00'));
    sameRuntime(1400, $result['amount_minor']);
    sameRuntime(3, $result['version']);
};
$tests['24 recurring cancellation is easy and explicit'] = static function () use ($repo, $donations, $runtime): void {
    $service = new DonationManagementService($repo, $donations, $runtime);
    $id = DonationCheckoutService::consentIdForIntent('intent:runtime:2');
    $result = $service->cancel($id, 'user:1001', 'idem-cancel-runtime-01', 3, new DateTimeImmutable('2026-08-05T16:05:00+05:00'));
    sameRuntime('cancelled', $result['state']);
    sameRuntime(4, $result['version']);
};
$tests['25 outbox dispatcher delivers financial facts idempotently'] = static function () use ($repo, $now): void {
    $transport = new RuntimeCaptureTransport();
    $result = (new OutboxDispatcher($repo, $transport))->dispatch($now->modify('+10 minutes'), 20);
    sameRuntime(3, $result['delivered']);
    sameRuntime(3, count($transport->events));
};
$tests['26 outbox failure enters bounded retry'] = static function () use ($now): void {
    $local = new MemoryFinancialRepository();
    $local->insert('outbox', 'event:failure:1', [
        'event_id' => 'event:failure:1', 'event_type' => 'TestEvent', 'aggregate_id' => 'agg:1',
        'aggregate_version' => '1', 'schema_version' => '1.0', 'trace_id' => 'trace:1',
        'payload_json' => ['ok' => true], 'payload_hash' => str_repeat('a', 64), 'state' => 'pending',
        'attempts' => 0, 'available_at' => $now, 'leased_until' => null, 'last_error_code' => null,
        'created_at' => $now, 'delivered_at' => null,
    ]);
    $result = (new OutboxDispatcher($local, new RuntimeFailingTransport(), 3))->dispatch($now, 10);
    sameRuntime(1, $result['retried']);
    sameRuntime('retry', $local->get('outbox', 'event:failure:1')['state']);
};
$tests['27 donation neutrality contract preserves equal capabilities'] = static function (): void {
    $contract = (new DonationNeutralityPolicy())->publicContract();
    sameRuntime(true, $contract['donor_and_non_donor_core_capabilities_equal']);
    sameRuntime(true, $contract['file26_ranking_must_ignore_donation']);
};
$tests['28 donation privilege projection is rejected'] = static fn () => throwsRuntime(
    static fn () => (new DonationNeutralityPolicy())->assertNeutralProjection(['ranking_boost' => 10]),
    InvariantViolation::class
);
$tests['29 private financial outcome cannot enter File 26 index'] = static function () use ($now): void {
    $projection = (new FinancialOutcomeProjection('donation_settled', 'settled', 'donation:private:1', $now, false, new Money(1400, 'USD')))->toFile26Projection();
    sameRuntime(false, $projection['indexable']);
    sameRuntime(null, $projection['canonical_reference']);
};
$tests['30 public outcome is aggregate and never recommendable'] = static function () use ($now): void {
    $projection = (new FinancialOutcomeProjection('transparency_snapshot_published', 'published', 'snapshot:2026-08', $now, true, new Money(2400, 'USD')))->toFile26Projection();
    sameRuntime(true, $projection['indexable']);
    sameRuntime(false, $projection['recommendable']);
    sameRuntime(false, $projection['contains_donor_identity']);
};
$tests['31 financial due process requires notice evidence and appeal law'] = static function () use ($now): void {
    (new FinancialDueProcessPolicy())->assertDecisionRecord('decision:1', 'refund:runtime:1', 'policy_not_eligible', ['evidence:1'], 'user:2002', 'user:1001', $now, true, $now->modify('+30 days'));
};
$tests['32 conflicted financial reviewer is rejected'] = static fn () => throwsRuntime(
    static fn () => (new FinancialDueProcessPolicy())->assertDecisionRecord('decision:1', 'refund:1', 'reason_code', ['evidence:1'], 'user:1', 'user:1', new DateTimeImmutable('2026-08-05T16:00:00+05:00'), false, null),
    InvariantViolation::class
);
$tests['33 repository transaction rolls back atomically'] = static function (): void {
    $local = new MemoryFinancialRepository();
    try {
        $local->transaction(static function () use ($local): void {
            $local->insert('donations', 'donation:rollback', ['donation_id' => 'donation:rollback']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {}
    sameRuntime(null, $local->get('donations', 'donation:rollback'));
};
$tests['34 WordPress durable repository implements query contract'] = static function (): void {
    sameRuntime(true, is_subclass_of(WordPressFinancialRepository::class, \Sabri\CF03\Contracts\QueryableFinancialRepository::class));
};
$tests['35 over-refund provider event is rejected and rolled back'] = static function () use ($runtime, $payments, $repo, $now): void {
    $before = count($repo->all('ledger_transactions'));
    $body = webhookRuntime('event:runtime:overrefund', 'payment.refunded', 'intent:runtime:1', 1000, $now->modify('+6 minutes'));
    throwsRuntime(
        static fn () => (new WebhookIngestionService($runtime, $payments, $repo))->ingest('provider.sandbox', $body, ['x-signature' => 'valid'], $now->modify('+6 minutes')->getTimestamp()),
        InvariantViolation::class
    );
    sameRuntime($before, count($repo->all('ledger_transactions')));
    sameRuntime(null, $repo->get('provider_events', 'event:runtime:overrefund'));
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

function webhookRuntime(string $eventId, string $type, string $intentId, int $amount, DateTimeImmutable $at): string
{
    return json_encode([
        'event_id' => $eventId,
        'event_type' => $type,
        'intent_id' => $intentId,
        'amount_minor' => $amount,
        'currency' => 'USD',
        'signed_at' => $at->getTimestamp(),
    ], JSON_THROW_ON_ERROR);
}

/** @return array{0:int,1:int} */
function ledgerTotalsRuntime(MemoryFinancialRepository $repo): array
{
    $debit = 0;
    $credit = 0;
    foreach ($repo->all('ledger_entries') as $entry) {
        if ($entry['direction'] === 'debit') { $debit += (int)$entry['amount_minor']; }
        if ($entry['direction'] === 'credit') { $credit += (int)$entry['amount_minor']; }
    }
    return [$debit, $credit];
}

function sameRuntime(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

/** @param class-string<Throwable> $class */
function throwsRuntime(callable $callback, string $class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());
    }
    throw new RuntimeException('Expected '.$class);
}
