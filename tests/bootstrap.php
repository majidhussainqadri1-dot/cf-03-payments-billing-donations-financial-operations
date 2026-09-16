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

require_once dirname(__DIR__) . '/src/Autoloader.php';
\Sabri\CF03\Autoloader::register(dirname(__DIR__) . '/src');
