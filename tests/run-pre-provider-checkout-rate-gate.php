<?php

declare(strict_types=1);

$GLOBALS['cf03_rl_options'] = [];
$GLOBALS['cf03_rl_fail_writes'] = false;
$GLOBALS['cf03_rl_salt'] = str_repeat('s', 64);
$GLOBALS['cf03_rl_new_option_flags'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, $GLOBALS['cf03_rl_options'])
        ? $GLOBALS['cf03_rl_options'][$name] : $default;
}

function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = false): bool
{
    if ($GLOBALS['cf03_rl_fail_writes'] || array_key_exists($name, $GLOBALS['cf03_rl_options'])) {
        return false;
    }
    $GLOBALS['cf03_rl_options'][$name] = $value;
    $GLOBALS['cf03_rl_new_option_flags'][$name] = $autoload;
    return true;
}

function delete_option(string $name): bool
{
    if (!array_key_exists($name, $GLOBALS['cf03_rl_options'])) {
        return false;
    }
    unset($GLOBALS['cf03_rl_options'][$name]);
    return true;
}

function wp_salt(string $scheme = 'auth'): string
{
    return $GLOBALS['cf03_rl_salt'];
}

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\CheckoutRateLimitExceeded;
use Sabri\CF03\Infrastructure\WordPressCheckoutRateGate as Gate;
use Sabri\CF03\Support\InvariantViolation;

$tests = 0;
$check = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};
$rejects = static function (callable $fn, string $class, string $label) use ($check): void {
    $rejected = false;
    try {
        $fn();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw $error;
        }
        $rejected = true;
    }
    $check($rejected, $label);
};

$at = new DateTimeImmutable('2026-11-15T10:03:00+00:00');
$actor = 'user:123';
$key1 = 'web-'.str_repeat('a', 48);
$key2 = 'web-'.str_repeat('b', 48);
$key3 = 'web-'.str_repeat('c', 48);

$check(!Gate::configured(), 'No owner-approved policy means pre-provider gate is not ready');
$rejects(static fn() => Gate::assertPermitted($actor, $key1, $at),
    InvariantViolation::class, 'A missing policy fails closed before payment provider');

$policy = [
    'approved' => true,
    'approved_by' => 'founder:financial-change-control',
    'approval_ref' => 'founder-approved-2026-11-15',
    'policy_version' => PlatformFinancialPolicy::DECISION_ID,
    'window_seconds' => 600,
    'max_new_intents' => 2,
];
$GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY] = $policy;
$check(Gate::configured(), 'Only signed-off policy format and durable WordPress functions enable gate');

Gate::assertPermitted($actor, $key1, $at);
$slots = static fn(): array => array_filter(
    array_keys($GLOBALS['cf03_rl_options']),
    static fn(string $name): bool => str_starts_with($name, Gate::PREFIX)
);
$check(count($slots()) === 1, 'First valid intent atomically occupies exactly one slot');
Gate::assertPermitted($actor, $key1, $at);
$check(count($slots()) === 1, 'Retry with identical idempotency key consumes no second slot');

Gate::assertPermitted($actor, $key2, $at);
$check(count($slots()) === 2, 'Independent intent consumes second owner-scoped quota slot');

$denied = null;
try {
    Gate::assertPermitted($actor, $key3, $at);
} catch (CheckoutRateLimitExceeded $exception) {
    $denied = $exception;
}
$check($denied instanceof CheckoutRateLimitExceeded,
    'Third independent intent gets explicit rate-limited exception');
$check($denied?->retryAfterSeconds() === 420,
    '429 retry-after is the remaining duration of the actual approved window');

Gate::assertPermitted('user:456', $key3, $at);
$check(count($slots()) === 3, 'Different registered user has independent quota');
Gate::assertPermitted('guest:'.str_repeat('a', 40), $key3, $at);
$check(count($slots()) === 4, 'Guest receives independently HMAC-scoped quota');

