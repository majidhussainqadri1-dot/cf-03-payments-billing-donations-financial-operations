<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$ui=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressPublicUi.php');
foreach([
    'sabri-cf03-preaction-disclosure',
    'Purpose: institutional sustainability',
    'Secure hosted payment provider:',
    'adds no separate platform fee or tax',
    'Refund/Support:',
    'Data use:',
    'card/PAN/CVV/PIN/OTP',
    'disabled aria-disabled="true"',
    '<noscript>',
] as $needle){
    if(!str_contains($ui,$needle)){
        throw new RuntimeException('Public donation pre-action disclosure/fail-closed UI is missing: '.$needle);
    }
}
if(!str_contains($ui,"data-runtime-enabled=") || !str_contains($ui,"$collectionEnabled ? '1' : '0'")){
    throw new RuntimeException('Donation form must expose server-authorized runtime state without enabling submit itself.');
}

$js=(string)file_get_contents(dirname(__DIR__).'/assets/js/public.js');
foreach([
    'data-runtime-enabled="1"',
    "cfg.collectionEnabled===true&&submit",
    "submit.setAttribute('aria-disabled','false')",
] as $needle){
    if(!str_contains($js,$needle)){
        throw new RuntimeException('JavaScript must explicitly hydrate the fail-closed donation submit control: '.$needle);
    }
}

$css=(string)file_get_contents(dirname(__DIR__).'/assets/css/public.css');
if(!str_contains($css,'.sabri-cf03-disclosure')){
    throw new RuntimeException('Pre-action disclosure requires a readable public style hook.');
}

fwrite(STDOUT,"PASS: donation UI discloses FR-003 terms and remains fail closed without JavaScript\n");
