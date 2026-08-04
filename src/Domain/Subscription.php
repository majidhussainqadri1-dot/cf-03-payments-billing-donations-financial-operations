<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class Subscription
{
    private const ALLOWED = [
        'pending' => ['active', 'cancelled', 'expired'],
        'active' => ['past_due', 'cancelled'],
        'past_due' => ['grace', 'active', 'cancelled', 'expired'],
        'grace' => ['active', 'cancelled', 'expired'],
        'cancelled' => ['active', 'expired'],
        'expired' => [],
    ];

    public function __construct(
        private readonly string $subscriptionId,
        private readonly string $ownerReference,
        private readonly string $productId,
        private readonly string $priceVersionId,
        private string $state,
        private int $version = 1
    ) {
        foreach ([$subscriptionId, $ownerReference, $productId, $priceVersionId] as $value) {
            if (trim($value) === '') { throw new InvalidArgumentException('Subscription fields are required.'); }
        }
        if (! array_key_exists($state, self::ALLOWED)) { throw new InvalidArgumentException('Unknown subscription state.'); }
    }

    public function transition(string $next, int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version) { throw new InvariantViolation('Stale subscription version.'); }
        if ($next === $this->state) { return; }
        if (! in_array($next, self::ALLOWED[$this->state], true)) { throw new InvariantViolation('Invalid subscription transition.'); }
        $this->state = $next;
        $this->version++;
    }

    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
}
