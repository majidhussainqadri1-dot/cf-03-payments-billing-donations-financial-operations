<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Support\InvariantViolation;

final class IdempotencyRecord
{
    private function __construct(
        private readonly string $scope,
        private readonly string $key,
        private readonly string $actorReference,
        private readonly string $requestFingerprint,
        private readonly IdempotencyStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
        private readonly ?string $resultReference
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function begin(
        string $scope,
        string $key,
        string $actorReference,
        array $payload,
        DateTimeImmutable $now
    ): self {
        self::assertScope($scope);
        self::assertKey($key);
        self::assertReference($actorReference, 'Idempotency actor reference');

        return new self(
            $scope,
            $key,
            $actorReference,
            self::fingerprint($scope, $key, $actorReference, $payload),
            IdempotencyStatus::PENDING,
            $now,
            $now,
            null
        );
    }

    /** @param array<string,mixed> $payload */
    public function assertReplayCompatible(
        string $scope,
        string $key,
        string $actorReference,
        array $payload
    ): void {
        $fingerprint = self::fingerprint($scope, $key, $actorReference, $payload);
        if (! hash_equals($this->requestFingerprint, $fingerprint)) {
            throw new InvariantViolation('Idempotency key was reused with a different request.');
        }
    }

    public function complete(string $resultReference, DateTimeImmutable $now): self
    {
        self::assertReference($resultReference, 'Idempotency result reference');
        if ($this->status !== IdempotencyStatus::PENDING) {
            throw new InvariantViolation('Only a pending idempotency record may be completed.');
        }
        if ($now < $this->updatedAt) {
            throw new InvariantViolation('Idempotency completion time cannot move backwards.');
        }

        return new self(
            $this->scope,
            $this->key,
            $this->actorReference,
            $this->requestFingerprint,
            IdempotencyStatus::COMPLETED,
            $this->createdAt,
            $now,
            $resultReference
        );
    }

    public function fail(string $resultReference, DateTimeImmutable $now): self
    {
        self::assertReference($resultReference, 'Idempotency failure reference');
        if ($this->status !== IdempotencyStatus::PENDING) {
            throw new InvariantViolation('Only a pending idempotency record may be failed.');
        }
        if ($now < $this->updatedAt) {
            throw new InvariantViolation('Idempotency failure time cannot move backwards.');
        }

        return new self(
            $this->scope,
            $this->key,
            $this->actorReference,
            $this->requestFingerprint,
            IdempotencyStatus::FAILED,
            $this->createdAt,
            $now,
            $resultReference
        );
    }

    public function scope(): string { return $this->scope; }
    public function key(): string { return $this->key; }
    public function actorReference(): string { return $this->actorReference; }
    public function requestFingerprint(): string { return $this->requestFingerprint; }
    public function status(): IdempotencyStatus { return $this->status; }
    public function resultReference(): ?string { return $this->resultReference; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @param array<string,mixed> $payload */
    private static function fingerprint(
        string $scope,
        string $key,
        string $actorReference,
        array $payload
    ): string {
        self::assertScope($scope);
        self::assertKey($key);
        self::assertReference($actorReference, 'Idempotency actor reference');

        try {
            $encoded = json_encode(
                self::normalize([
                    'scope' => $scope,
                    'key' => $key,
                    'actor_reference' => $actorReference,
                    'payload' => $payload,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Idempotency payload cannot be canonicalized.', 0, $error);
        }

        return hash('sha256', $encoded);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Idempotency payload must contain only canonical scalar and array values.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Idempotency payload object keys must be strings.');
            }
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function assertScope(string $scope): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,63}$/', $scope) !== 1) {
            throw new InvalidArgumentException('Idempotency scope is invalid.');
        }
    }

    private static function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $key) !== 1) {
            throw new InvalidArgumentException('Idempotency key must be 16 to 128 safe characters.');
        }
    }

    private static function assertReference(string $reference, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $reference) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
