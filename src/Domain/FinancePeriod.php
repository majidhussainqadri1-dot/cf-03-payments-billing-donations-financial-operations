<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancePeriod
{
    public function __construct(
        private readonly string $periodId,
        private bool $closed = false,
        private ?string $closedBy = null
    ) {
        if (trim($periodId) === '') { throw new InvalidArgumentException('Period ID is required.'); }
    }

    public function close(ReconciliationResult $result, string $approver): void
    {
        if ($this->closed) { return; }
        $result->assertClosable();
        if (trim($approver) === '') { throw new InvalidArgumentException('Approver is required.'); }
        $this->closed = true;
        $this->closedBy = $approver;
    }

    public function assertWritable(): void
    {
        if ($this->closed) { throw new InvariantViolation('Closed periods are immutable; use next-period adjustment.'); }
    }

    public function closed(): bool { return $this->closed; }
}
