<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$now = new DateTimeImmutable('2026-11-15T14:00:00+05:00');
$checks = 0;
$expect = static function (bool $ok, string $why) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL: '.$why); }
};
$seed = static function (MemoryFinancialRepository $db, string $id, DateTimeImmutable $at): void {
    $db->insert('outbox', $id, [
        'event_id'=>$id, 'event_type'=>'DonationSettled.v1',
        'payload_json'=>['donation_type'=>'one_time'],
        'state'=>'pending', 'attempts'=>0, 'available_at'=>$at->modify('-1 minute'),
        'leased_until'=>null,
    ]);
};

// Another worker changed the state while the transport published externally.
// Without a post-publish CAS check, a 0-row UPDATE looked like successful delivery.
$lost = new MemoryFinancialRepository();
$seed($lost, 'evt:lost:ack', $now);
$replace = new class($lost) implements OutboxTransport {
    public int $publishes = 0;
    public function __construct(private MemoryFinancialRepository $db) {}
    public function publish(string $eventId, string $eventType, array $payload): void {
        $this->publishes++;
        $this->db->updateWhere('outbox', [
            'event_id'=>$eventId, 'state'=>'processing',
        ], ['state'=>'retry', 'leased_until'=>null]);
    }
};
$lostResult = (new OutboxDispatcher($lost, $replace))->dispatch($now, 1);
$expect($replace->publishes===1, 'Transport was reached once in lost-ack scenario');
$expect($lostResult['delivered']===0, 'No false delivered counter when DB confirmation changed state');
$expect($lostResult['skipped']===1, 'Lost lease is classified as skipped, not delivered or failed');
$expect($lostResult['retried']===0, 'Outdated worker does not falsely claim its own retry');
$expect(($lost->get('outbox', 'evt:lost:ack')['state']??null)==='retry',
    'Newer worker state is not overwritten after failed acknowledgment');

// A renewal changes ONLY the lease while keeping state=processing.
// The stale worker cannot erase that newer lease on success or error.
$renewed = new MemoryFinancialRepository();
$seed($renewed, 'evt:renewed:lease', $now);
$transportRenew = new class($renewed, $now) implements OutboxTransport {
    public function __construct(private MemoryFinancialRepository $db, private DateTimeImmutable $clock) {}
    public function publish(string $eventId, string $eventType, array $payload): void {
        $this->db->updateWhere('outbox', [
            'event_id'=>$eventId, 'state'=>'processing',
        ], ['leased_until'=>$this->clock->modify('+30 minutes')]);
    }
};
$renewResult=(new OutboxDispatcher($renewed, $transportRenew))->dispatch($now, 1);
$renewRow=$renewed->get('outbox', 'evt:renewed:lease');
$expect($renewResult['delivered']===0 && $renewResult['skipped']===1,
    'Renewed lease cannot be marked delivered by an old worker');
$expect(($renewRow['state']??null)==='processing',
    'Renewed processing state remains protected');
$expect(($renewRow['leased_until']??null)==$now->modify('+30 minutes'),
    'Stale error handling must not erase a newer lease');

// Ordinary successful delivery still uses exactly one persisted transition.
$good = new MemoryFinancialRepository();
$seed($good, 'evt:good:ack', $now);
$transportGood = new class implements OutboxTransport {
    public array $ids = [];
    public function publish(string $eventId, string $eventType, array $payload): void {
        $this->ids[]=$eventId;
    }
};
$goodResult=(new OutboxDispatcher($good,$transportGood))->dispatch($now,1);
$expect($goodResult['delivered']===1 && $goodResult['skipped']===0,
    'A persisted delivery is counted exactly once');
$expect(($good->get('outbox','evt:good:ack')['state']??null)==='delivered',
    'Persisted delivered state agrees with result');
$expect($transportGood->ids===['evt:good:ack'],
    'Stable event identity reaches the consumer');

fwrite(STDOUT, "CF-03 outbox CAS delivery evidence: {$checks}/{$checks} checks passed.\n");
