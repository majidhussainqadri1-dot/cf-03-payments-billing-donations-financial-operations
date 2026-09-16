<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$root = dirname(__DIR__);
$tests = [];

$tests['every executable test suite is explicitly current or historical'] = static function (): void {
    $files = array_map('basename', glob(__DIR__.'/run*.php') ?: []);
    sort($files);
    $classified = array_values(array_unique(array_merge(CF03_ACTIVE_TEST_SUITES, CF03_HISTORICAL_TEST_SUITES)));
    sort($classified);
    if ($files !== $classified) {
        throw new RuntimeException('Test suite inventory contains unclassified or missing executable suites.');
    }
};

$tests['composer current acceptance executes every active suite and no historical suite'] = static function () use ($root): void {
    $composer = json_decode((string)file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $commands = $composer['scripts']['test'] ?? [];
    if (!is_array($commands)) { throw new RuntimeException('Composer current acceptance script is invalid.'); }
    $text = implode("\n", array_map('strval', $commands));
    foreach (CF03_ACTIVE_TEST_SUITES as $suite) {
        if (!str_contains($text, 'php tests/'.$suite)) {
            throw new RuntimeException('Composer acceptance omits active suite '.$suite.'.');
        }
    }
    foreach (CF03_HISTORICAL_TEST_SUITES as $suite) {
        if (str_contains($text, 'php tests/'.$suite)) {
            throw new RuntimeException('Composer acceptance executes historical suite '.$suite.'.');
        }
    }
};

$tests['github current acceptance executes every active suite and no historical suite'] = static function () use ($root): void {
    $workflow = (string)file_get_contents($root.'/.github/workflows/ci.yml');
    foreach (CF03_ACTIVE_TEST_SUITES as $suite) {
        if (!str_contains($workflow, 'php tests/'.$suite)) {
            throw new RuntimeException('GitHub CI omits active suite '.$suite.'.');
        }
    }
    foreach (CF03_HISTORICAL_TEST_SUITES as $suite) {
        if (str_contains($workflow, 'php tests/'.$suite)) {
            throw new RuntimeException('GitHub CI executes historical suite '.$suite.'.');
        }
    }
};

$tests['historical suites are fail-safe provenance rather than runnable acceptance truth'] = static function (): void {
    foreach (CF03_HISTORICAL_TEST_SUITES as $suite) {
        if (!is_file(__DIR__.'/'.$suite)) {
            throw new RuntimeException('Historical provenance suite is missing: '.$suite.'.');
        }
    }
    $bootstrap = (string)file_get_contents(__DIR__.'/bootstrap.php');
    if (!str_contains($bootstrap, 'HISTORICAL/SUPERSEDED') || !str_contains($bootstrap, 'CF03_HISTORICAL_TEST_SUITES')) {
        throw new RuntimeException('Historical suite quarantine guard is missing.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS: {$name}\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, count($tests)." tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
