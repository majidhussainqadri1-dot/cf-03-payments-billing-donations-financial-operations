<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Support\InvariantViolation;

final class AuditChain
{
    /** @var list<array{event:array<string,mixed>,previous_hash:string,entry_hash:string}> */
    private array $entries = [];

    public function append(AuditEnvelope $event): string
    {
        $payload = $event->toPayload();
        $previous = $this->entries === [] ? str_repeat('0', 64) : $this->entries[array_key_last($this->entries)]['entry_hash'];
        $entryHash = $this->hash($payload, $previous);
        $this->entries[] = [
            'event' => $payload,
            'previous_hash' => $previous,
            'entry_hash' => $entryHash,
        ];
        return $entryHash;
    }

    public function verify(): void
    {
        $previous = str_repeat('0', 64);
        foreach ($this->entries as $entry) {
            if (! hash_equals($previous, $entry['previous_hash'])) {
                throw new InvariantViolation('Financial audit chain previous hash mismatch.');
            }
            $expected = $this->hash($entry['event'], $previous);
            if (! hash_equals($expected, $entry['entry_hash'])) {
                throw new InvariantViolation('Financial audit chain entry hash mismatch.');
            }
            $previous = $entry['entry_hash'];
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload, string $previous): string
    {
        $payload = $this->canonicalize($payload);
        try {
            return hash('sha256', $previous . '|' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Financial audit event cannot be canonicalized.', 0, $error);
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        return $value;
    }
}
