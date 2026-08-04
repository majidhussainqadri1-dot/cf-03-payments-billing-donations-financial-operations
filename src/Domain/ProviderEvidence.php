<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class ProviderEvidence
{
    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $providerCode,
        private readonly string $providerEventId,
        private readonly string $eventType,
        private readonly string $paymentIntentId,
        private readonly Money $amount,
        private readonly string $signatureKeyVersion,
        private readonly DateTimeImmutable $signatureTimestamp,
        private readonly DateTimeImmutable $receivedAt,
        private readonly string $rawBodySha256,
        private readonly bool $signatureVerified,
        private readonly bool $eventIdUnique,
        ?DateTimeImmutable $occurredAt = null
    ) {
        self::assertIdentifier($providerCode, 'Provider code');
        self::assertIdentifier($providerEventId, 'Provider event ID');
        self::assertIdentifier($eventType, 'Provider event type');
        self::assertIdentifier($paymentIntentId, 'Payment intent ID');
        self::assertIdentifier($signatureKeyVersion, 'Signature key version');

        if (preg_match('/^[a-f0-9]{64}$/', $rawBodySha256) !== 1) {
            throw new InvalidArgumentException('Provider raw-body SHA-256 is invalid.');
        }

        $this->occurredAt = $occurredAt ?? $signatureTimestamp;
        if ($this->occurredAt > $signatureTimestamp->modify('+5 minutes')
            || $this->occurredAt > $receivedAt->modify('+5 minutes')
        ) {
            throw new InvalidArgumentException('Provider event occurrence time is implausibly in the future.');
        }
    }

    public function assertTrusted(int $replayWindowSeconds = 300): void
    {
        if ($replayWindowSeconds < 30 || $replayWindowSeconds > 3600) {
            throw new InvalidArgumentException('Webhook replay window must be between 30 and 3600 seconds.');
        }

        if (! $this->signatureVerified) {
            throw new InvariantViolation('Provider evidence signature is not verified.');
        }

        if (! $this->eventIdUnique) {
            throw new InvariantViolation('Provider event ID has already been processed.');
        }

        $age = $this->receivedAt->getTimestamp() - $this->signatureTimestamp->getTimestamp();
        if ($age < 0 || $age > $replayWindowSeconds) {
            throw new InvariantViolation('Provider evidence is outside the accepted replay window.');
        }
    }

    public function assertMatches(string $providerCode, string $paymentIntentId, Money $expectedAmount): void
    {
        if ($this->providerCode !== $providerCode
            || $this->paymentIntentId !== $paymentIntentId
            || ! $this->amount->equals($expectedAmount)
        ) {
            throw new InvariantViolation('Provider evidence does not match the expected payment intent and amount.');
        }
    }

    public function providerCode(): string { return $this->providerCode; }
    public function providerEventId(): string { return $this->providerEventId; }
    public function eventType(): string { return $this->eventType; }
    public function paymentIntentId(): string { return $this->paymentIntentId; }
    public function amount(): Money { return $this->amount; }
    public function rawBodySha256(): string { return $this->rawBodySha256; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
    public function signatureTimestamp(): DateTimeImmutable { return $this->signatureTimestamp; }
    public function receivedAt(): DateTimeImmutable { return $this->receivedAt; }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
