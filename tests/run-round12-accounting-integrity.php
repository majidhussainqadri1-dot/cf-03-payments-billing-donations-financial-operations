<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Application\PaymentConfirmationService;
use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\WebhookIngestionService;
use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Contracts\PaymentProvider;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\FinancialReceiptIdentity;
use Sabri\CF03\Domain\LedgerEntry;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntent;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class Round12Provider implements PaymentProvider
{
    public function __construct(private readonly ?ProviderEvidence $evidence = null) {}
    public function providerId(): string { return 'provider.test'; }
    public function currencies(): array { return ['USD']; }
    public function createHostedCheckout(CheckoutCommand $command): HostedCheckoutReference { throw new LogicException('not used'); }
    public function verifyWebhook(string $rawBody, array $headers, int $receivedAt): ProviderEvidence
    {
        if ($this->evidence === null) { throw new LogicException('no evidence'); }
        return $this->evidence;
    }
    public function refund(string $providerPaymentReference, Money $amount, string $idempotencyKey): string
    {
        return 'provider.refund.round12';
    }
    public function settlements(string $fromDate, string $toDate): iterable { return []; }
    public function health(): string { return 'healthy'; }
}

$tests = [];

$tests['refund reservation scans beyond five hundred rows before computing balance'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T10:00:00+00:00');
    insertIntent12($repo, 'intent.refund.501', 501, 'user:501', 'settled', $now);
    for ($i = 1; $i <= 501; $i++) {
        $id = 'refund.prior.'.str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $repo->insert('refunds', $id, [
            'refund_id' => $id,
            'intent_id' => 'intent.refund.501',
            'amount_minor' => 1,
            'currency' => 'USD',
            'refundable_balance_minor' => 0,
            'requester_ref' => 'user:501',
            'reviewer_ref' => 'user:reviewer',
            'executor_ref' => 'user:executor',
            'reason' => 'prior_refund',
            'decision_reason' => 'provider_confirmed',
            'policy_version' => 'refund.current.v1',
            'state' => 'closed',
            'provider_ref' => 'provider.refund.'.$i,
            'record_version' => 1,
            'requested_at' => $now->modify('-1 day'),
            'updated_at' => $now->modify('-1 day'),
        ]);
    }
    $service = new RefundWorkflowService($repo, new ProviderRegistry(), fullRuntime12());
    expectInvariant12(static fn () => $service->request(
        'refund.new.502',
        'intent.refund.501',
        'user:501',
        new Money(1, 'USD'),
        'duplicate_charge',
        $now
    ));
    assertSame12(null, $repo->get('refunds', 'refund.new.502'), 'over-refund record must not exist');
};

$tests['provider refund webhook also scans complete refund history'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T11:00:00+00:00');
    insertIntent12($repo, 'intent.webhook.refund.501', 501, 'user:502', 'settled', $now);
    for ($i = 1; $i <= 501; $i++) {
        $id = 'refund.webhook.prior.'.str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $repo->insert('refunds', $id, [
            'refund_id' => $id,
            'intent_id' => 'intent.webhook.refund.501',
            'amount_minor' => 1,
            'currency' => 'USD',
            'refundable_balance_minor' => 0,
            'requester_ref' => 'user:502',
            'reviewer_ref' => 'user:reviewer',
            'executor_ref' => 'user:executor',
            'reason' => 'prior_refund',
            'decision_reason' => 'provider_confirmed',
            'policy_version' => 'refund.current.v1',
            'state' => 'closed',
            'provider_ref' => 'provider.refund.webhook.'.$i,
            'record_version' => 1,
            'requested_at' => $now->modify('-1 day'),
            'updated_at' => $now->modify('-1 day'),
        ]);
    }
    $raw = '{"event":"payment.refunded"}';
    $evidence = new ProviderEvidence(
        'provider.test',
        'provider.event.refund.502',
        'payment.refunded',
        'intent.webhook.refund.501',
        new Money(1, 'USD'),
        'key.round12',
        $now,
        $now,
        hash('sha256', $raw),
        true,
        true,
        $now
    );
    $service = new WebhookIngestionService(
        fullRuntime12(),
        new ProviderRegistry([new Round12Provider($evidence)]),
        $repo
    );
    expectInvariant12(static fn () => $service->ingest('provider.test', $raw, [], $now->getTimestamp()));
    assertSame12(null, $repo->get('provider_events', 'provider.event.refund.502'), 'failed over-refund webhook must rollback event evidence');
};

