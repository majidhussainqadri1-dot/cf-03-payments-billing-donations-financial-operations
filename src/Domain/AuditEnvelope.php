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
        'private_key', 'access_key', 'refresh_token', 'authorization', 'cookie',
        'iban', 'swift', 'routing_number', 'account_number', 'magnetic_stripe',
        'track_data', 'cryptogram', 'security_code', 'payment_method_payload',
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

        $items = 0;
        self::assertSafeMetadata($metadata, null, 0, $items);
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

    private static function assertSafeMetadata(
        mixed $value,
        ?string $key,
        int $depth,
        int &$items
    ): void {
        if ($depth > 8) {
            throw new InvalidArgumentException('Audit metadata exceeds the maximum nesting depth.');
        }
        $items++;
        if ($items > 512) {
            throw new InvalidArgumentException('Audit metadata exceeds the maximum item count.');
        }
        if ($key !== null && self::isForbiddenKey($key)) {
            throw new InvariantViolation('Audit metadata contains a prohibited sensitive field.');
        }

        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Audit metadata must contain only safe scalar and array values.');
        }

        if (is_string($value)) {
            if (strlen($value) > 2048
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException('Audit metadata string is oversized or contains prohibited controls.');
            }
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $childKey => $childValue) {
            if (! is_int($childKey) && ! is_string($childKey)) {
                throw new InvalidArgumentException('Audit metadata key is invalid.');
            }
            if (is_string($childKey)
                && (strlen($childKey) > 96 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$/', $childKey) !== 1)
            ) {
                throw new InvalidArgumentException('Audit metadata key format is invalid.');
            }
            self::assertSafeMetadata($childValue, is_string($childKey) ? $childKey : null, $depth + 1, $items);
        }
    }
}
