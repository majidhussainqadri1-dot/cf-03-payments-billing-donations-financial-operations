<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressPrivacy;

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, string $value): object|false
    {
        return (object)['ID' => 1501];
    }
}

$export = WordPressPrivacy::export('privacy@example.test', 1);
if (($export['done'] ?? true) !== false) {
    throw new RuntimeException('A failed privacy export read must not be reported as complete.');
}
if (($export['data'][0]['data'][0]['value'] ?? '') === '') {
    throw new RuntimeException('Privacy export failure must surface a safe status message.');
}

$erase = WordPressPrivacy::erase('privacy@example.test', 1);
if (($erase['done'] ?? true) !== false) {
    throw new RuntimeException('A failed donor-acknowledgment erasure must remain incomplete for retry.');
}
if (($erase['items_retained'] ?? false) !== true) {
    throw new RuntimeException('Privacy erasure must still disclose retained financial evidence.');
}

fwrite(STDOUT, "PASS: privacy export and erasure infrastructure failures do not falsely declare completion\n");
