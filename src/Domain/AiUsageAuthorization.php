<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class AiUsageAuthorization
{
    private string $priceVersionId;
    private int $maximumUnits;
    private Money $hardCap;
    private DateTimeImmutable $validUntil;
    private ?Money $legacyUnitPrice = null;

    /**
     * Supports the canonical persisted-price contract and the historical in-memory
     * unit-price constructor used by the earlier plan-completion suite.
     */
    public function __construct(
        private readonly string $authorizationId,
        private readonly string $actorReference,
        private readonly string $productId,
        string|Money $priceVersionId,
        int $maximumUnits,
        Money $hardCap,
        DateTimeImmutable $validUntil,
        ?string $legacyEvidenceHash = null
    ) {
        foreach ([$authorizationId, $actorReference, $productId] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('AI usage authorization reference is invalid.');
            }
        }
        if ($maximumUnits < 1 || $hardCap->minorUnits() < 1) {
            throw new InvalidArgumentException('AI usage authorization limits must be positive.');
        }

        if ($priceVersionId instanceof Money) {
            if ($priceVersionId->currency() !== $hardCap->currency()) {
                throw new InvalidArgumentException('AI usage unit price and hard cap currency must match.');
            }
            if ($priceVersionId->minorUnits() > intdiv(PHP_INT_MAX, $maximumUnits)
                || $priceVersionId->minorUnits() * $maximumUnits > $hardCap->minorUnits()
            ) {
                throw new InvariantViolation('AI usage maximum units exceed the authorized monetary hard cap.');
            }
            if ($legacyEvidenceHash !== null && preg_match('/^[a-f0-9]{64}$/', $legacyEvidenceHash) !== 1) {
                throw new InvalidArgumentException('AI usage legacy evidence hash is invalid.');
            }
            $this->legacyUnitPrice = $priceVersionId;
            $this->priceVersionId = 'legacy.price.'.substr(hash('sha256', implode('|', [
                $authorizationId,
                $productId,
                (string)$priceVersionId->minorUnits(),
                $priceVersionId->currency(),
                (string)$legacyEvidenceHash,
            ])), 0, 32);
        } else {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $priceVersionId) !== 1) {
                throw new InvalidArgumentException('AI usage price-version reference is invalid.');
            }
            $this->priceVersionId = $priceVersionId;
        }

        $this->maximumUnits = $maximumUnits;
        $this->hardCap = $hardCap;
        $this->validUntil = $validUntil;
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
        $this->assertUsageBounds($units, $occurredAt);
        $this->assertSignatureInputs($signatureHex, $sharedSecret);
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
        $this->assertHmac($message, $signatureHex, $sharedSecret);
    }

    /** Historical compatibility wrapper; new runtime code uses assertSignedUsage(). */
    public function assertSignedUsageFact(
        string $usageId,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $sharedSecret
    ): void {
        if ($this->legacyUnitPrice === null) {
            throw new InvariantViolation('Legacy AI usage signature mode is unavailable for persisted price authorizations.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $usageId) !== 1) {
            throw new InvalidArgumentException('AI usage evidence reference is invalid.');
        }
        $this->assertUsageBounds($units, $occurredAt);
        $this->assertSignatureInputs($signatureHex, $sharedSecret);
        $message = implode('|', [
            $this->authorizationId,
            $usageId,
            (string)$units,
            $occurredAt->format(DATE_ATOM),
        ]);
        $this->assertHmac($message, $signatureHex, $sharedSecret);
    }

    /** Historical exact-money projection; new runtime pricing is resolved by AiUsageBillingService. */
    public function priceFor(int $units, DateTimeImmutable $occurredAt): Money
    {
        if ($this->legacyUnitPrice === null) {
            throw new InvariantViolation('AI usage price must be resolved from the approved persisted price version.');
        }
        $this->assertUsageBounds($units, $occurredAt);
        if ($this->legacyUnitPrice->minorUnits() > intdiv(PHP_INT_MAX, $units)) {
            throw new InvariantViolation('AI usage amount multiplication would overflow.');
        }
        $amount = new Money($this->legacyUnitPrice->minorUnits() * $units, $this->legacyUnitPrice->currency());
        if ($amount->minorUnits() > $this->hardCap->minorUnits()) {
            throw new InvariantViolation('AI usage exceeds the authorized monetary hard cap.');
        }
        return $amount;
    }

    private function assertUsageBounds(int $units, DateTimeImmutable $occurredAt): void
    {
        if ($units < 1 || $units > $this->maximumUnits) {
            throw new InvariantViolation('AI usage exceeds the authorized unit limit.');
        }
        if ($occurredAt > $this->validUntil) {
            throw new InvariantViolation('AI usage authorization has expired.');
        }
    }

    private function assertSignatureInputs(string $signatureHex, string $sharedSecret): void
    {
        if (strlen($sharedSecret) < 32 || preg_match('/^[a-f0-9]{64}$/', $signatureHex) !== 1) {
            throw new InvalidArgumentException('AI usage signing evidence is invalid.');
        }
    }

    private function assertHmac(string $message, string $signatureHex, string $sharedSecret): void
    {
        $expected = hash_hmac('sha256', $message, $sharedSecret);
        if (!hash_equals($expected, $signatureHex)) {
            throw new InvariantViolation('AI usage signature verification failed.');
        }
    }
}
