<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\LedgerTransaction;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class LedgerJournal
{
    /** @var array<string,array<string,mixed>> */
    private array $transactions = [];

    /** @var array<string,string> */
    private array $sourceIndex = [];

    public function post(
        LedgerTransaction $transaction,
        string $sourceType,
        string $sourceReference,
        string $actorReference,
        string $reason,
        string $periodId,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $recordedAt,
        ?string $reversalOf = null
    ): void {
        foreach ([$sourceType, $sourceReference, $actorReference, $reason, $periodId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Ledger posting metadata is required.');
            }
        }
        if ($recordedAt < $effectiveAt) {
            throw new InvalidArgumentException('Ledger recorded time cannot precede effective time.');
        }
        $transaction->assertBalanced();
        if (isset($this->transactions[$transaction->transactionId()])) {
            throw new InvariantViolation('Ledger transaction ID is immutable and unique.');
        }
        $sourceKey = $sourceType . '|' . $sourceReference;
        if (isset($this->sourceIndex[$sourceKey])) {
            throw new InvariantViolation('Ledger source may post only once; retry must return the original transaction.');
        }
        if ($reversalOf !== null && ! isset($this->transactions[$reversalOf])) {
            throw new InvariantViolation('Ledger reversal requires an existing original transaction.');
        }

        $this->transactions[$transaction->transactionId()] = [
            'transaction' => $transaction,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'actor_reference' => $actorReference,
            'reason' => $reason,
            'period_id' => $periodId,
            'effective_at' => $effectiveAt,
            'recorded_at' => $recordedAt,
            'reversal_of' => $reversalOf,
        ];
        $this->sourceIndex[$sourceKey] = $transaction->transactionId();
    }

    public function assertNoUnbalancedPosting(): void
    {
        foreach ($this->transactions as $record) {
            $record['transaction']->assertBalanced();
        }
    }

    public function findBySource(string $sourceType, string $sourceReference): ?LedgerTransaction
    {
        $id = $this->sourceIndex[$sourceType . '|' . $sourceReference] ?? null;
        return $id === null ? null : $this->transactions[$id]['transaction'];
    }

    /** @return array<string,int> */
    public function accountBalances(string $currency): array
    {
        $balances = [];
        foreach ($this->transactions as $record) {
            /** @var LedgerTransaction $transaction */
            $transaction = $record['transaction'];
            foreach ($transaction->entries() as $entry) {
                if ($entry->amount()->currency() !== $currency) {
                    continue;
                }
                $signed = $entry->direction() === 'debit'
                    ? $entry->amount()->minorUnits()
                    : -$entry->amount()->minorUnits();
                $balances[$entry->account()] = ($balances[$entry->account()] ?? 0) + $signed;
            }
        }
        ksort($balances);
        return $balances;
    }

    public function transactionCount(): int
    {
        return count($this->transactions);
    }
}
