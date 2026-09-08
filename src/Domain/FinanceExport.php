<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinanceExport
{
    private const FORBIDDEN_KEY_PATTERN = '/(?:pan|cvv|pin|otp|password|secret|token|full_card|bank_credential|private_key|api_key|webhook_key)/i';

    /** @var list<array<string,scalar|null>> */
    private readonly array $rows;

    /** @param list<array<string,scalar|null>> $rows */
    public function __construct(
        private readonly string $exportId,
        array $rows,
        private readonly string $manifestHash,
        private readonly int $expiresAt
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $exportId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $manifestHash) !== 1
            || $expiresAt < 1
        ) {
            throw new InvalidArgumentException('Export identity, manifest or expiry is invalid.');
        }
        if (count($rows) > 100000) {
            throw new InvariantViolation('Export row limit exceeded.');
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row) || count($row) > 64) {
                throw new InvalidArgumentException('Finance export row must be a bounded object.');
            }
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                if (! is_string($key)
                    || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                    || preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1
                    || is_float($value)
                    || (! is_scalar($value) && $value !== null)
                ) {
                    throw new InvariantViolation('Finance export contains an unsafe field or value.');
                }
                if (is_string($value)) {
                    if (strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                        throw new InvalidArgumentException('Finance export string value is oversized or contains prohibited controls.');
                    }
                    $value = self::neutralizeSpreadsheetFormula($value);
                }
                $normalizedRow[$key] = $value;
            }
            ksort($normalizedRow, SORT_STRING);
            $normalized[] = $normalizedRow;
        }
        $this->rows = $normalized;
    }

    public static function neutralizeSpreadsheetFormula(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    public function expired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return list<array<string,scalar|null>> */
    public function rows(): array { return $this->rows; }
    public function manifestHash(): string { return $this->manifestHash; }
}
