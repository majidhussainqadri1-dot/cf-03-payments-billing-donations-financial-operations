<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

interface OutboxTransport
{
    /** @param array<string,mixed> $payload */
    public function publish(string $eventId, string $eventType, array $payload): void;
}
