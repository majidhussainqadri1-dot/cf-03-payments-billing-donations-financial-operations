<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Domain\Money;

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
};
$at = new DateTimeImmutable('2026-10-21T09:00:00+05:00');
$draft = static fn (string $intent, string $actor, string $key, int $minor = 1400): DonationIntentDraft =>
    new DonationIntentDraft($intent, $actor, new Money($minor, 'USD'), false, false,
        DonationServiceState::SANDBOX, $key, $at);

$a = $draft('intent:user1:1', 'user:1001', 'idem-shared-0000000001');
$b = $draft('intent:user2:1', 'user:1002', 'idem-shared-0000000001');
$cloneA = $a->providerSafeClone();
$cloneB = $b->providerSafeClone();

$assert($a->idempotencyKey() === 'idem-shared-0000000001', 'Original client key is unchanged for local canonical claim');
$assert($cloneA->donorReference() === 'donor:withheld', 'Provider identity is withheld');
$assert($cloneB->donorReference() === 'donor:withheld', 'Other user identity also withheld');
$assert($cloneA->idempotencyKey() !== $a->idempotencyKey(), 'Raw user key never sent to provider');
$assert($cloneA->idempotencyKey() !== $cloneB->idempotencyKey(), 'Two distinct actors using same key cannot collide at provider');
$assert($cloneA->idempotencyKey() === $a->providerSafeClone()->idempotencyKey(), 'Provider key deterministic for safe retry');
$assert(preg_match('/^cf03prov\.[a-f0-9]{64}$/', $cloneA->idempotencyKey()) === 1, 'Provider key uses valid bounded opaque format');
$assert(!str_contains($cloneA->idempotencyKey(), '1001'), 'Provider key contains no raw donor identifier');
$assert(!str_contains($cloneA->idempotencyKey(), 'shared'), 'Provider key contains no raw client key');
$c = $draft('intent:user1:2', 'user:1001', 'idem-shared-0000000002');
$assert($c->providerSafeClone()->idempotencyKey() !== $cloneA->idempotencyKey(), 'Distinct approved intent gets independent provider key');
$d = $draft('intent:user1:1', 'user:1001', 'idem-shared-0000000001', 1500);
$assert($d->providerSafeClone()->idempotencyKey() === $cloneA->idempotencyKey(), 'Same intent/key remains stable; application amount-hash mismatch check must reject changed amount');

fwrite(STDOUT, "CF-03 provider idempotency scope: {$checks}/{$checks} assertions passed.\n");
