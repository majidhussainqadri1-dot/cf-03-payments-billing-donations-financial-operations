<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DomainException;

/** A bounded local pre-provider rejection, not a payment failure. */
final class CheckoutRateLimitExceeded extends DomainException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many new donation attempts. Try again later.');
        if ($retryAfterSeconds < 1 || $retryAfterSeconds > 3600) {
            throw new \InvalidArgumentException('Retry-after boundary is invalid.');
        }
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
