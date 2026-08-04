<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PaymentIntent
{
    private PaymentIntentTransition $transitionLaw;

    public function __construct(
        private readonly string $intentId,
        private readonly string $actorReference,
        private readonly string $productId,
        private readonly ?string $priceVersionId,
        private readonly Money $amount,
        private readonly string $providerCode,
        private readonly string $idempotencyKey,
        private readonly string $requestHash,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private PaymentIntentState $state = PaymentIntentState::CREATED,
        private int $recordVersion = 1,
        private ?string $providerReference = null,
        private ?string $failureCode = null,
        private ?DateTimeImmutable $updatedAt = null
    ) {
        foreach ([$intentId, $actorReference, $productId, $providerCode] as $reference) {
            self::assertIdentifier($reference, 'Payment intent reference');
        }
        if ($priceVersionId !== null) {
            self::assertIdentifier($priceVersionId, 'Payment intent price version');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,191}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Payment intent idempotency key is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1) {
            throw new InvalidArgumentException('Payment intent request hash is invalid.');
        }
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Payment intent amount must be positive.');
        }
        if ($expiresAt <= $createdAt || $expiresAt > $createdAt->modify('+24 hours')) {
            throw new InvalidArgumentException('Payment intent expiry must be after creation and within 24 hours.');
        }
        if ($recordVersion < 1) {
            throw new InvalidArgumentException('Payment intent record version must be positive.');
        }

        $this->transitionLaw = new PaymentIntentTransition();
        $this->updatedAt ??= $createdAt;
    }

    public function attachProviderReference(string $providerReference, int $expectedVersion, DateTimeImmutable $at): void
    {
        $this->assertMutable($expectedVersion, $at);
        self::assertIdentifier($providerReference, 'Provider payment reference');
        if ($this->providerReference !== null && $this->providerReference !== $providerReference) {
            throw new InvariantViolation('Payment intent provider reference is immutable once recorded.');
        }
        $this->providerReference = $providerReference;
        $this->recordVersion++;
        $this->updatedAt = $at;
    }

    public function transition(
        PaymentIntentState $target,
        int $expectedVersion,
        DateTimeImmutable $at,
        ?string $failureCode = null
    ): void {
        $this->assertVersion($expectedVersion);
        if ($at < $this->updatedAt) {
            throw new InvariantViolation('Payment intent transition timestamp cannot move backwards.');
        }
        if ($this->state === $target) {
            return;
        }
        if ($at >= $this->expiresAt && ! in_array($target, [PaymentIntentState::EXPIRED, PaymentIntentState::SETTLED], true)) {
            throw new InvariantViolation('Expired payment intent cannot enter the requested state.');
        }
        $this->transitionLaw->assertAllowed($this->state, $target);

        if ($target === PaymentIntentState::FAILED) {
            if ($failureCode === null || preg_match('/^[a-z0-9][a-z0-9._:-]{2,63}$/', $failureCode) !== 1) {
                throw new InvalidArgumentException('Failed payment intent requires a safe failure code.');
            }
            $this->failureCode = $failureCode;
        } elseif ($failureCode !== null) {
            throw new InvalidArgumentException('Failure code is only valid for failed payment intents.');
        }

        if (in_array($target, [PaymentIntentState::AUTHORIZED, PaymentIntentState::CAPTURED, PaymentIntentState::SETTLED], true)
            && $this->providerReference === null
        ) {
            throw new InvariantViolation('Trusted payment state requires a provider reference.');
        }

        $this->state = $target;
        $this->recordVersion++;
        $this->updatedAt = $at;
    }

    public function assertProviderEvidence(ProviderEvidence $evidence, int $replayWindowSeconds = 300): void
    {
        $evidence->assertTrusted($replayWindowSeconds);
        $evidence->assertMatches($this->providerCode, $this->intentId, $this->amount);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'intent_id' => $this->intentId,
            'actor_reference' => $this->actorReference,
            'product_id' => $this->productId,
            'price_version_id' => $this->priceVersionId,
            'amount_minor' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'provider_code' => $this->providerCode,
            'provider_reference' => $this->providerReference,
            'state' => $this->state->value,
            'failure_code' => $this->failureCode,
            'idempotency_key' => $this->idempotencyKey,
            'request_hash' => $this->requestHash,
            'record_version' => $this->recordVersion,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    public function intentId(): string { return $this->intentId; }
    public function state(): PaymentIntentState { return $this->state; }
    public function amount(): Money { return $this->amount; }
    public function providerCode(): string { return $this->providerCode; }
    public function providerReference(): ?string { return $this->providerReference; }
    public function recordVersion(): int { return $this->recordVersion; }

    private function assertMutable(int $expectedVersion, DateTimeImmutable $at): void
    {
        $this->assertVersion($expectedVersion);
        if ($at < $this->updatedAt) {
            throw new InvariantViolation('Payment intent mutation timestamp cannot move backwards.');
        }
        if (in_array($this->state, [PaymentIntentState::FAILED, PaymentIntentState::CANCELLED, PaymentIntentState::EXPIRED, PaymentIntentState::REFUNDED], true)) {
            throw new InvariantViolation('Terminal payment intent cannot be mutated.');
        }
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->recordVersion) {
            throw new InvariantViolation('Stale payment intent record version.');
        }
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
