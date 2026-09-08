<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum AuditOutcome: string
{
    case SUCCEEDED = 'succeeded';
    case DENIED = 'denied';
    case FAILED = 'failed';
    case QUARANTINED = 'quarantined';
}
