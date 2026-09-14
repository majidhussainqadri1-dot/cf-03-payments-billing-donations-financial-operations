<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\WebhookIngestionService;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$tests = [];

$tests['R26 refund balance scan cannot truncate after 500 prior records'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $intentId = 'intent.refund.highcardinality';
    $actor = 'user.refund.owner';
    $at = new DateTimeImmutable('2026-09-15T00:00:00Z');
    $repo->insert('intents', $intentId, [
        'intent_id'=>$intentId,
        'actor_ref'=>$actor,
        'amount_minor'=>501,
        'currency'=>'USD',
        'provider'=>'provider.test',
        'provider_ref'=>'pay.highcardinality.001',
        'state'=>'settled',
        'record_version'=>1,
    ]);
    for ($index = 1; $index <= 501; $index++) {
        $refundId = 'refund.prior.'.str_pad((string)$index, 4, '0', STR_PAD_LEFT);
        $repo->insert('refunds', $refundId, [
            'refund_id'=>$refundId,
            'intent_id'=>$intentId,
            'amount_minor'=>1,
            'currency'=>'USD',
            'requester_ref'=>$actor,
            'reason'=>'prior_refund',
            'state'=>'closed',
            'record_version'=>1,
        ]);
    }
    $service = new RefundWorkflowService($repo, new ProviderRegistry(), RuntimeConfiguration::preparing());
    reviewThrows(
        static fn () => $service->request(
            'refund.new.blocked', $intentId, $actor, new Money(1, 'USD'), 'duplicate_charge', $at
        ),
        InvariantViolation::class
    );
    reviewSame(null, $repo->get('refunds', 'refund.new.blocked'));
};

$tests['R27 provider refund reconciliation cannot truncate after 500 prior refunds'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $intentId = 'intent.webhook.refund.highcardinality';
    $at = new DateTimeImmutable('2026-09-15T00:10:00+00:00');
    for ($index = 1; $index <= 501; $index++) {
        $refundId = 'refund.webhook.prior.'.str_pad((string)$index, 4, '0', STR_PAD_LEFT);
        $repo->insert('refunds', $refundId, [
            'refund_id'=>$refundId,
            'intent_id'=>$intentId,
            'amount_minor'=>1,
            'currency'=>'USD',
            'requester_ref'=>'user.refund.owner',
            'reason'=>'prior_refund',
            'state'=>'closed',
            'record_version'=>1,
        ]);
    }
    $eventId = 'event.provider.refund.highcardinality';
    $evidence = new ProviderEvidence(
        'provider.test',
        $eventId,
        'payment.refunded',
        $intentId,
        new Money(1, 'USD'),
        'key.v1',
        $at,
        $at,
        hash('sha256', 'refund-webhook'),
        true,
        true,
        $at
    );
    $service = new WebhookIngestionService(RuntimeConfiguration::preparing(), new ProviderRegistry(), $repo);
    $invoke = \Closure::bind(
        static function (WebhookIngestionService $service, array $intent, ProviderEvidence $evidence): void {
            $service->postRefund($intent, $evidence, 'trace.refund.highcardinality', 1);
        },
        null,
        WebhookIngestionService::class
    );
    if (!$invoke instanceof \Closure) {
        throw new RuntimeException('Private refund reconciliation test hook could not be bound.');
    }
    reviewThrows(
        static fn () => $invoke($service, [
            'intent_id'=>$intentId,
            'product_id'=>'donation.one_time',
            'actor_ref'=>'user.refund.owner',
            'amount_minor'=>501,
            'currency'=>'USD',
        ], $evidence),
        InvariantViolation::class
    );
    $externalId = 'refund.external.'.substr(hash('sha256', 'provider.test|'.$eventId), 0, 32);
    reviewSame(null, $repo->get('refunds', $externalId));
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("%d review tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function reviewSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) { throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true)); }
}

/** @param class-string<Throwable> $class */
function reviewThrows(callable $callback, string $class): void
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());
    }
    throw new RuntimeException('Expected '.$class.' to be thrown.');
}
