<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum PaymentIntentState: string
{
    case CREATED = 'created';
    case PROVIDER_PENDING = 'provider_pending';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
    case DISPUTED = 'disputed';
    case QUARANTINED = 'quarantined';
}
