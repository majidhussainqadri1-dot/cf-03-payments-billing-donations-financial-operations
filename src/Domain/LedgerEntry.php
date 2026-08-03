<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

final class LedgerEntry
{
    public const DEBIT = 'debit';
    public const CREDIT = 'credit';

    public function __construct(
        private readonly string $account,
        private readonly string $direction,
        private readonly Money $amount,
        private readonly string $sourceReference
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $account)) {
            throw new InvalidArgumentException('Ledger account identifier is invalid.');
        }

        if (! in_array($direction, [self::DEBIT, self::CREDIT], true)) {
            throw new InvalidArgumentException('Ledger direction must be debit or credit.');
        }

        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Ledger entries must contain a positive amount.');
        }

        if ($sourceReference === '') {
            throw new InvalidArgumentException('Ledger source reference is required.');
        }
    }

    public function account(): string
    {
        return $this->account;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function sourceReference(): string
    {
        return $this->sourceReference;
    }
}
