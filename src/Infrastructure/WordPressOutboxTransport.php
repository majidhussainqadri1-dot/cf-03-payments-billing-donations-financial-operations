<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Support\InvariantViolation;

final class WordPressOutboxTransport implements OutboxTransport
{
    public function publish(string $eventId,string $eventType,array $payload): void
    {
        if (!function_exists('do_action')) { throw new InvariantViolation('WordPress event transport is unavailable.'); }
        do_action('sabri_cf03_financial_fact',$eventType,$payload,$eventId);
    }
}
