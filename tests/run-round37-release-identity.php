<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$root=dirname(__DIR__);
$current='1.4.0-rc.5';
$schema='4.0.2';
$files=[
 'cf-03-payments-billing-donations-financial-operations.php',
 'src/Plugin.php',
 'src/Application/FutureExpansionRegistry.php',
 'manifests/cf03-release-1.4.0.json',
 'manifests/cf03-contracts.json',
 'manifests/cf03-future-expansion-40.json',
 'scripts/build-package.sh',
 'README.md','SECURITY.md','docs/requirements-traceability.md'
];
foreach($files as $file){
    $body=(string)file_get_contents($root.'/'.$file);
    if(!str_contains($body,$current)){
        throw new RuntimeException('Current rc.5 carrier missing from active release surface: '.$file);
    }
}
$release=json_decode((string)file_get_contents($root.'/manifests/cf03-release-1.4.0.json'),true,512,JSON_THROW_ON_ERROR);
$future=json_decode((string)file_get_contents($root.'/manifests/cf03-future-expansion-40.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['software_version']??null)!==$current||($release['schema_version']??null)!==$schema){
    throw new RuntimeException('Release manifest is not rc.5/schema-4.0.2.');
}
if(($future['software_target']??null)!=='1.4.0-rc.1'
    ||($future['original_schema_baseline']??null)!=='4.0.0'
    ||($future['current_hardened_carrier']??null)!==$current
){
    throw new RuntimeException('Future40 immutable origin/current-carrier separation is invalid.');
}
$readme=(string)file_get_contents($root.'/README.md');
if(str_contains($readme,'rc.3` is the current hardened successor candidate')){
    throw new RuntimeException('README still identifies an obsolete hardened carrier as current.');
}
fwrite(STDOUT,"PASS: fourth-cycle source bytes use distinct rc.5 carrier identity without changing schema law\n");
