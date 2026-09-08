<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum TaxMode: string
{
    case INCLUDED = 'included';
    case EXCLUDED = 'excluded';
    case NOT_APPLICABLE = 'not_applicable';
}
