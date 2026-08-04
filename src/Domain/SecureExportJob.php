<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class SecureExportJob
{
    private const ALLOWED_FIELDS = [
        'transaction_id', 'source_type', 'source_ref', 'product_id', 'status',
        'amount_minor', 'currency', 'effective_at', 'recorded_at', 'invoice_number',
        'refund_id', 'settlement_batch_id', 'exception_type', 'period_id',
    ];

    /** @var list<string> */
    private array $fields;

    /** @var array<string,scalar> */
    private array $filters;

    /** @param list<string> $fields @param array<string,scalar> $filters */
    public function __construct(
        private readonly string $jobId,
        private readonly string $requesterReference,
        array $fields,
        array $filters,
        private readonly int $maximumRows,
        private readonly DateTimeImmutable $expiresAt,
        private string $state = 'queued',
        private int $recordVersion = 1,
        private ?string $manifestSha256 = null,
        private ?string $encryptedObjectReference = null
    ) {
        foreach ([$jobId, $requesterReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Finance export reference is invalid.');
            }
        }
        if ($maximumRows < 1 || $maximumRows > 100000) {
            throw new InvalidArgumentException('Finance export row limit must be between 1 and 100000.');
        }
        if (! in_array($state, ['queued', 'running', 'ready', 'failed', 'expired', 'revoked'], true) || $recordVersion < 1) {
            throw new InvalidArgumentException('Finance export state or version is invalid.');
        }
        $fields = array_values(array_unique($fields));
        if ($fields === [] || array_diff($fields, self::ALLOWED_FIELDS) !== []) {
            throw new InvariantViolation('Finance export contains unapproved or empty field selection.');
        }
        foreach (array_keys($filters) as $filter) {
            if (! in_array($filter, ['date_from', 'date_to', 'product_id', 'currency', 'status', 'period_id'], true)) {
                throw new InvariantViolation('Finance export filter is not approved.');
            }
        }
        $this->fields = $fields;
        $this->filters = $filters;
    }

    public function start(int $expectedVersion): void
    {
        $this->transition('queued', 'running', $expectedVersion);
    }

    public function complete(string $manifestSha256, string $encryptedObjectReference, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== 'running') {
            throw new InvariantViolation('Finance export is not running.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,255}$/', $encryptedObjectReference) !== 1
        ) {
            throw new InvalidArgumentException('Finance export artifact evidence is invalid.');
        }
        $this->manifestSha256 = $manifestSha256;
        $this->encryptedObjectReference = $encryptedObjectReference;
        $this->state = 'ready';
        $this->recordVersion++;
    }

    public function fail(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (! in_array($this->state, ['queued', 'running'], true)) {
            throw new InvariantViolation('Finance export cannot fail from the current state.');
        }
        $this->state = 'failed';
        $this->recordVersion++;
    }

    public function assertDownloadable(DateTimeImmutable $now): void
    {
        if ($now >= $this->expiresAt) {
            throw new InvariantViolation('Finance export has expired.');
        }
        if ($this->state !== 'ready' || $this->manifestSha256 === null || $this->encryptedObjectReference === null) {
            throw new InvariantViolation('Finance export is not ready for secure delivery.');
        }
    }

    public function revoke(int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if (in_array($this->state, ['expired', 'revoked'], true)) {
            return;
        }
        $this->state = 'revoked';
        $this->recordVersion++;
    }

    public static function neutralizeCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    /** @return array<string,mixed> */
    public function specification(): array
    {
        return [
            'job_id' => $this->jobId,
            'fields' => $this->fields,
            'filters' => $this->filters,
            'maximum_rows' => $this->maximumRows,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'state' => $this->state,
            'record_version' => $this->recordVersion,
        ];
    }

    public function state(): string { return $this->state; }
    public function recordVersion(): int { return $this->recordVersion; }

    private function transition(string $from, string $to, int $expectedVersion): void
    {
        $this->assertVersion($expectedVersion);
        if ($this->state !== $from) {
            throw new InvariantViolation('Finance export transition is invalid.');
        }
        $this->state = $to;
        $this->recordVersion++;
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale finance export record version.');
        }
    }
}
