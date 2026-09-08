<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class LedgerTransaction
{
    /** @var list<LedgerEntry> */
    private readonly array $entries;

    /** @param list<LedgerEntry> $entries */
    public function __construct(
        private readonly string $transactionId,
        array $entries
    ) {
        if ($transactionId === '') {
            throw new InvalidArgumentException('Ledger transaction ID is required.');
        }

        if (count($entries) < 2) {
            throw new InvalidArgumentException('A ledger transaction requires at least two entries.');
        }

        foreach ($entries as $entry) {
            if (! $entry instanceof LedgerEntry) {
                throw new InvalidArgumentException('Every ledger transaction item must be a LedgerEntry.');
            }
        }

        $this->entries = array_values($entries);
        $this->assertBalanced();
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    /** @return list<LedgerEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<string,array{debit:int,credit:int}> */
    public function totalsByCurrency(): array
    {
        $totals = [];
        foreach ($this->entries as $entry) {
            $currency = $entry->amount()->currency();
            $totals[$currency] ??= ['debit' => 0, 'credit' => 0];
            $direction = $entry->direction();
            $amount = $entry->amount()->minorUnits();
            if ($amount > PHP_INT_MAX - $totals[$currency][$direction]) {
                throw new InvariantViolation('Ledger totals exceed supported integer range.');
            }
            $totals[$currency][$direction] += $amount;
        }
        ksort($totals);
        return $totals;
    }

    public function assertRepresents(Money $amount): void
    {
        $totals = $this->totalsByCurrency();
        if (count($totals) !== 1
            || ! isset($totals[$amount->currency()])
            || $totals[$amount->currency()]['debit'] !== $amount->minorUnits()
            || $totals[$amount->currency()]['credit'] !== $amount->minorUnits()
        ) {
            throw new InvariantViolation('Ledger transaction amount and currency do not match the financial aggregate.');
        }
    }

    public function assertBalanced(): void
    {
        foreach ($this->totalsByCurrency() as $currency => $total) {
            if ($total['debit'] !== $total['credit']) {
                throw new InvariantViolation(sprintf(
                    'Ledger transaction is unbalanced for %s.',
                    $currency
                ));
            }
        }
    }
}
