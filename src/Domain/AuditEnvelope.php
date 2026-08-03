<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class AuditEnvelope
{
    private const FORBIDDEN_KEYS = [
        'pan', 'cvv', 'pin', 'otp', 'password', 'secret', 'api_key',
        'webhook_key', 'bank_credentials', 'raw_body', 'token', 'card_number',
    ];

    /** @param array<string,mixed> $metadata */
    public function __construct(
        private readonly string $eventId,
        private readonly string $actorReference,
        private readonly string $action,
        private readonly string $objectType,
        private readonly string $objectId,
        private readonly string $purpose,
        private readonly AuditOutcome $outcome,
        private readonly DateTimeImmutable $occurredAt,
        private readonly string $correlationId,
        private readonly array $metadata
    ) {
        foreach ([$eventId, $actorReference, $action, $objectType, $objectId, $purpose, $correlationId] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
                throw new InvalidArgumentException('Audit envelope contains an invalid identifier.');
            }
        }

        self::assertSafeMetadata($metadata);
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'actor_reference' => $this->actorReference,
            'action' => $this->action,
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'purpose' => $this->purpose,
            'outcome' => $this->outcome->value,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'correlation_id' => $this->correlationId,
            'metadata' => $this->metadata,
        ];
    }

    private static function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));
        if (str_ends_with($normalized, '_sha256') || str_ends_with($normalized, '_hash')) {
            return false;
        }

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if ($normalized === $forbidden
                || str_starts_with($normalized, $forbidden . '_')
                || str_ends_with($normalized, '_' . $forbidden)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function assertSafeMetadata(mixed $value, ?string $key = null): void
    {
        if ($key !== null && self::isForbiddenKey($key)) {
            throw new InvariantViolation('Audit metadata contains a prohibited sensitive field.');
        }

        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Audit metadata must contain only safe scalar and array values.');
        }

        if (is_string($value) && strlen($value) > 2048) {
            throw new InvalidArgumentException('Audit metadata string exceeds the safe limit.');
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $childKey => $childValue) {
            if (! is_int($childKey) && ! is_string($childKey)) {
                throw new InvalidArgumentException('Audit metadata key is invalid.');
            }
            self::assertSafeMetadata($childValue, is_string($childKey) ? $childKey : null);
        }
    }
}
