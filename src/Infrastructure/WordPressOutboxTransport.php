<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\OutboxTransport;
use Sabri\CF03\Support\InvariantViolation;

final class WordPressOutboxTransport implements OutboxTransport
{
    public const EVENT_HOOK = 'sabri_cf03_financial_fact';

    public function publish(string $eventId, string $eventType, array $payload): void
    {
        // WordPress do_action() silently returns even when no consumer exists.
        // That must not be treated as a delivered financial fact.
        if (!function_exists('do_action') || !function_exists('has_action')) {
            throw new InvariantViolation('WordPress financial event transport is unavailable.');
        }
        if (has_action(self::EVENT_HOOK) === false) {
            throw new InvariantViolation('No registered financial fact consumer; event remains retryable.');
        }
        do_action(self::EVENT_HOOK, $eventType, $payload, $eventId);
    }
}
