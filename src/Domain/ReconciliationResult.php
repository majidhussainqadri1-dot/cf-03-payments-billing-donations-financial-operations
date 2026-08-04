<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class ReconciliationResult
{
    /** @param list<array{type:string,reference:string,expected:int,actual:int,currency:string,material:bool}> $exceptions */
    public function __construct(private readonly array $exceptions)
    {
        foreach ($exceptions as $exception) {
            foreach (['type','reference','currency'] as $field) {
                if (trim((string) $exception[$field]) === '') { throw new InvariantViolation('Reconciliation exception is incomplete.'); }
            }
        }
    }

    public function mayClose(): bool
    {
        foreach ($this->exceptions as $exception) {
            if ($exception['material']) { return false; }
        }
        return true;
    }

    public function assertClosable(): void
    {
        if (! $this->mayClose()) { throw new InvariantViolation('Material reconciliation exceptions block close.'); }
    }

    public function count(): int { return count($this->exceptions); }
}
