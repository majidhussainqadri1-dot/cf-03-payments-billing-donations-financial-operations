<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Domain\AuditEnvelope;

interface AuditSink
{
    public function append(AuditEnvelope $event): void;
}
