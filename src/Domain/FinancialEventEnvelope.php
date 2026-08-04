<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final class FinancialEventEnvelope
{
    /** @param array<string,scalar|null> $payload */
    public function __construct(
        private readonly string $eventId,
        private readonly string $eventType,
        private readonly string $aggregateId,
        private readonly string $aggregateVersion,
        private readonly string $schemaVersion,
        private readonly string $traceId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly array $payload
    ) {
        foreach ([$eventId, $eventType, $aggregateId, $traceId] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Financial event envelope reference is invalid.');
            }
        }
        foreach ([$aggregateVersion, $schemaVersion] as $version) {
            if (preg_match('/^[0-9][0-9A-Za-z._-]{0,31}$/', $version) !== 1) {
                throw new InvalidArgumentException('Financial event envelope version is invalid.');
            }
        }
        if (preg_match('/(?:Create|Update|Delete|Grant|Approve|Execute|Set|Change)$/', $eventType) === 1) {
            throw new InvalidArgumentException('Financial event type must be a past-tense fact, not a command.');
        }
        foreach ($payload as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Financial event payload must contain scalar values only.');
            }
            if (preg_match('/(?:pan|cvv|pin|otp|password|secret|raw_body|full_card|bank_credential|private_key)/i', (string) $key) === 1) {
                throw new InvalidArgumentException('Sensitive financial event payload field is prohibited.');
            }
        }
    }

    public function payloadSha256(): string
    {
        $payload = $this->payload;
        ksort($payload);
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Financial event payload cannot be canonicalized.', 0, $error);
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'aggregate_id' => $this->aggregateId,
            'aggregate_version' => $this->aggregateVersion,
            'schema_version' => $this->schemaVersion,
            'trace_id' => $this->traceId,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'payload' => $this->payload,
            'payload_sha256' => $this->payloadSha256(),
        ];
    }
}
