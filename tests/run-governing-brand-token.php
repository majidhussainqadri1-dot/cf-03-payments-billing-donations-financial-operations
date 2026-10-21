<?php
declare(strict_types=1);

$css = file_get_contents(__DIR__.'/../assets/css/public.css');
if (!is_string($css) || $css === '') {
    throw new RuntimeException('CF-03 public stylesheet is missing.');
}
$checks = 0;
$assert = static function (bool $ok, string $msg) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL: '.$msg); }
};
$assert(str_contains($css, '--sabri-green:#087A4E'), 'Sabri Green agrees with current governing central plan');
$assert(!str_contains(strtolower($css), '#187a3d'), 'Superseded module-specific green is removed');
$assert(str_contains($css, 'var(--sabri-green)'), 'Donation UI reuses the single local primary token');
$assert(str_contains($css, ':focus-visible'), 'Keyboard focus remains explicit');
$assert(str_contains($css, 'prefers-reduced-motion'), 'Motion-safety accessibility guard remains');
$assert(str_contains($css, 'max-width:380px'), 'Mobile 320–380px layout breakpoint remains');
fwrite(STDOUT, "CF-03 governing brand token: {$checks}/{$checks} checks passed.\n");
