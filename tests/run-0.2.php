<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sabri\CF03\Application\ActivationEvidenceRecord;
use Sabri\CF03\Application\CheckoutCommand;
use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Application\HostedCheckoutReference;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\BillingType;
use Sabri\CF03\Domain\FinancialProduct;
use Sabri\CF03\Domain\IdempotencyRecord;
use Sabri\CF03\Domain\IdempotencyStatus;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PaymentIntentState;
use Sabri\CF03\Domain\PriceCatalog;
use Sabri\CF03\Domain\PriceVersion;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Domain\ProviderEventStateMapper;
use Sabri\CF03\Domain\TaxMode;
use Sabri\CF03\Integration\File00FinancialFact;
use Sabri\CF03\Integration\FinancialFactType;

$tests = [];
$now = new DateTimeImmutable('2026-08-04T01:43:00+05:00');

$tests['strict activation accepts only hash-bound evidence'] = static function () use ($now): void {
    $record = activationRecord($now);
    $gate = strictGate($record, $now);
    same(true, $gate->evaluate()->approved());
};
$tests['strict activation rejects hash mismatch'] = static function () use ($now): void {
    $record = activationRecord($now);
    $gate = new EvidenceBoundActivationGate(true, static fn (): array => $record, str_repeat('b', 64), '0.2.0', static fn () => $now);
    same(false, $gate->evaluate()->approved());
};
$tests['strict activation rejects expired evidence'] = static function () use ($now): void {
    $record = activationRecord($now);
    $record['approvals']['pci_scope_validation']['expires_at'] = $now->modify('-1 minute')->format(DATE_ATOM);
    same(false, strictGate($record, $now)->evaluate()->approved());
};
$tests['activation evidence IDs must be unique'] = static function () use ($now): void {
    $record = activationRecord($now);
    $record['approvals']['legal_tax_accounting_review']['evidence_id'] = $record['approvals']['founder_change_control']['evidence_id'];
    truth(in_array('activation_evidence_duplicate', strictGate($record, $now)->evaluate()->missingGates(), true));
};
$tests['provider evidence ID cannot reuse approval evidence ID'] = static function () use ($now): void {
    $record = activationRecord($now);
    $record['provider']['evidence_id'] = $record['approvals']['founder_change_control']['evidence_id'];
    truth(in_array('activation_evidence_duplicate', strictGate($record, $now)->evaluate()->missingGates(), true));
};
$tests['donation cannot map to an entitlement'] = static function (): void {
    throws(static fn () => new FinancialProduct('donation.general', ProductKind::DONATION, BillingType::VOLUNTARY, 'cf03.finance', 'membership.vip', 'refund.donation.v1', 'cancel.donation.v1', true, true, 'approval.product.1'), DomainException::class);
};
$tests['education membership must remain recurring'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    same(BillingType::RECURRING, $product->billingType());
    same(40000, $price->amount()->minorUnits());
};
$tests['AI usage is a separate metered product'] = static function (): void {
    $product = new FinancialProduct('ai.usage.standard', ProductKind::AI_USAGE, BillingType::METERED, 'file16.ai', 'ai.usage.standard', 'refund.ai.v1', 'cancel.ai.v1', true, true, 'approval.ai.1');
    same(ProductKind::AI_USAGE, $product->kind());
};
$tests['approved price versions cannot overlap'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    $overlap = new PriceVersion($product->productId(), 'price.pkr.2026b', Money::fromDecimal('450.00', 'PKR'), 'GLOBAL', TaxMode::NOT_APPLICABLE, $now->modify('-1 day'), null, $product->refundPolicyVersion(), $product->cancellationPolicyVersion(), true, 'approval.price.2');
    throws(static fn () => new PriceCatalog($product, [$price, $overlap]), DomainException::class);
};
$tests['catalog resolves an approved immutable snapshot'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    $catalog = new PriceCatalog($product, [$price]);
    same($price->snapshotHash(), $catalog->resolve($now, 'PK', 'PKR')->snapshotHash());
};
$tests['checkout rejects client-tampered amount'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    throws(static fn () => checkout($product, $price, Money::fromDecimal('399.00', 'PKR'), $now), DomainException::class);
};
$tests['provider request excludes canonical user reference'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    $payload = checkout($product, $price, $price->amount(), $now)->toProviderRequest();
    same(false, array_key_exists('user_reference', $payload));
    same($price->snapshotHash(), $payload['price_snapshot_hash']);
};
$tests['checkout return path must remain same-origin'] = static function () use ($now): void {
    [$product, $price] = educationProductAndPrice($now);
    throws(static fn () => new CheckoutCommand('intent:2', 'user:42', $product, $price, $price->amount(), 'idem-checkout-0002', $now, $now->modify('+30 minutes'), '//evil.test'), InvalidArgumentException::class);
};
$tests['idempotency fingerprint ignores object key ordering'] = static function () use ($now): void {
    $record = IdempotencyRecord::begin('checkout.create', 'idem-checkout-0003', 'user:42', ['amount' => 40000, 'currency' => 'PKR'], $now);
    $record->assertReplayCompatible('checkout.create', 'idem-checkout-0003', 'user:42', ['currency' => 'PKR', 'amount' => 40000]);
};
$tests['idempotency key conflict is rejected'] = static function () use ($now): void {
    $record = IdempotencyRecord::begin('checkout.create', 'idem-checkout-0004', 'user:42', ['amount' => 40000], $now);
    throws(static fn () => $record->assertReplayCompatible('checkout.create', 'idem-checkout-0004', 'user:42', ['amount' => 45000]), DomainException::class);
};
$tests['idempotency completion is immutable'] = static function () use ($now): void {
    $record = IdempotencyRecord::begin('checkout.create', 'idem-checkout-0005', 'user:42', ['amount' => 40000], $now)->complete('intent:5', $now->modify('+1 second'));
    same(IdempotencyStatus::COMPLETED, $record->status());
    throws(static fn () => $record->complete('intent:6', $now->modify('+2 seconds')), DomainException::class);
};
$tests['idempotency rejects floating-point money'] = static function () use ($now): void {
    throws(static fn () => IdempotencyRecord::begin('checkout.create', 'idem-checkout-0006', 'user:42', ['amount' => 400.00], $now), InvalidArgumentException::class);
};
$tests['signed provider evidence is accepted within replay window'] = static function () use ($now): void {
    $evidence = providerEvidence($now, 'event:100', 'payment.settled', true, true, '-30 seconds');
    $evidence->assertTrusted();
    $evidence->assertMatches('provider.sandbox', 'intent:100', Money::fromDecimal('400.00', 'PKR'));
};
$tests['forged provider evidence is rejected'] = static function () use ($now): void {
    throws(static fn () => providerEvidence($now, 'event:101', 'payment.settled', false, true, '-30 seconds')->assertTrusted(), DomainException::class);
};
$tests['stale provider evidence is rejected'] = static function () use ($now): void {
    throws(static fn () => providerEvidence($now, 'event:102', 'payment.settled', true, true, '-10 minutes')->assertTrusted(), DomainException::class);
};
$tests['provider evidence cannot cross provider boundaries'] = static function () use ($now): void {
    $evidence = providerEvidence($now, 'event:103', 'payment.settled', true, true, '-30 seconds');
    throws(static fn () => $evidence->assertMatches('provider.other', 'intent:100', Money::fromDecimal('400.00', 'PKR')), DomainException::class);
};
$tests['unknown trusted provider event maps to quarantine'] = static function () use ($now): void {
    $mapper = new ProviderEventStateMapper();
    same(PaymentIntentState::QUARANTINED, $mapper->mapTrusted(providerEvidence($now, 'event:104', 'provider.unknown', true, true, '-10 seconds')));
};
$tests['untrusted event cannot be mapped into financial state'] = static function () use ($now): void {
    $mapper = new ProviderEventStateMapper();
    throws(static fn () => $mapper->mapTrusted(providerEvidence($now, 'event:105', 'payment.settled', false, true, '-10 seconds')), DomainException::class);
};
$tests['File 00 receives facts and never entitlement commands'] = static function () use ($now): void {
    $fact = new File00FinancialFact('event:financial:1', FinancialFactType::PAYMENT_SETTLED, 'user:42', 'education.membership.monthly', 'price.pkr.2026a', 'payment:100', Money::fromDecimal('400.00', 'PKR'), 1, $now, 'correlation:100');
    $payload = $fact->toPayload();
    same('PaymentSettled', $payload['event_name']);
    same(false, array_key_exists('grant_access', $payload));
};
$tests['audit envelope rejects secret metadata'] = static function () use ($now): void {
    throws(static fn () => new AuditEnvelope('audit:1', 'operator:1', 'checkout.create', 'payment_intent', 'intent:1', 'billing.checkout', AuditOutcome::DENIED, $now, 'correlation:1', ['provider_secret' => 'never-log']), DomainException::class);
};
$tests['audit envelope permits minimized hashes'] = static function () use ($now): void {
    $event = new AuditEnvelope('audit:2', 'operator:1', 'webhook.verify', 'provider_event', 'event:100', 'finance.reconciliation', AuditOutcome::SUCCEEDED, $now, 'correlation:2', ['raw_body_sha256' => str_repeat('d', 64)]);
    same('succeeded', $event->toPayload()['outcome']);
};
$tests['hosted checkout URL requires HTTPS and allowed host'] = static function () use ($now): void {
    $reference = new HostedCheckoutReference('provider.sandbox', 'session:100', 'https://payments.example.test/session/100', $now->modify('+30 minutes'), ['payments.example.test']);
    same('provider.sandbox', $reference->providerCode());
    throws(static fn () => new HostedCheckoutReference('provider.sandbox', 'session:101', 'https://evil.example.test/session/101', $now->modify('+30 minutes'), ['payments.example.test']), InvalidArgumentException::class);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

/** @return array{0:FinancialProduct,1:PriceVersion} */
function educationProductAndPrice(DateTimeImmutable $now): array
{
    $product = new FinancialProduct('education.membership.monthly', ProductKind::EDUCATION_MEMBERSHIP, BillingType::RECURRING, 'file00.membership', 'education.structured', 'refund.education.v1', 'cancel.education.v1', true, true, 'approval.product.education.1');
    $price = new PriceVersion($product->productId(), 'price.pkr.2026a', Money::fromDecimal('400.00', 'PKR'), 'GLOBAL', TaxMode::NOT_APPLICABLE, $now->modify('-1 day'), null, $product->refundPolicyVersion(), $product->cancellationPolicyVersion(), true, 'approval.price.education.1');
    return [$product, $price];
}
function checkout(FinancialProduct $product, PriceVersion $price, Money $amount, DateTimeImmutable $now): CheckoutCommand
{
    return new CheckoutCommand('intent:1', 'user:42', $product, $price, $amount, 'idem-checkout-0001', $now, $now->modify('+30 minutes'), '/billing/return');
}
function providerEvidence(DateTimeImmutable $now, string $eventId, string $eventType, bool $verified, bool $unique, string $age): ProviderEvidence
{
    return new ProviderEvidence('provider.sandbox', $eventId, $eventType, 'intent:100', Money::fromDecimal('400.00', 'PKR'), 'key:v1', $now->modify($age), $now, str_repeat('a', 64), $verified, $unique);
}
/** @return array<string,mixed> */
function activationRecord(DateTimeImmutable $now): array
{
    $approval = static fn (string $id): array => ['approved' => true, 'evidence_id' => 'evidence:' . $id, 'approver_ref' => 'approver:' . $id, 'approved_at' => $now->modify('-1 hour')->format(DATE_ATOM), 'expires_at' => $now->modify('+30 days')->format(DATE_ATOM)];
    return ['schema_version' => '1.0', 'module_version' => '0.2.0', 'record_id' => 'activation:cf03:production:1', 'configuration_hash' => str_repeat('a', 64), 'approvals' => ['founder_change_control' => $approval('founder'), 'legal_tax_accounting_review' => $approval('legal'), 'pci_scope_validation' => $approval('pci'), 'independent_security_acceptance' => $approval('security'), 'staging_acceptance' => $approval('staging'), 'rollback_rehearsal' => $approval('rollback')], 'provider' => ['mode' => 'hosted', 'provider_ref' => 'provider:sandbox', 'evidence_id' => 'evidence:provider:1', 'validated_at' => $now->modify('-1 hour')->format(DATE_ATOM), 'expires_at' => $now->modify('+30 days')->format(DATE_ATOM)]];
}
/** @param array<string,mixed> $record */
function strictGate(array $record, DateTimeImmutable $now): EvidenceBoundActivationGate
{
    return new EvidenceBoundActivationGate(true, static fn (): array => $record, ActivationEvidenceRecord::canonicalHash($record), '0.2.0', static fn () => $now);
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) { throw new RuntimeException(sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true))); }
}
function truth(bool $condition): void
{
    if (! $condition) { throw new RuntimeException('Expected condition to be true.'); }
}
/** @param class-string<Throwable> $class */
function throws(callable $callback, string $class): void
{
    try { $callback(); } catch (Throwable $error) { if ($error instanceof $class) { return; } throw new RuntimeException(sprintf('Expected %s, got %s.', $class, $error::class)); }
    throw new RuntimeException(sprintf('Expected %s to be thrown.', $class));
}
