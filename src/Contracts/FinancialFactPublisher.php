<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Integration\File00FinancialFact;

interface FinancialFactPublisher
{
    public function publish(File00FinancialFact $fact): void;
}
