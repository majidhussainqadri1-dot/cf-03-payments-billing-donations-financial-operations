<?php

declare(strict_types=1);

define('SPF_VERSION', '2.0.1');
define('SPF_CONTRACT_VERSION', '2.0.0');

final class SPF_Event_Bus
{
    public static array $accepted = [];
    public static mixed $result = true;
    public static function publish(
        string $eventName,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        int $version,
        string $dedupeKey,
        string $privacyClass
    ): mixed {
        self::$accepted[] = [$eventName, $aggregateType, $aggregateId,
            $payload, $version, $dedupeKey, $privacyClass];
        return self::$result;
    }
}

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressOutboxTransport;
use Sabri\CF03\Support\InvariantViolation;

$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
};
$rejects = static function (callable $operation, string $label) use ($expect): void {
    $denied = false;
    try { $operation(); } catch (InvariantViolation) { $denied = true; }
    $expect($denied, $label);
};

$transport = new WordPressOutboxTransport();
$expect(WordPressOutboxTransport::backboneReady(),
    'File 01 versioned durable event bus is an explicit dependency');

$eventId = 'evt:consumer:1';
$eventType = 'DonationSettled.v1';
$private = [
    'amount_minor' => 100,
    'donor_ref' => 'user:999',
    'provider_secret' => 'DO-NOT-EXPORT',
    'receipt_ref' => 'private:receipt',
    'access' => 'none',
];
$transport->publish($eventId, $eventType, $private);
$expect(count(SPF_Event_Bus::$accepted) === 1,
    'One financial fact is submitted for durable File01 storage');
[$eventName, $aggregateType, $aggregateId, $minimum,
 $version, $dedupeKey, $privacyClass] = SPF_Event_Bus::$accepted[0];
$expect($eventName === 'CF03.DonationSettled.v1',
    'File01 receives the explicit versioned owner event name');
$expect($aggregateType === 'financial_fact' && $version === 1,
    'File01 receives canonical owner aggregate/version');
$expect($privacyClass === 'restricted',
    'Financial facts are never public analytics or user ranking');
$expect(str_starts_with($aggregateId, 'fact.') && !str_contains($aggregateId, $eventId),
    'File01 aggregate ID cannot expose original donor/provider event reference');
$expect(str_starts_with($dedupeKey, 'cf03:') && !str_contains($dedupeKey, $eventId),
    'File01 dedupe key is stable and opaque');
$expect(($minimum['donor_privilege'] ?? true) === false
    && ($minimum['access_signal'] ?? true) === false
    && ($minimum['accounting_only'] ?? false) === true,
    'No financial fact grants donor access, ranking or extra feature entitlement');
$expect(!isset($minimum['amount_minor'], $minimum['donor_ref'])
    && !isset($minimum['receipt_ref'], $minimum['provider_secret'])
    && !str_contains(json_encode($minimum, JSON_THROW_ON_ERROR), 'DO-NOT-EXPORT'),
    'Raw financial/identity/payment evidence never leaves CF03 owner');

$transport->publish($eventId, $eventType, $private);
$expect(SPF_Event_Bus::$accepted[0][5] === SPF_Event_Bus::$accepted[1][5],
    'Duplicate same-source event retries preserve exact durable File01 deduplication');

// CF-03's live source currently writes these bare event names. The
// downstream File 01 envelope MUST carry an explicit contract version.
$transport->publish('event:adjustment:abc', 'FinancialAdjustmentExecuted', $private);
$expect(SPF_Event_Bus::$accepted[2][0] === 'CF03.FinancialAdjustmentExecuted.v1',
    'Bare adjustment fact becomes an explicit v1 File01 event');
$expect(SPF_Event_Bus::$accepted[2][3]['source_event_type'] === 'FinancialAdjustmentExecuted',
    'Original immutable owner fact name is preserved in minimized metadata');
$transport->publish('event:refund:abc', 'DonationRefunded', $private);
$expect(SPF_Event_Bus::$accepted[3][0] === 'CF03.DonationRefunded.v1',
    'Bare refund event is normalized to versioned durable File01 fact');

SPF_Event_Bus::$result = false;
$rejects(static fn() => $transport->publish('evt:failed:1', 'DonationSettled.v1', $private),
    'File01 failed store cannot be labeled delivered by CF03');
SPF_Event_Bus::$result = new stdClass();
$rejects(static fn() => $transport->publish('evt:failed:2', 'DonationSettled.v1', $private),
    'Any non-true acceptance result must remain retryable');
SPF_Event_Bus::$result = true;

$before = count(SPF_Event_Bus::$accepted);
$rejects(static fn() => $transport->publish('bad id', $eventType, []),
    'Untrusted source event identifier rejected before external bus');
$rejects(static fn() => $transport->publish('evt:bad:3', 'unknown', []),
    'Unversioned financial event type rejected');
$expect(count(SPF_Event_Bus::$accepted) === $before,
    'Malformed fact cannot reserve a File01 event or publish raw data');

$source = file_get_contents(__DIR__.'/../src/Infrastructure/WordPressOutboxTransport.php');
$expect(is_string($source) && str_contains($source, 'SPF_Event_Bus::publish(')
    && str_contains($source, '$accepted !== true'),
    'Delivery acknowledgement depends on durable File01 storage result');
$expect(!str_contains($source, 'do_action(self::EVENT_HOOK')
    && !str_contains($source, 'has_action(self::EVENT_HOOK'),
    'Legacy WordPress hook presence cannot masquerade as durable consumer ACK');

fwrite(STDOUT, "CF-03 File01 durable financial event-boundary: {$checks}/{$checks} assertions passed.\n");
