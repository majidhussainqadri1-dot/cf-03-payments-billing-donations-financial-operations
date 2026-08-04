<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinanceExport
{
    /** @param list<array<string,scalar|null>> $rows */
    public function __construct(
        private readonly string $exportId,
        private readonly array $rows,
        private readonly string $manifestHash,
        private readonly int $expiresAt
    ) {
        if (trim($exportId) === '' || ! preg_match('/^[a-f0-9]{64}$/', $manifestHash)) {
            throw new InvalidArgumentException('Export identity is invalid.');
        }
        if (count($rows) > 100000) { throw new InvariantViolation('Export row limit exceeded.'); }
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (preg_match('/(?:pan|cvv|pin|otp|password|secret|token|full_card|bank_credential)/i', (string) $key)) {
                    throw new InvariantViolation('Sensitive field is forbidden in export.');
                }
            }
        }
    }

    public static function neutralizeSpreadsheetFormula(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    public function expired(int $now): bool { return $now >= $this->expiresAt; }
}