foreach ($slots() as $name) {
    $check(!str_contains($name, 'user:') && !str_contains($name, 'guest:'),
        'No direct user/guest identifiers are written in option keys');
    $check(($GLOBALS['cf03_rl_new_option_flags'][$name] ?? true) === false,
        'Rate reservations never autoload across website requests');
    $check(strlen((string)$GLOBALS['cf03_rl_options'][$name]) === 64,
        'Stored reservation is an opaque HMAC, not a raw idempotency key');
}

Gate::assertPermitted($actor, $key3, $at->modify('+10 minutes'));
$check(count($slots()) === 5, 'New time bucket does not inherit exhausted previous bucket');

$GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY]['approved_by'] = 'unknown:operator';
$check(!Gate::configured(), 'Arbitrary approver marker cannot activate native throttle');
$rejects(static fn() => Gate::assertPermitted('user:999', $key1, $at),
    InvariantViolation::class, 'Unapproved policy denies even an unused actor');
$GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY] = $policy;
$GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY]['window_seconds'] = 1;
$check(!Gate::configured(), 'Sub-minute or malformed policy is rejected');
$GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY] = $policy;
$GLOBALS['cf03_rl_salt'] = 'short';
$check(!Gate::configured(), 'Weak or missing WordPress HMAC key fails closed');
$rejects(static fn() => Gate::assertPermitted('user:999', $key1, $at),
    InvariantViolation::class, 'Weak secret cannot create a rate reservation');
$GLOBALS['cf03_rl_salt'] = str_repeat('s', 64);

$GLOBALS['cf03_rl_fail_writes'] = true;
$rejects(static fn() => Gate::assertPermitted('user:999', $key1, $at),
    CheckoutRateLimitExceeded::class, 'Failed atomic storage never enables a hosted provider call');
$GLOBALS['cf03_rl_fail_writes'] = false;

$rejects(static fn() => Gate::assertPermitted('user:0', $key1, $at),
    InvalidArgumentException::class, 'Untrusted subject identifiers are rejected');
$rejects(static fn() => Gate::assertPermitted($actor, 'bad', $at),
    InvalidArgumentException::class, 'Untrusted short request identities are rejected');

$wpdb = new class {
    public string $options = 'wp_options';
    public function esc_like(string $prefix): string { return addcslashes($prefix, '_%\\'); }
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_col(string $sql): array { return array_keys($GLOBALS['cf03_rl_options']); }
};
$futureExpiry = (string)($at->getTimestamp() + 604800);
$futureName = Gate::PREFIX.$futureExpiry.'_'.str_repeat('f', 40).'_00';
$GLOBALS['cf03_rl_options'][$futureName] = str_repeat('f', 64);
$before = count($slots());
$deleted = Gate::cleanExpired($at->modify('+2 days'), 100);
$check($deleted === $before - 1, 'Bounded GC removes expired historical slots only');
$check(isset($GLOBALS['cf03_rl_options'][$futureName]),
    'GC leaves future-dated slot untouched');
$check(isset($GLOBALS['cf03_rl_options'][Gate::OPTION_POLICY]),
    'GC never touches the approved policy record');

$api = file_get_contents(__DIR__.'/../src/Infrastructure/WordPressRestApi.php');
$scheduler = file_get_contents(__DIR__.'/../src/Infrastructure/WordPressScheduler.php');
$check(is_string($api)
    && strpos($api, 'WordPressCheckoutRateGate::assertPermitted(') !== false
    && strpos($api, 'WordPressCheckoutRateGate::assertPermitted(') <
       strpos($api, 'new DonationIntentDraft('),
    'Rate limit admission occurs before any donation intent or provider side effect');
$check(str_contains((string)$api, 'WordPressCheckoutRateGate::configured()'),
    'Public readiness also remains false until native rate policy is approved');
$check(str_contains((string)$api, "'Retry-After'") && str_contains((string)$api, '429'),
    'Rate-limited request yields an explicit 429 Retry-After response');
$check(str_contains((string)$scheduler, 'WordPressCheckoutRateGate::cleanExpired('),
    'Existing five-minute queue performs bounded, privacy-preserving cleanup');

fwrite(STDOUT, "CF-03 pre-provider atomic checkout rate gate: {$tests}/{$tests} assertions passed.\n");
