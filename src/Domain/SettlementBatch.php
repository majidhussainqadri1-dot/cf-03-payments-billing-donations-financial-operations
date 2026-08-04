<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class SettlementBatch
{
    /** @var list<array{reference:string,type:string,amount_minor:int,currency:string}> */
    private array $lines;

    /**
     * @param list<array{reference:string,type:string,amount_minor:int,currency:string}> $lines
     */
    public function __construct(
        private readonly string $batchId,
        private readonly string $providerCode,
        private readonly Money $gross,
        private readonly Money $fees,
        private readonly Money $refunds,
        private readonly Money $net,
        private readonly DateTimeImmutable $settledAt,
        private readonly string $sourceSha256,
        array $lines
    ) {
        foreach ([$batchId, $providerCode] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Settlement reference is invalid.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sourceSha256) !== 1) {
            throw new InvalidArgumentException('Settlement source hash is invalid.');
        }
        if ($gross->currency() !== $fees->currency()
            || $gross->currency() !== $refunds->currency()
            || $gross->currency() !== $net->currency()
        ) {
            throw new InvariantViolation('Settlement totals require one currency.');
        }
        if ($fees->minorUnits() + $refunds->minorUnits() > $gross->minorUnits()) {
            throw new InvariantViolation('Settlement fees and refunds cannot exceed gross.');
        }
        $expectedNet = $gross->minorUnits() - $fees->minorUnits() - $refunds->minorUnits();
        if ($net->minorUnits() !== $expectedNet) {
            throw new InvariantViolation('Settlement net does not equal gross less fees and refunds.');
        }

        $seen = [];
        $lineGross = 0;
        $lineFees = 0;
        $lineRefunds = 0;
        foreach ($lines as $line) {
            if (! is_array($line)
                || ! isset($line['reference'], $line['type'], $line['amount_minor'], $line['currency'])
                || ! is_string($line['reference'])
                || ! is_string($line['type'])
                || ! is_int($line['amount_minor'])
                || ! is_string($line['currency'])
            ) {
                throw new InvalidArgumentException('Settlement line is invalid.');
            }
            if ($line['amount_minor'] < 0 || strtoupper($line['currency']) !== $gross->currency()) {
                throw new InvalidArgumentException('Settlement line amount or currency is invalid.');
            }
            if (isset($seen[$line['reference']])) {
                throw new InvariantViolation('Settlement line reference is duplicated.');
            }
            $seen[$line['reference']] = true;
            match ($line['type']) {
                'payment' => $lineGross += $line['amount_minor'],
                'fee' => $lineFees += $line['amount_minor'],
                'refund' => $lineRefunds += $line['amount_minor'],
                default => throw new InvalidArgumentException('Unknown settlement line type.'),
            };
        }
        if ($lineGross !== $gross->minorUnits()
            || $lineFees !== $fees->minorUnits()
            || $lineRefunds !== $refunds->minorUnits()
        ) {
            throw new InvariantViolation('Settlement line totals do not match batch totals.');
        }

        $this->lines = array_values($lines);
    }

    public function batchId(): string { return $this->batchId; }
    public function providerCode(): string { return $this->providerCode; }
    public function gross(): Money { return $this->gross; }
    public function fees(): Money { return $this->fees; }
    public function refunds(): Money { return $this->refunds; }
    public function net(): Money { return $this->net; }
    public function settledAt(): DateTimeImmutable { return $this->settledAt; }
    public function sourceSha256(): string { return $this->sourceSha256; }

    /** @return list<array{reference:string,type:string,amount_minor:int,currency:string}> */
    public function lines(): array { return $this->lines; }
}
