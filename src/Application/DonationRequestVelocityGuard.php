<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class DonationRequestVelocityGuard
{
    public const WINDOW_SECONDS = 300;
    public const MAX_NEW_ATTEMPTS_PER_WINDOW = 6;
    public const MAX_ACTIVE_PROVIDER_PENDING_INTENTS = 3;
    private const PAGE_SIZE = 100;
    private const MAX_ACTOR_RECORDS_TO_SCAN = 1000;

    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    public function assertAllowed(string $actorReference, DateTimeImmutable $now): void
    {
        $threshold = $now->modify('-'.self::WINDOW_SECONDS.' seconds');
        $recentAttempts = 0;
        $offset = 0;
        while (true) {
            $claims = $this->repository->page('idempotency', ['actor_ref' => $actorReference], self::PAGE_SIZE, $offset);
            foreach ($claims as $claim) {
                if (($claim['scope'] ?? null) !== 'donation_checkout') {
                    continue;
                }
                $createdAt = self::date($claim['created_at'] ?? null);
                if ($createdAt >= $threshold && $createdAt <= $now->modify('+5 minutes')) {
                    $recentAttempts++;
                    if ($recentAttempts >= self::MAX_NEW_ATTEMPTS_PER_WINDOW) {
                        throw new InvariantViolation('Donation checkout rate limit exceeded; retry after the bounded safety window.');
                    }
                }
            }
            $offset += count($claims);
            if (count($claims) < self::PAGE_SIZE) {
                break;
            }
            if ($offset >= self::MAX_ACTOR_RECORDS_TO_SCAN) {
                throw new InvariantViolation('Donation checkout velocity evidence exceeds the bounded audit window and cannot be proven safe automatically.');
            }
        }

        $active = 0;
        $offset = 0;
        while (true) {
            $intents = $this->repository->page('intents', ['actor_ref' => $actorReference], self::PAGE_SIZE, $offset);
            foreach ($intents as $intent) {
                if (($intent['product_id'] ?? null) !== 'donation.one_time'
                    || ($intent['state'] ?? null) !== 'provider_pending'
                ) {
                    continue;
                }
                $expiresAt = self::date($intent['expires_at'] ?? null);
                if ($expiresAt > $now) {
                    $active++;
                    if ($active >= self::MAX_ACTIVE_PROVIDER_PENDING_INTENTS) {
                        throw new InvariantViolation('Too many active hosted donation checkouts exist for this actor; complete or let them expire before creating another.');
                    }
                }
            }
            $offset += count($intents);
            if (count($intents) < self::PAGE_SIZE) {
                break;
            }
            if ($offset >= self::MAX_ACTOR_RECORDS_TO_SCAN) {
                throw new InvariantViolation('Donation active-intent evidence exceeds the bounded audit window and cannot be proven safe automatically.');
            }
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }
        throw new InvariantViolation('Donation velocity evidence contains an invalid timestamp.');
    }
}