$tests['chargeback provider and cumulative amount are bound to canonical payment'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
    insertIntent12($repo, 'intent.chargeback.100', 100, 'user:600', 'settled', $now);
    $risk = new RiskOperationsService($repo, fullRuntime12());

    expectInvariant12(static fn () => $risk->openChargeback(new ChargebackCase(
        'case.provider.mismatch',
        'provider.other',
        'provider.case.mismatch',
        'intent.chargeback.100',
        new Money(10, 'USD'),
        'fraudulent',
        $now,
        $now->modify('+30 days')
    ), $now));

    $risk->openChargeback(new ChargebackCase(
        'case.partial.60',
        'provider.test',
        'provider.case.60',
        'intent.chargeback.100',
        new Money(60, 'USD'),
        'fraudulent',
        $now,
        $now->modify('+30 days')
    ), $now);

    expectInvariant12(static fn () => $risk->openChargeback(new ChargebackCase(
        'case.partial.50',
        'provider.test',
        'provider.case.50',
        'intent.chargeback.100',
        new Money(50, 'USD'),
        'fraudulent',
        $now,
        $now->modify('+30 days')
    ), $now));
};

$tests['won zero-fee chargeback reaches ledger-adjusted state without empty ledger transaction'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T13:00:00+00:00');
    insertIntent12($repo, 'intent.chargeback.won', 100, 'user:601', 'settled', $now);
    $risk = new RiskOperationsService($repo, fullRuntime12());
    $deadline = $now->modify('+30 days');
    $risk->openChargeback(new ChargebackCase(
        'case.won.zero',
        'provider.test',
        'provider.case.won.zero',
        'intent.chargeback.won',
        new Money(100, 'USD'),
        'fraudulent',
        $now,
        $deadline
    ), $now);
    // The WordPress repository persists datetimes as SQL strings. Normalize the
    // in-memory fixture to that persistence representation before exercising hydration.
    $repo->updateWhere('chargebacks', ['case_id' => 'case.won.zero'], [
        'created_at' => $now->format(DATE_ATOM),
        'response_deadline' => $deadline->format(DATE_ATOM),
        'updated_at' => $now->format(DATE_ATOM),
    ]);
    $risk->submitChargebackEvidence('case.won.zero', str_repeat('a', 64), $now->modify('+1 hour'), 1);
    $risk->acceptChargebackEvidence('case.won.zero', $now->modify('+2 hours'), 2);
    $risk->recordChargebackOutcome('case.won.zero', true, Money::zero('USD'), $now->modify('+3 hours'), 3);
    $result = $risk->adjustChargebackLedger('case.won.zero', 'user:risk.operator', $now->modify('+4 hours'), 4);
    assertSame12('ledger_adjusted', $result['state'] ?? null, 'won chargeback state');
    assertSame12(null, $result['transaction_id'] ?? 'missing', 'zero-impact chargeback ledger transaction');
    assertSame12(0, count($repo->all('ledger_transactions')), 'empty ledger transaction must not be created');
};

$tests['adjustment idempotency includes accounts reason and requester and source currency'] = static function (): void {
    $repo = new MemoryFinancialRepository();
    $now = new DateTimeImmutable('2026-09-17T14:00:00+00:00');
    insertBalancedLedger12($repo, 'txn.source.usd', 'USD', 100, $now);
    $service = new FinancialAdjustmentService($repo, fullRuntime12(), new FinancialAuditService($repo));
    $service->request(
        'adjustment.round12.1',
        'txn.source.usd',
        new Money(10, 'USD'),
        'expense.financial_adjustment',
        'asset.provider_clearing',
        'correction',
        str_repeat('b', 64),
        'user:requester.12',
        $now
    );
    expectInvariant12(static fn () => $service->request(
        'adjustment.round12.1',
        'txn.source.usd',
        new Money(10, 'USD'),
        'expense.payment_processing',
        'asset.provider_clearing',
        'correction',
        str_repeat('b', 64),
        'user:requester.12',
        $now->modify('+1 minute')
    ));
    expectInvariant12(static fn () => $service->request(
        'adjustment.round12.eur',
        'txn.source.usd',
        new Money(10, 'EUR'),
        'expense.financial_adjustment',
        'asset.provider_clearing',
        'correction',
        str_repeat('c', 64),
        'user:requester.12',
        $now->modify('+2 minutes')
    ));
    $actions = array_column($repo->all('audit'), 'action');
    assertTrue12(in_array('adjustment_requested', $actions, true), 'adjustment request audit missing');
};

