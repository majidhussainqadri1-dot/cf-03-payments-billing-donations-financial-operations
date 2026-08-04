<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class ReconciliationResult
{
    /** @var list<array{type:string,reference:string,expected:int,actual:int,currency:string,material:bool}> */
    private readonly array $exceptions;

    /** @param list<array{type:string,reference:string,expected:int,actual:int,currency:string,material:bool}> $exceptions */
    public function __construct(array $exceptions)
    {
        $normalized = [];
        $seen = [];
        foreach ($exceptions as $exception) {
            if (! is_array($exception)
                || array_keys($exception) !== ['type', 'reference', 'expected', 'actual', 'currency', 'material']
                || ! is_string($exception['type'])
                || ! is_string($exception['reference'])
                || ! is_int($exception['expected'])
                || ! is_int($exception['actual'])
                || ! is_string($exception['currency'])
                || ! is_bool($exception['material'])
                || preg_match('/^[a-z][a-z0-9._:-]{2,63}$/', $exception['type']) !== 1
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $exception['reference']) !== 1
                || $exception['expected'] < 0
                || $exception['actual'] < 0
                || preg_match('/^[A-Z]{3}$/', $exception['currency']) !== 1
            ) {
                throw new InvalidArgumentException('Reconciliation exception is malformed.');
            }
            $key = implode('|', [$exception['type'], $exception['reference'], $exception['currency']]);
            if (isset($seen[$key])) {
                throw new InvariantViolation('Duplicate reconciliation exceptions are prohibited.');
            }
            $seen[$key] = true;
            $normalized[] = $exception;
        }
        $this->exceptions = $normalized;
    }

    public function mayClose(): bool
    {
        foreach ($this->exceptions as $exception) {
            if ($exception['material']) {
                return false;
            }
        }
        return true;
    }

    public function assertClosable(): void
    {
        if (! $this->mayClose()) {
            throw new InvariantViolation('Material reconciliation exceptions block close.');
        }
    }

    public function count(): int { return count($this->exceptions); }

    /** @return list<array{type:string,reference:string,expected:int,actual:int,currency:string,material:bool}> */
    public function exceptions(): array { return $this->exceptions; }
}
