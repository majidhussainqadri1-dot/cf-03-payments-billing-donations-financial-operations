<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class AiUsageAuthorization
{
    public function __construct(
        private readonly string $authorizationId,
        private readonly string $actorReference,
        private readonly string $productId,
        private readonly string $priceVersionId,
        private readonly int $maximumUnits,
        private readonly Money $hardCap,
        private readonly DateTimeImmutable $validUntil
    ) {
        foreach ([$authorizationId, $actorReference, $productId, $priceVersionId] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('AI usage authorization reference is invalid.');
            }
        }
        if ($maximumUnits < 1 || $hardCap->minorUnits() < 1) {
            throw new InvalidArgumentException('AI usage authorization limits must be positive.');
        }
    }

    public function authorizationId(): string { return $this->authorizationId; }
    public function actorReference(): string { return $this->actorReference; }
    public function productId(): string { return $this->productId; }
    public function priceVersionId(): string { return $this->priceVersionId; }
    public function maximumUnits(): int { return $this->maximumUnits; }
    public function hardCap(): Money { return $this->hardCap; }
    public function validUntil(): DateTimeImmutable { return $this->validUntil; }

    public function assertSignedUsage(
        string $usageId,
        string $producerReference,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $sharedSecret
    ): void {
        foreach ([$usageId, $producerReference] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('AI usage evidence reference is invalid.');
            }
        }
        if ($units < 1 || $units > $this->maximumUnits) {
            throw new InvariantViolation('AI usage exceeds the authorized unit limit.');
        }
        if ($occurredAt > $this->validUntil) {
            throw new InvariantViolation('AI usage authorization has expired.');
        }
        if (strlen($sharedSecret) < 32 || preg_match('/^[a-f0-9]{64}$/', $signatureHex) !== 1) {
            throw new InvalidArgumentException('AI usage signing evidence is invalid.');
        }
        $message = implode('|', [
            $this->authorizationId,
            $this->actorReference,
            $this->productId,
            $this->priceVersionId,
            $usageId,
            $producerReference,
            (string)$units,
            $occurredAt->format(DATE_ATOM),
        ]);
        $expected = hash_hmac('sha256', $message, $sharedSecret);
        if (!hash_equals($expected, $signatureHex)) {
            throw new InvariantViolation('AI usage signature verification failed.');
        }
    }
}
