<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class Subscription
{
    private const ALLOWED = [
        'pending' => ['active', 'cancelled', 'expired'],
        'active' => ['past_due', 'paused', 'cancel_at_period_end', 'cancelled'],
        'past_due' => ['grace', 'active', 'cancel_at_period_end', 'cancelled', 'expired'],
        'grace' => ['active', 'cancel_at_period_end', 'cancelled', 'expired'],
        'paused' => ['active', 'cancelled', 'expired'],
        'cancel_at_period_end' => ['active', 'cancelled', 'expired'],
        'cancelled' => ['expired'],
        'expired' => [],
    ];

    public function __construct(
        private readonly string $subscriptionId,
        private readonly string $ownerReference,
        private readonly string $productId,
        private readonly string $priceVersionId,
        private string $state,
        private int $version = 1,
        private readonly string $policyVersion = 'subscription.policy.v1',
        private ?string $providerReference = null,
        private ?DateTimeImmutable $currentPeriodEnd = null,
        private ?DateTimeImmutable $graceUntil = null,
        private ?DateTimeImmutable $pausedUntil = null,
        private ?DateTimeImmutable $cancellationEffectiveAt = null
    ) {
        foreach ([$subscriptionId, $ownerReference, $productId, $priceVersionId, $policyVersion] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Subscription fields are required.');
            }
        }
        if (! array_key_exists($state, self::ALLOWED) || $version < 1) {
            throw new InvalidArgumentException('Unknown subscription state or invalid version.');
        }
    }

    public function transition(string $next, int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version) {
            throw new InvariantViolation('Stale subscription version.');
        }
        if ($next === $this->state) {
            return;
        }
        if (! isset(self::ALLOWED[$next]) || ! in_array($next, self::ALLOWED[$this->state], true)) {
            throw new InvariantViolation('Invalid subscription transition.');
        }
        $this->state = $next;
        $this->version++;
    }

    public function activate(
        string $providerReference,
        DateTimeImmutable $activatedAt,
        DateTimeImmutable $periodEnd,
        int $expectedVersion
    ): void {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $providerReference) !== 1) {
            throw new InvalidArgumentException('Subscription provider reference is invalid.');
        }
        if ($periodEnd <= $activatedAt || $periodEnd > $activatedAt->modify('+2 years')) {
            throw new InvalidArgumentException('Subscription period end must follow activation and remain within two years.');
        }
        $this->transition('active', $expectedVersion);
        $this->providerReference = $providerReference;
        $this->currentPeriodEnd = $periodEnd;
        $this->graceUntil = null;
        $this->pausedUntil = null;
        $this->cancellationEffectiveAt = null;
    }

    public function markPastDue(int $expectedVersion): void
    {
        $this->transition('past_due', $expectedVersion);
    }

    public function startGrace(DateTimeImmutable $graceUntil, int $expectedVersion): void
    {
        if ($this->currentPeriodEnd !== null && $graceUntil <= $this->currentPeriodEnd) {
            throw new InvalidArgumentException('Subscription grace must extend beyond the current period.');
        }
        $this->transition('grace', $expectedVersion);
        $this->graceUntil = $graceUntil;
    }

    public function pause(
        DateTimeImmutable $pausedUntil,
        DateTimeImmutable $at,
        int $expectedVersion
    ): void {
        if ($pausedUntil <= $at) {
            throw new InvalidArgumentException('Subscription pause end must be after the pause decision time.');
        }
        $this->transition('paused', $expectedVersion);
        $this->pausedUntil = $pausedUntil;
    }

    public function cancelAtPeriodEnd(int $expectedVersion): void
    {
        if ($this->currentPeriodEnd === null) {
            throw new InvariantViolation('Subscription period end is unavailable.');
        }
        $this->transition('cancel_at_period_end', $expectedVersion);
        $this->cancellationEffectiveAt = $this->currentPeriodEnd;
    }

    public function resume(DateTimeImmutable $newPeriodEnd, int $expectedVersion): void
    {
        if (! in_array($this->state, ['past_due', 'grace', 'paused', 'cancel_at_period_end'], true)) {
            throw new InvariantViolation('Subscription is not resumable. A cancelled subscription requires new consent and a new subscription.');
        }
        $this->transition('active', $expectedVersion);
        $this->currentPeriodEnd = $newPeriodEnd;
        $this->graceUntil = null;
        $this->pausedUntil = null;
        $this->cancellationEffectiveAt = null;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'owner_reference' => $this->ownerReference,
            'product_id' => $this->productId,
            'price_version_id' => $this->priceVersionId,
            'policy_version' => $this->policyVersion,
            'provider_reference' => $this->providerReference,
            'state' => $this->state,
            'current_period_end' => $this->currentPeriodEnd?->format(DATE_ATOM),
            'grace_until' => $this->graceUntil?->format(DATE_ATOM),
            'paused_until' => $this->pausedUntil?->format(DATE_ATOM),
            'cancellation_effective_at' => $this->cancellationEffectiveAt?->format(DATE_ATOM),
            'record_version' => $this->version,
        ];
    }

    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
}
