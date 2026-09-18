<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$source = (string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinanceAdminApi.php');
$methods = [
    'openFraud','decideFraud','appealFraud','openChargeback',
    'submitChargebackEvidence','chargebackOutcome','adjustChargeback',
];
foreach ($methods as $method) {
    $start = strpos($source, 'public static function '.$method.'(');
    if ($start === false) {
        throw new RuntimeException('Missing privileged risk method '.$method.'.');
    }
    $next = strpos($source, 'public static function ', $start + 25);
    $body = substr($source, $start, $next === false ? null : $next - $start);
    if (!str_contains($body, '->transaction(') || !str_contains($body, 'appendAudit')) {
        throw new RuntimeException('Risk mutation '.$method.' must commit its mutation and immutable audit in one repository transaction.');
    }
}
$helper = strpos($source, 'private static function auditedRisk(');
if ($helper === false) {
    throw new RuntimeException('Shared audited risk helper is missing.');
}
$helperBody = substr($source, $helper, 2500);
if (!str_contains($helperBody, '->transaction(') || !str_contains($helperBody, 'appendAudit')) {
    throw new RuntimeException('Shared risk lifecycle helper must be transactionally audited.');
}

fwrite(STDOUT, "PASS: privileged fraud and chargeback mutations are atomic with immutable audit evidence\n");
