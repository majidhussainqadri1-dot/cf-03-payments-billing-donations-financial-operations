<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum IdempotencyStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
