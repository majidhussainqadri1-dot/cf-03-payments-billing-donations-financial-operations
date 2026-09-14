<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ProviderRegistry;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;
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
