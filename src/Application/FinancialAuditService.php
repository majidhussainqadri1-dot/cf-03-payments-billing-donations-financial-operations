<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialAuditService
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    /** @return array<string,mixed> */
    public function append(AuditEnvelope $envelope): array
    {
        $payload = $envelope->toPayload();
        $auditId = (string)$payload['event_id'];
        $existing = $this->repository->get('audit', $auditId);
        if ($existing !== null) {
            $this->assertExistingMatches($existing, $payload);
            return $existing;
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->repository->transaction(function () use ($payload, $auditId): array {
                    $existing = $this->repository->get('audit', $auditId);
                    if ($existing !== null) {
                        $this->assertExistingMatches($existing, $payload);
                        return $existing;
                    }
                    $records = $this->orderedRecords();
                    $previousHash = $records === []
                        ? self::GENESIS_HASH
                        : (string)$records[array_key_last($records)]['entry_hash'];
                    $metadata = $this->metadata($payload);
                    $metadataHash = hash('sha256', $this->canonicalJson($metadata));
                    $createdAt = (new DateTimeImmutable((string)$payload['occurred_at']))->format(DATE_ATOM);
                    $entryMaterial = [
                        'audit_id' => $auditId,
                        'actor_ref' => $payload['actor_reference'],
                        'purpose' => $payload['purpose'],
                        'action' => $payload['action'],
                        'outcome' => $payload['outcome'],
                        'trace_id' => $payload['correlation_id'],
                        'metadata_hash' => $metadataHash,
                        'previous_hash' => $previousHash,
                        'created_at' => $createdAt,
                    ];
                    $entryHash = hash('sha256', $this->canonicalJson($entryMaterial));
                    $record = [
                        'audit_id' => $auditId,
                        'actor_ref' => $payload['actor_reference'],
                        'purpose' => $payload['purpose'],
                        'action' => $payload['action'],
                        'outcome' => $payload['outcome'],
                        'trace_id' => $payload['correlation_id'],
                        'metadata_json' => $metadata,
                        'metadata_hash' => $metadataHash,
                        'previous_hash' => $previousHash,
                        'entry_hash' => $entryHash,
                        'created_at' => new DateTimeImmutable($createdAt),
                    ];
                    $this->repository->insert('audit', $auditId, $record);
                    return $record;
                });
            } catch (InvariantViolation $error) {
                $lastError = $error;
                $existing = $this->repository->get('audit', $auditId);
                if ($existing !== null) {
                    $this->assertExistingMatches($existing, $payload);
                    return $existing;
                }
            }
        }
        throw new InvariantViolation('Financial audit append could not serialize after three attempts.', 0, $lastError);
    }

    public function verifyChain(): bool
    {
        $previous = self::GENESIS_HASH;
        foreach ($this->orderedRecords() as $record) {
            if (!hash_equals($previous, (string)$record['previous_hash'])) {
                throw new InvariantViolation('Financial audit chain previous-hash mismatch.');
            }
            $metadataHash = hash('sha256', $this->canonicalJson((array)$record['metadata_json']));
            if (!hash_equals($metadataHash, (string)$record['metadata_hash'])) {
                throw new InvariantViolation('Financial audit metadata hash mismatch.');
            }
            $createdAt = self::dateString($record['created_at'] ?? null);
            $entryMaterial = [
                'audit_id' => $record['audit_id'],
                'actor_ref' => $record['actor_ref'],
                'purpose' => $record['purpose'],
                'action' => $record['action'],
                'outcome' => $record['outcome'],
                'trace_id' => $record['trace_id'],
                'metadata_hash' => $record['metadata_hash'],
                'previous_hash' => $record['previous_hash'],
                'created_at' => $createdAt,
            ];
            $expected = hash('sha256', $this->canonicalJson($entryMaterial));
            if (!hash_equals($expected, (string)$record['entry_hash'])) {
                throw new InvariantViolation('Financial audit entry hash mismatch.');
            }
            $previous = (string)$record['entry_hash'];
        }
        return true;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $payload */
    private function assertExistingMatches(array $existing, array $payload): void
    {
        $metadata = $this->metadata($payload);
        $expectedMetadataHash = hash('sha256', $this->canonicalJson($metadata));
        $expectedCreatedAt = (new DateTimeImmutable((string)$payload['occurred_at']))->format(DATE_ATOM);
        if (($existing['actor_ref'] ?? null) !== $payload['actor_reference']
            || ($existing['purpose'] ?? null) !== $payload['purpose']
            || ($existing['action'] ?? null) !== $payload['action']
            || ($existing['outcome'] ?? null) !== $payload['outcome']
            || ($existing['trace_id'] ?? null) !== $payload['correlation_id']
            || !hash_equals($expectedMetadataHash, (string)($existing['metadata_hash'] ?? ''))
            || !hash_equals($expectedCreatedAt, self::dateString($existing['created_at'] ?? null))
        ) {
            throw new InvariantViolation('Audit event identifier was reused with different immutable evidence.');
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function metadata(array $payload): array
    {
        return [
            'object_type' => $payload['object_type'],
            'object_id' => $payload['object_id'],
            'metadata' => $payload['metadata'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function orderedRecords(): array
    {
        return $this->repository->all('audit');
    }

    private function canonicalJson(mixed $value): string
    {
        $value = $this->sortRecursively($value);
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Financial audit payload cannot be canonically encoded.', 0, $error);
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }
        return $value;
    }

    private static function dateString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('Financial audit timestamp is missing.');
        }
        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    }
}
