<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$root=dirname(__DIR__);
$current='1.4.0-rc.5';
$schema='4.0.2';
$checks=[
    'cf-03-payments-billing-donations-financial-operations.php'=>["Version: {$current}","SABRI_CF03_VERSION', '{$current}'","SABRI_CF03_SCHEMA_VERSION', '{$schema}'"],
    'src/Plugin.php'=>[$current],
    'src/Application/FutureExpansionRegistry.php'=>["SOFTWARE_TARGET = '{$current}'"],
    'manifests/cf03-release-1.4.0.json'=>["\"software_version\": \"{$current}\"","\"schema_version\": \"{$schema}\""],
    'manifests/cf03-contracts.json'=>["\"software_version\": \"{$current}\"","\"active_schema_version\": \"{$schema}\""],
    'manifests/cf03-future-expansion-40.json'=>["\"current_hardened_carrier\": \"{$current}\""],
    'scripts/build-package.sh'=>["VERSION=\"{$current}\""],
    'README.md'=>["Current source candidate:** `{$current}`"],
    'docs/requirements-traceability.md'=>[$current],
];
foreach($checks as $file=>$needles){
    $body=(string)file_get_contents($root.'/'.$file);
    foreach($needles as $needle){
        if(!str_contains($body,$needle)){
            throw new RuntimeException('Current carrier identity drift in '.$file.': '.$needle);
        }
    }
}
$future=json_decode((string)file_get_contents($root.'/manifests/cf03-future-expansion-40.json'),true,512,JSON_THROW_ON_ERROR);
if(($future['software_target']??null)!=='1.4.0-rc.1'||($future['original_schema_baseline']??null)!=='4.0.0'){
    throw new RuntimeException('Future40 amendment origin must remain immutable while carrier advances.');
}
fwrite(STDOUT,"PASS: post-rc.4 source bytes have a distinct rc.4 carrier identity across active release surfaces\n");
