<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$now = new DateTimeImmutable('2026-10-21T09:00:00+05:00');
$checks = 0;
$test = static function (bool $ok, string $description) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL: '.$description); }
};
$transport = new class implements OutboxTransport {
    public array $sent = [];
    public function publish(string $eventId, string $eventType, array $payload): void
    {
        $this->sent[] = [$eventId, $eventType, $payload];
    }
};
$repo = new MemoryFinancialRepository();
$make = static function (string $id, string $state, DateTimeImmutable $lease, int $attempts = 0) use ($repo, $now): void {
    $repo->insert('outbox', $id, [
        'event_id' => $id, 'event_type' => 'DonationSettled.v1',
        'payload_json' => ['donation_type' => 'one_time'],
        'state' => $state, 'attempts' => $attempts,
        'available_at' => $now->modify('-1 minute'),
        'leased_until' => $lease,
    ]);
};
$make('evt:expired:1', 'processing', $now->modify('-1 second'));
$make('evt:active:1', 'processing', $now->modify('+5 minutes'));
$make('evt:exhausted:1', 'processing', $now->modify('-1 minute'), 1);
$dispatcher = new OutboxDispatcher($repo, $transport, 2);
$result = $dispatcher->dispatch($now, 10);
$test($result['delivered'] === 1, 'Exactly one expired non-exhausted event publishes');
$test($result['retried'] === 1, 'Expired lease is accounted as a retry');
$test($result['dead_lettered'] === 1, 'Exhausted expired lease goes dead-letter');
$test(count($transport->sent) === 1 && $transport->sent[0][0] === 'evt:expired:1', 'Stable event ID survives recovery');
$test(($repo->get('outbox', 'evt:expired:1')['state'] ?? '') === 'delivered', 'Recovered event is durably marked delivered');
$test(($repo->get('outbox', 'evt:expired:1')['attempts'] ?? 0) === 1, 'Recovered lease increments retry count');
$test(($repo->get('outbox', 'evt:active:1')['state'] ?? '') === 'processing', 'Unexpired lease is never reclaimed');
$test(($repo->get('outbox', 'evt:exhausted:1')['state'] ?? '') === 'dead_letter', 'Exhausted lease not sent to transport');
$test(count($transport->sent) === 1, 'No second delivery of an acknowledged event');
$result2 = $dispatcher->dispatch($now, 10);
$test($result2['delivered'] === 0 && count($transport->sent) === 1, 'Re-dispatch remains idempotent for delivered event');
$test(($repo->get('outbox', 'evt:active:1')['attempts'] ?? -1) === 0, 'Fresh worker attempt count unchanged');
fwrite(STDOUT, "CF-03 expired outbox lease recovery: {$checks}/{$checks} assertions passed.\n");
