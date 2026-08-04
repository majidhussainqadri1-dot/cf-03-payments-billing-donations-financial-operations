<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProviderEvidence;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class ProviderWebhookVerifier
{
    /** @var list<string> */
    private const PAYLOAD_FIELDS = [
        'event_id', 'type', 'intent_id', 'amount_minor', 'currency', 'occurred_at',
    ];

    /**
     * @param callable(string,string):string $secretResolver provider, key version -> secret
     * @param callable(string,string):bool $eventIsUnique provider, event ID -> atomic uniqueness reservation
     */
    public function __construct(
        private readonly mixed $secretResolver,
        private readonly mixed $eventIsUnique,
        private readonly int $replayWindowSeconds = 300
    ) {
        if (! is_callable($secretResolver) || ! is_callable($eventIsUnique)) {
            throw new InvalidArgumentException('Webhook verifier callbacks must be callable.');
        }
        if ($replayWindowSeconds < 30 || $replayWindowSeconds > 3600) {
            throw new InvalidArgumentException('Webhook replay window must be between 30 and 3600 seconds.');
        }
    }

    /**
     * Expected raw JSON fields: event_id, type, intent_id, amount_minor,
     * currency and occurred_at. Unknown fields are rejected so provider
     * adapters must normalize their native payload before this boundary.
     */
    public function verify(
        string $providerCode,
        string $keyVersion,
        string $rawBody,
        string $signatureHex,
        DateTimeImmutable $signatureTimestamp,
        DateTimeImmutable $receivedAt
    ): ProviderEvidence {
        if ($rawBody === '' || strlen($rawBody) > 1048576) {
            throw new InvalidArgumentException('Provider webhook body is empty or exceeds the one-megabyte limit.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signatureHex) !== 1) {
            throw new InvalidArgumentException('Provider webhook signature format is invalid.');
        }

        $secret = ($this->secretResolver)($providerCode, $keyVersion);
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new InvalidArgumentException('Provider webhook secret is unavailable or too short.');
        }

        $signedPayload = $signatureTimestamp->getTimestamp() . '.' . $rawBody;
        $calculated = hash_hmac('sha256', $signedPayload, $secret);
        if (! hash_equals($calculated, $signatureHex)) {
            throw new InvariantViolation('Provider webhook signature is invalid.');
        }

        $age = $receivedAt->getTimestamp() - $signatureTimestamp->getTimestamp();
        if ($age < 0 || $age > $this->replayWindowSeconds) {
            throw new InvariantViolation('Provider webhook is outside the accepted replay window.');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Provider webhook body is not valid JSON.', 0, $error);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Provider webhook payload must be a JSON object.');
        }

        foreach (self::PAYLOAD_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException('Provider webhook field is missing: ' . $field . '.');
            }
        }
        foreach (array_keys($payload) as $field) {
            if (! is_string($field) || ! in_array($field, self::PAYLOAD_FIELDS, true)) {
                throw new InvalidArgumentException('Provider webhook contains an unapproved field.');
            }
        }

        foreach (['event_id', 'type', 'intent_id', 'currency', 'occurred_at'] as $field) {
            if (! is_string($payload[$field])) {
                throw new InvalidArgumentException('Provider webhook field has an invalid type: ' . $field . '.');
            }
        }
        if (! is_int($payload['amount_minor']) || $payload['amount_minor'] < 0) {
            throw new InvalidArgumentException('Provider webhook amount must use non-negative integer minor units.');
        }
        if (preg_match('/^[A-Z]{3}$/', $payload['currency']) !== 1) {
            throw new InvalidArgumentException('Provider webhook currency must be a canonical uppercase code.');
        }

        try {
            $occurredAt = new DateTimeImmutable($payload['occurred_at']);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Provider webhook occurred_at is invalid.', 0, $error);
        }

        // Reserve only after signature, replay and payload validation. A forged request
        // must never consume a legitimate provider event ID.
        $unique = ($this->eventIsUnique)($providerCode, $payload['event_id']);
        $evidence = new ProviderEvidence(
            $providerCode,
            $payload['event_id'],
            $payload['type'],
            $payload['intent_id'],
            new Money($payload['amount_minor'], $payload['currency']),
            $keyVersion,
            $signatureTimestamp,
            $receivedAt,
            hash('sha256', $rawBody),
            true,
            $unique,
            $occurredAt
        );
        $evidence->assertTrusted($this->replayWindowSeconds);

        return $evidence;
    }
}
