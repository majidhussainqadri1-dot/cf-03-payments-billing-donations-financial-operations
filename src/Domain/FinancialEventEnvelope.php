<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final class FinancialEventEnvelope
{
    private const FACT_SUFFIXES = [
        'Created','Staged','Approved','Activated','Retired','Authorized','Captured','Settled','Failed',
        'Cancelled','Expired','Refunded','Disputed','PastDue','Started','Issued','Posted','Requested',
        'Succeeded','Reconciled','Opened','Won','Lost','Imported','Closed','Quarantined','Degraded',
        'Adjusted','Paused','Resumed','Revoked','Delivered','Acknowledged','Completed','Updated'
    ];

    private const SENSITIVE_KEY_PATTERN = '/(?:pan|cvv|pin|otp|password|secret|raw_body|full_card|bank_credential|private_key|api_key|webhook_key|authorization|cookie|access_token|refresh_token|account_number|routing_number|iban|swift|track_data|cryptogram|security_code)/i';

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
        $isFact = false;
        foreach (self::FACT_SUFFIXES as $suffix) {
            if (str_ends_with($eventType, $suffix)) {
                $isFact = true;
                break;
            }
        }
        if (! $isFact) {
            throw new InvalidArgumentException('Financial event type must be a normalized past-tense fact.');
        }
        if (count($payload) > 64) {
            throw new InvalidArgumentException('Financial event payload exceeds the maximum field count.');
        }
        foreach ($payload as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1
                || is_float($value)
                || (! is_scalar($value) && $value !== null)
            ) {
                throw new InvalidArgumentException('Financial event payload contains an unsafe field or value.');
            }
            if (is_string($value)
                && (strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)
            ) {
                throw new InvalidArgumentException('Financial event payload string is oversized or contains prohibited controls.');
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
