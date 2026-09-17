<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Domain\ReconciliationResult;
use Sabri\CF03\Domain\SettlementBatch;

final class ReconciliationEngine
{
    /**
     * @param list<array{reference:string,type:string,amount_minor:int,currency:string}> $internalLines
     * @param array<string,int> $materialityByCurrency
     */
    public function reconcile(
        SettlementBatch $providerBatch,
        array $internalLines,
        array $materialityByCurrency = []
    ): ReconciliationResult {
        $provider = $this->index($providerBatch->lines(), 'provider');
        $internal = $this->index($internalLines, 'internal');
        $exceptions = [];

        foreach ($internal as $reference => $line) {
            if (! isset($provider[$reference])) {
                $exceptions[] = $this->exception('missing_provider_line', $reference, $line['amount_minor'], 0, $line['currency'], $materialityByCurrency);
                continue;
            }
            $other = $provider[$reference];
            if ($line['type'] !== $other['type']) {
                $typeMismatch = $this->exception('type_mismatch', $reference, $line['amount_minor'], $other['amount_minor'], $line['currency'], $materialityByCurrency);
                // A payment/fee/refund semantic mismatch can reverse the accounting meaning
                // even when the numeric amount is identical, so it is never eligible for
                // threshold-based non-material treatment or accepted-risk closure.
                $typeMismatch['material'] = true;
                $exceptions[] = $typeMismatch;
            }
            if ($line['currency'] !== $other['currency']) {
                $exceptions[] = [
                    'type' => 'currency_mismatch',
                    'reference' => $reference,
                    'expected' => $line['amount_minor'],
                    'actual' => $other['amount_minor'],
                    'currency' => $line['currency'],
                    'material' => true,
                ];
                continue;
            }
            if ($line['amount_minor'] !== $other['amount_minor']) {
                $exceptions[] = $this->exception('amount_mismatch', $reference, $line['amount_minor'], $other['amount_minor'], $line['currency'], $materialityByCurrency);
            }
        }

        foreach ($provider as $reference => $line) {
            if (! isset($internal[$reference])) {
                $exceptions[] = $this->exception('unexpected_provider_line', $reference, 0, $line['amount_minor'], $line['currency'], $materialityByCurrency);
            }
        }

        return new ReconciliationResult($exceptions);
    }

    /**
     * @param list<array{reference:string,type:string,amount_minor:int,currency:string}> $lines
     * @return array<string,array{reference:string,type:string,amount_minor:int,currency:string}>
     */
    private function index(array $lines, string $source): array
    {
        $indexed = [];
        foreach ($lines as $line) {
            if (! is_array($line)
                || ! isset($line['reference'], $line['type'], $line['amount_minor'], $line['currency'])
                || ! is_string($line['reference'])
                || ! is_string($line['type'])
                || ! is_int($line['amount_minor'])
                || ! is_string($line['currency'])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $line['reference']) !== 1
                || ! in_array($line['type'], ['payment', 'fee', 'refund'], true)
                || $line['amount_minor'] < 0
                || preg_match('/^[A-Z]{3}$/', $line['currency']) !== 1
            ) {
                throw new InvalidArgumentException('Invalid ' . $source . ' reconciliation line.');
            }
            if (isset($indexed[$line['reference']])) {
                throw new InvalidArgumentException('Duplicate ' . $source . ' reconciliation reference.');
            }
            $indexed[$line['reference']] = $line;
        }
        return $indexed;
    }

    /** @param array<string,int> $materialityByCurrency @return array{type:string,reference:string,expected:int,actual:int,currency:string,material:bool} */
    private function exception(
        string $type,
        string $reference,
        int $expected,
        int $actual,
        string $currency,
        array $materialityByCurrency
    ): array {
        $threshold = $materialityByCurrency[$currency] ?? 0;
        if (! is_int($threshold) || $threshold < 0) {
            throw new InvalidArgumentException('Reconciliation materiality threshold is invalid.');
        }
        return [
            'type' => $type,
            'reference' => $reference,
            'expected' => $expected,
            'actual' => $actual,
            'currency' => $currency,
            'material' => abs($expected - $actual) > $threshold,
        ];
    }
}
