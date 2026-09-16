<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationRequestVelocityGuard;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$repo = new MemoryFinancialRepository();
$guard = new DonationRequestVelocityGuard($repo);
$now = new DateTimeImmutable('2026-09-16T09:00:00+05:00');
$actor = 'guest:'.str_repeat('a', 40);

$guard->assertAllowed($actor, $now);

for ($i = 1; $i <= DonationRequestVelocityGuard::MAX_NEW_ATTEMPTS_PER_WINDOW; $i++) {
    $repo->insert('idempotency', 'idem.rate.'.$i, [
        'scope' => 'donation_checkout',
        'idempotency_key' => 'idem.rate.'.$i,
        'actor_ref' => $actor,
        'request_hash' => str_repeat((string)($i % 10), 64),
        'state' => 'failed',
        'result_ref' => null,
        'created_at' => $now->modify('-'.($i * 10).' seconds'),
        'completed_at' => $now->modify('-'.($i * 9).' seconds'),
        'expires_at' => $now->modify('+1 day'),
    ]);
}

expectInvariant(static fn () => $guard->assertAllowed($actor, $now), 'rate limit');
$guard->assertAllowed($actor, $now->modify('+6 minutes'));

$actor2 = 'user:42';
for ($i = 1; $i <= DonationRequestVelocityGuard::MAX_ACTIVE_PROVIDER_PENDING_INTENTS; $i++) {
    $repo->insert('intents', 'intent.active.'.$i, [
        'intent_id' => 'intent.active.'.$i,
        'actor_ref' => $actor2,
        'product_id' => 'donation.one_time',
        'amount_minor' => 1000,
        'currency' => 'USD',
        'provider' => 'provider.test',
        'provider_ref' => 'session.'.$i,
        'state' => 'provider_pending',
        'expires_at' => $now->modify('+1 hour'),
        'record_version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
expectInvariant(static fn () => $guard->assertAllowed($actor2, $now), 'active hosted donation checkouts');
$guard->assertAllowed($actor2, $now->modify('+2 hours'));

// Regression: old records must not hide recent attempts beyond a small first page.
$actor3 = 'user:pagination-rate';
for ($i = 1; $i <= 120; $i++) {
    $repo->insert('idempotency', 'idem.old.'.str_pad((string)$i, 3, '0', STR_PAD_LEFT), [
        'scope' => 'donation_checkout',
        'idempotency_key' => 'idem.old.'.str_pad((string)$i, 3, '0', STR_PAD_LEFT),
        'actor_ref' => $actor3,
        'request_hash' => hash('sha256', 'old-'.$i),
        'state' => 'failed',
        'result_ref' => null,
        'created_at' => $now->modify('-2 days'),
        'completed_at' => $now->modify('-2 days'),
        'expires_at' => $now->modify('-1 day'),
    ]);
}
for ($i = 1; $i <= DonationRequestVelocityGuard::MAX_NEW_ATTEMPTS_PER_WINDOW; $i++) {
    $repo->insert('idempotency', 'idem.recent.'.str_pad((string)$i, 2, '0', STR_PAD_LEFT), [
        'scope' => 'donation_checkout',
        'idempotency_key' => 'idem.recent.'.str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'actor_ref' => $actor3,
        'request_hash' => hash('sha256', 'recent-'.$i),
        'state' => 'failed',
        'result_ref' => null,
        'created_at' => $now->modify('-'.($i * 5).' seconds'),
        'completed_at' => $now,
        'expires_at' => $now->modify('+1 day'),
    ]);
}
expectInvariant(static fn () => $guard->assertAllowed($actor3, $now), 'rate limit');

fwrite(STDOUT, "PASS: donation mutation velocity and active hosted-intent limits fail closed across complete bounded actor scans\n");

function expectInvariant(callable $operation, string $messagePart): void
{
    try {
        $operation();
    } catch (InvariantViolation $error) {
        if (!str_contains(strtolower($error->getMessage()), strtolower($messagePart))) {
            throw new RuntimeException('Unexpected invariant message: '.$error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected InvariantViolation containing: '.$messagePart);
}
