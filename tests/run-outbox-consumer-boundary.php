<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressOutboxTransport;
use Sabri\CF03\Support\InvariantViolation;

$GLOBALS['cf03_test_has_subscriber'] = false;
$GLOBALS['cf03_test_sent'] = [];
function has_action(string $hook): int|false {
    return $hook === WordPressOutboxTransport::EVENT_HOOK && $GLOBALS['cf03_test_has_subscriber'] ? 10 : false;
}
function do_action(string $hook, ...$arguments): void {
    $GLOBALS['cf03_test_sent'][] = [$hook, $arguments];
}
$checks = 0;
$assert = static function (bool $yes, string $label) use (&$checks): void {
    $checks++;
    if (!$yes) { throw new RuntimeException('FAIL: '.$label); }
};
$transport = new WordPressOutboxTransport();
$blocked = false;
try {
    $transport->publish('evt:no:consumer', 'DonationSettled.v1', ['amount_minor'=>100]);
} catch (InvariantViolation) { $blocked = true; }
$assert($blocked, 'Unsubscribed outbox event fails instead of claiming delivery');
$assert($GLOBALS['cf03_test_sent'] === [], 'No fake publication when subscriber absent');

$GLOBALS['cf03_test_has_subscriber'] = true;
$transport->publish('evt:consumer:1', 'DonationSettled.v1', ['donation_type'=>'one_time']);
$assert(count($GLOBALS['cf03_test_sent']) === 1, 'Subscribed hook is invoked exactly once');
$assert($GLOBALS['cf03_test_sent'][0][0] === WordPressOutboxTransport::EVENT_HOOK, 'Only approved financial hook invoked');
$assert($GLOBALS['cf03_test_sent'][0][1][0] === 'DonationSettled.v1', 'Versioned fact type preserved');
$assert($GLOBALS['cf03_test_sent'][0][1][2] === 'evt:consumer:1', 'Stable idempotency event ID reaches consumer');
$assert($GLOBALS['cf03_test_sent'][0][1][1]['donation_type'] === 'one_time', 'Financial-only payload passed unchanged');
fwrite(STDOUT, "CF-03 WordPress finance consumer boundary: {$checks}/{$checks} checks passed.\n");
