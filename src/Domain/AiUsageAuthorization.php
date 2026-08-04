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
        private readonly string $meterUnit,
        private readonly Money $unitRate,
        private readonly int $authorizedUnits,
        private readonly Money $hardCap,
        private readonly DateTimeImmutable $validUntil,
        private readonly string $termsSha256
    ) {
        foreach ([$authorizationId, $actorReference, $meterUnit] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('AI usage authorization reference is invalid.');
            }
        }
        if ($unitRate->minorUnits() <= 0 || $authorizedUnits < 1 || $authorizedUnits > 1000000000) {
            throw new InvalidArgumentException('AI usage rate or authorized unit count is invalid.');
        }
        if ($hardCap->currency() !== $unitRate->currency()) {
            throw new InvariantViolation('AI usage hard cap and unit rate require matching currency.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $termsSha256) !== 1) {
            throw new InvalidArgumentException('AI usage terms hash is invalid.');
        }
        $maximum = $this->multiply($unitRate->minorUnits(), $authorizedUnits);
        if ($maximum > $hardCap->minorUnits()) {
            throw new InvariantViolation('Authorized AI usage exceeds the user-approved hard cap.');
        }
    }

    public function priceFor(int $units, DateTimeImmutable $at): Money
    {
        if ($at > $this->validUntil) {
            throw new InvariantViolation('AI usage authorization has expired.');
        }
        if ($units < 0 || $units > $this->authorizedUnits) {
            throw new InvariantViolation('AI usage exceeds authorized units.');
        }
        $amount = new Money($this->multiply($this->unitRate->minorUnits(), $units), $this->unitRate->currency());
        if ($amount->minorUnits() > $this->hardCap->minorUnits()) {
            throw new InvariantViolation('AI usage exceeds the user-approved hard cap.');
        }
        return $amount;
    }

    /**
     * The usage producer signs: authorization_id|usage_id|units|occurred_at_iso8601
     */
    public function assertSignedUsageFact(
        string $usageId,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $sharedSecret
    ): void {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $usageId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signatureHex) !== 1
            || strlen($sharedSecret) < 32
        ) {
            throw new InvalidArgumentException('AI usage fact signature input is invalid.');
        }
        $this->priceFor($units, $occurredAt);
        $payload = implode('|', [$this->authorizationId, $usageId, (string) $units, $occurredAt->format(DATE_ATOM)]);
        if (! hash_equals(hash_hmac('sha256', $payload, $sharedSecret), $signatureHex)) {
            throw new InvariantViolation('AI usage fact signature is invalid.');
        }
    }

    private function multiply(int $left, int $right): int
    {
        if ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right)) {
            throw new InvariantViolation('AI usage amount exceeds the supported integer range.');
        }
        return $left * $right;
    }
}
