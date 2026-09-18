<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$source=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressPublicUi.php');
foreach([
    "private static function language(): string",
    "$direction = $language === 'ur' ? 'rtl' : 'ltr';",
    "lang="'.esc_attr($language).'" dir="'.esc_attr($direction).'"",
    "$publicDisclosure[$language] ?? $publicDisclosure['en-US']",
] as $needle){
    if(!str_contains($source,$needle)){
        throw new RuntimeException('RTL/LTR public rendering contract is missing: '.$needle);
    }
}
if(str_contains($source,'dir="auto"')){
    throw new RuntimeException('Financial UI must not depend on first-character dir=auto inference for Urdu pages.');
}
if(str_contains($source,"publicDisclosure()['en-US']")){
    throw new RuntimeException('Transparency disclosure must not hard-code English on Urdu locale.');
}

fwrite(STDOUT,"PASS: public financial UI has deterministic locale-aware RTL/LTR structure\n");