$tests['legacy confirmation and active webhook chronology share twenty-four-hour settlement allowance'] = static function (): void {
    $created = new DateTimeImmutable('2026-09-17T00:00:00+00:00');
    $expires = $created->modify('+1 hour');
    $occurred = $expires->modify('+2 hours');
    $intent = paymentIntent12('intent.confirm.async', $created, $expires);
    $evidence = evidence12('event.confirm.async', 'intent.confirm.async', $occurred);
    $ledger = new LedgerTransaction('txn.confirm.async', [
        new LedgerEntry('asset.provider_clearing', 'debit', new Money(100, 'USD'), 'confirm:debit'),
        new LedgerEntry('income.donation', 'credit', new Money(100, 'USD'), 'confirm:credit'),
    ]);
    $service = new PaymentConfirmationService(
        static fn (callable $work) => $work(),
        static fn (LedgerTransaction $transaction) => null,
        static fn ($event) => null
    );
    $service->confirmSettled($intent, $evidence, $ledger, 1, $occurred, 'event.financial.confirm.async');
    assertSame12(PaymentIntentState::SETTLED, $intent->state(), 'async settlement state');

    $lateOccurred = $expires->modify('+24 hours +1 second');
    $lateIntent = paymentIntent12('intent.confirm.too-late', $created, $expires);
    $lateEvidence = evidence12('event.confirm.too-late', 'intent.confirm.too-late', $lateOccurred);
    $lateLedger = new LedgerTransaction('txn.confirm.too-late', [
        new LedgerEntry('asset.provider_clearing', 'debit', new Money(100, 'USD'), 'late:debit'),
        new LedgerEntry('income.donation', 'credit', new Money(100, 'USD'), 'late:credit'),
    ]);
    expectInvariant12(static fn () => $service->confirmSettled(
        $lateIntent,
        $lateEvidence,
        $lateLedger,
        1,
        $lateOccurred,
        'event.financial.confirm.too-late'
    ));
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
fwrite(STDOUT, count($tests)." tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);

function fullRuntime12(): RuntimeConfiguration
{
    return new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', [
        'founder_change_control' => true,
        'legal_tax_accounting' => true,
        'receipt_identity' => true,
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
    ], true, false, new FinancialReceiptIdentity('Sabri Social Homeopathy Platform', 'PK'));
}

function insertIntent12(MemoryFinancialRepository $repo, string $intentId, int $amount, string $actor, string $state, DateTimeImmutable $now): void
{
    $repo->insert('intents', $intentId, [
        'intent_id' => $intentId,
        'actor_ref' => $actor,
        'product_id' => 'donation.one_time',
        'price_version_id' => null,
        'amount_minor' => $amount,
        'currency' => 'USD',
        'provider' => 'provider.test',
        'provider_ref' => 'provider.payment.'.substr(hash('sha256', $intentId), 0, 24),
        'state' => $state,
        'failure_code' => null,
        'idempotency_key' => 'idem.'.substr(hash('sha256', $intentId), 0, 40),
        'request_hash' => str_repeat('d', 64),
        'expires_at' => $now->modify('+1 hour'),
        'record_version' => 1,
        'trace_id' => 'trace.'.substr(hash('sha256', $intentId), 0, 24),
        'created_at' => $now->modify('-1 hour'),
        'updated_at' => $now->modify('-1 minute'),
    ]);
}

function insertBalancedLedger12(MemoryFinancialRepository $repo, string $transactionId, string $currency, int $amount, DateTimeImmutable $at): void
{
    $repo->insert('ledger_transactions', $transactionId, [
        'transaction_id' => $transactionId,
        'source_type' => 'round12_test',
        'source_ref' => 'source:'.$transactionId,
        'effective_at' => $at,
        'recorded_at' => $at,
        'actor_ref' => 'system:test',
        'reason' => 'round12_test',
        'period_id' => $at->format('Y-m'),
        'reversal_of' => null,
        'trace_id' => 'trace:'.$transactionId,
    ]);
    $repo->insert('ledger_entries', $transactionId.':debit', [
        'transaction_id' => $transactionId,
        'account' => 'asset.provider_clearing',
        'direction' => 'debit',
        'amount_minor' => $amount,
        'currency' => $currency,
        'source_ref' => $transactionId.':debit',
    ]);
    $repo->insert('ledger_entries', $transactionId.':credit', [
        'transaction_id' => $transactionId,
        'account' => 'income.donation',
        'direction' => 'credit',
        'amount_minor' => $amount,
        'currency' => $currency,
        'source_ref' => $transactionId.':credit',
    ]);
}

function paymentIntent12(string $id, DateTimeImmutable $created, DateTimeImmutable $expires): PaymentIntent
{
    return new PaymentIntent(
        $id,
        'user:confirm.12',
        'donation.one_time',
        null,
        new Money(100, 'USD'),
        'provider.test',
        'idempotency.confirm.round12.0001',
        str_repeat('e', 64),
        $created,
        $expires,
        PaymentIntentState::PROVIDER_PENDING,
        1,
        'provider.payment.confirm.12',
        null,
        $created
    );
}

function evidence12(string $eventId, string $intentId, DateTimeImmutable $occurred): ProviderEvidence
{
    return new ProviderEvidence(
        'provider.test',
        $eventId,
        'payment.settled',
        $intentId,
        new Money(100, 'USD'),
        'key.round12',
        $occurred,
        $occurred,
        hash('sha256', $eventId),
        true,
        true,
        $occurred
    );
}

function expectInvariant12(callable $operation): void
{
    try { $operation(); }
    catch (InvariantViolation) { return; }
    throw new RuntimeException('Expected InvariantViolation.');
}

function assertSame12(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label.' mismatch: expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

function assertTrue12(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
