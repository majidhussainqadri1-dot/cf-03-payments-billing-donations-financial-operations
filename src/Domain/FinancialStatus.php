<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum FinancialStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case QUARANTINED = 'quarantined';
    case CLOSED = 'closed';
}
