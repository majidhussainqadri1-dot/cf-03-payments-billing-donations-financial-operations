<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$root=dirname(__DIR__);
$current='1.4.0-rc.5';
$security=(string)file_get_contents($root.'/SECURITY.md');
if(!str_contains($security,"CF-03 `{$current}` is the current repository-source candidate")){
    throw new RuntimeException('Security policy does not identify the exact current source carrier.');
}

$workflow=(string)file_get_contents($root.'/.github/workflows/ci.yml');
foreach([
    "Version: {$current}",
    "SOFTWARE_TARGET = '{$current}'",
    "current_hardened_carrier\": \"{$current}",
    "cf-03-payments-billing-donations-financial-operations-{$current}.zip",
] as $needle){
    if(!str_contains($workflow,$needle)){
        throw new RuntimeException('Final release sweep found CI carrier drift: '.$needle);
    }
}

$plugin=(string)file_get_contents($root.'/cf-03-payments-billing-donations-financial-operations.php');
$manifest=json_decode((string)file_get_contents($root.'/manifests/cf03-release-1.4.0.json'),true,512,JSON_THROW_ON_ERROR);
if(!str_contains($plugin,"Version: {$current}")||($manifest['software_version']??null)!==$current){
    throw new RuntimeException('Plugin and release manifest carrier identities diverge.');
}

foreach([
    'src/Application/SubscriptionOperationsService.php',
    'src/Application/AiUsageBillingService.php',
] as $file){
    $body=(string)file_get_contents($root.'/'.$file);
    if(!str_contains($body,'retired')){
        throw new RuntimeException('Final sweep found a retired financial runtime without explicit fail-closed tombstone: '.$file);
    }
}

fwrite(STDOUT,"PASS: final cross-layer carrier/security/package sweep is internally consistent\n");
