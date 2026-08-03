<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class PaymentIntentTransition
{
    /** @var array<string,list<string>> */
    private const ALLOWED = [
        'created' => ['provider_pending', 'cancelled', 'expired'],
        'provider_pending' => ['authorized', 'captured', 'settled', 'failed', 'cancelled', 'expired', 'quarantined'],
        'authorized' => ['captured', 'settled', 'failed', 'cancelled', 'expired', 'quarantined'],
        'captured' => ['settled', 'refunded', 'disputed', 'quarantined'],
        'settled' => ['refunded', 'disputed'],
        'quarantined' => ['provider_pending', 'failed', 'cancelled'],
        'failed' => [],
        'cancelled' => [],
        'expired' => [],
        'refunded' => ['disputed'],
        'disputed' => ['refunded'],
    ];

    public function assertAllowed(PaymentIntentState $from, PaymentIntentState $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::ALLOWED[$from->value] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new InvariantViolation(sprintf(
                'Invalid payment-intent transition from %s to %s.',
                $from->value,
                $to->value
            ));
        }
    }
}
