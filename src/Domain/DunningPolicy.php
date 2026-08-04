<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class DunningPolicy
{
    /** @var list<int> */
    private array $retryDelaysSeconds;

    /** @param list<int> $retryDelaysSeconds */
    public function __construct(
        array $retryDelaysSeconds,
        private readonly int $quietHourStart = 21,
        private readonly int $quietHourEnd = 8,
        private readonly int $maximumAttempts = 4
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 8) {
            throw new InvalidArgumentException('Dunning maximum attempts must be between 1 and 8.');
        }
        if (count($retryDelaysSeconds) !== $maximumAttempts) {
            throw new InvalidArgumentException('Dunning retry schedule must match maximum attempts.');
        }
        $previous = 0;
        foreach ($retryDelaysSeconds as $delay) {
            if (! is_int($delay) || $delay < 300 || $delay <= $previous || $delay > 2592000) {
                throw new InvalidArgumentException('Dunning retry delays must be increasing between five minutes and thirty days.');
            }
            $previous = $delay;
        }
        if ($quietHourStart < 0 || $quietHourStart > 23 || $quietHourEnd < 0 || $quietHourEnd > 23) {
            throw new InvalidArgumentException('Dunning quiet hours are invalid.');
        }
        $this->retryDelaysSeconds = array_values($retryDelaysSeconds);
    }

    public function nextRetryAt(
        DateTimeImmutable $failedAt,
        int $attemptNumber,
        DateTimeZone $userTimeZone,
        bool $providerOutage = false
    ): DateTimeImmutable {
        if ($providerOutage) {
            throw new InvariantViolation('Provider outage must use service recovery, not user-failure dunning.');
        }
        if ($attemptNumber < 1 || $attemptNumber > $this->maximumAttempts) {
            throw new InvariantViolation('Dunning attempts are exhausted or invalid.');
        }

        $candidate = $failedAt->modify('+' . $this->retryDelaysSeconds[$attemptNumber - 1] . ' seconds');
        $local = $candidate->setTimezone($userTimeZone);
        $hour = (int) $local->format('G');
        if ($this->inQuietHours($hour)) {
            $local = $local->setTime($this->quietHourEnd, 0, 0);
            if ($this->quietHourStart > $this->quietHourEnd && $hour >= $this->quietHourStart) {
                $local = $local->modify('+1 day');
            }
            $candidate = $local->setTimezone($failedAt->getTimezone());
        }

        return $candidate;
    }

    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    private function inQuietHours(int $hour): bool
    {
        if ($this->quietHourStart === $this->quietHourEnd) {
            return false;
        }
        if ($this->quietHourStart < $this->quietHourEnd) {
            return $hour >= $this->quietHourStart && $hour < $this->quietHourEnd;
        }
        return $hour >= $this->quietHourStart || $hour < $this->quietHourEnd;
    }
}
