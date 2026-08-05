<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
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
            return $existing;
        }

        $records = $this->orderedRecords();
        $previousHash = $records === []
            ? self::GENESIS_HASH
            : (string)$records[array_key_last($records)]['entry_hash'];
        $metadata = [
            'object_type' => $payload['object_type'],
            'object_id' => $payload['object_id'],
            'metadata' => $payload['metadata'],
        ];
        $metadataHash = hash('sha256', $this->canonicalJson($metadata));
        $entryMaterial = [
            'audit_id' => $auditId,
            'actor_ref' => $payload['actor_reference'],
            'purpose' => $payload['purpose'],
            'action' => $payload['action'],
            'outcome' => $payload['outcome'],
            'trace_id' => $payload['correlation_id'],
            'metadata_hash' => $metadataHash,
            'previous_hash' => $previousHash,
            'created_at' => $payload['occurred_at'],
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
            'created_at' => new DateTimeImmutable((string)$payload['occurred_at']),
        ];
        $this->repository->insert('audit', $auditId, $record);
        return $record;
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
            $createdAt = $record['created_at'] instanceof DateTimeImmutable
                ? $record['created_at']->format(DATE_ATOM)
                : (new DateTimeImmutable((string)$record['created_at']))->format(DATE_ATOM);
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

    /** @return list<array<string,mixed>> */
    private function orderedRecords(): array
    {
        $records = $this->repository->all('audit');
        usort($records, static function (array $left, array $right): int {
            $leftTime = $left['created_at'] instanceof DateTimeImmutable
                ? $left['created_at']->getTimestamp()
                : strtotime((string)$left['created_at']);
            $rightTime = $right['created_at'] instanceof DateTimeImmutable
                ? $right['created_at']->getTimestamp()
                : strtotime((string)$right['created_at']);
            return [$leftTime, (string)$left['audit_id']] <=> [$rightTime, (string)$right['audit_id']];
        });
        return $records;
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
}
