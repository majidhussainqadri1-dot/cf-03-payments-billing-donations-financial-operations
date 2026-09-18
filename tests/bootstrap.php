<?php

declare(strict_types=1);

const CF03_ACTIVE_TEST_SUITES = [
    'run.php',
    'run-new-governing-plans.php',
    'run-new-governing-plans-adversarial.php',
    'run-webhook-quarantine-recovery.php',
    'run-donation-velocity-guard.php',
    'run-round5-schema-retention.php',
    'run-round6-privacy-secure-export.php',
    'run-round7-concurrency-outbox.php',
    'run-round8-runtime-delivery-gates.php',
    'run-round9-governance-security.php',
    'run-round10-identity-manifest.php',
    'run-round12-accounting-integrity.php',
    'run-round13-audit-order.php',
    'run-round14-export-revocation.php',
    'run-round15-privacy-completion.php',
    'run-round16-risk-audit-atomicity.php',
    'run-round17-retention-external-atomicity.php',
    'run-round18-settlement-close-integrity.php',
    'run-release-inventory.php',
    'run-future-expansion-40.php',
];

const CF03_HISTORICAL_TEST_SUITES = [
    'run-0.2.php',
    'run-1.0.php',
    'run-adversarial-2.php',
    'run-adversarial-source.php',
    'run-founder-donation-transparency.php',
    'run-free-donation-policy.php',
    'run-plan-completion.php',
    'run-review-40.php',
    'run-review-40-second.php',
    'run-review-40-third.php',
    'run-review-40-fourth.php',
    'run-runtime-completion.php',
    'run-source-completion.php',
    'run-three-plan-harmonization.php',
];

$cf03Script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (in_array($cf03Script, CF03_HISTORICAL_TEST_SUITES, true)) {
    fwrite(STDOUT, "HISTORICAL/SUPERSEDED: {$cf03Script} is retained for provenance only and is not current CF-03 acceptance truth.\n");
    exit(0);
}

$cf03Executable = array_map('basename', glob(__DIR__.'/run*.php') ?: []);
sort($cf03Executable);
$cf03Classified = array_values(array_unique(array_merge(CF03_ACTIVE_TEST_SUITES, CF03_HISTORICAL_TEST_SUITES)));
sort($cf03Classified);
if ($cf03Executable !== $cf03Classified) {
    throw new RuntimeException('CF-03 executable test inventory contains an unclassified or missing suite.');
}

$cf03Root = dirname(__DIR__);
$cf03Composer = json_decode((string)file_get_contents($cf03Root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$cf03Commands = $cf03Composer['scripts']['test'] ?? [];
if (!is_array($cf03Commands)) {
    throw new RuntimeException('CF-03 Composer current acceptance script is invalid.');
}
$cf03CommandText = implode("\n", array_map('strval', $cf03Commands));
foreach (CF03_ACTIVE_TEST_SUITES as $cf03Suite) {
    if (!str_contains($cf03CommandText, 'php tests/'.$cf03Suite)) {
        throw new RuntimeException('CF-03 Composer acceptance omits active suite '.$cf03Suite.'.');
    }
}
foreach (CF03_HISTORICAL_TEST_SUITES as $cf03Suite) {
    if (str_contains($cf03CommandText, 'php tests/'.$cf03Suite)) {
        throw new RuntimeException('CF-03 Composer acceptance executes historical suite '.$cf03Suite.'.');
    }
}

$cf03Workflow = (string)file_get_contents($cf03Root.'/.github/workflows/ci.yml');
foreach (CF03_HISTORICAL_TEST_SUITES as $cf03Suite) {
    if (str_contains($cf03Workflow, 'php tests/'.$cf03Suite)) {
        throw new RuntimeException('CF-03 GitHub CI executes historical suite '.$cf03Suite.'.');
    }
}

require_once $cf03Root . '/src/Autoloader.php';
\Sabri\CF03\Autoloader::register($cf03Root . '/src');
