<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;

final class DonationPromptDecision
{
    private function __construct(
        private readonly bool $show,
        private readonly string $reason,
        private readonly ?DateTimeImmutable $nextEligibleAt
    ) {
    }

    public static function showNow(): self
    {
        return new self(true, 'eligible', null);
    }

    public static function suppress(string $reason, ?DateTimeImmutable $nextEligibleAt = null): self
    {
        return new self(false, $reason, $nextEligibleAt);
    }

    public function shouldShow(): bool { return $this->show; }
    public function reason(): string { return $this->reason; }
    public function nextEligibleAt(): ?DateTimeImmutable { return $this->nextEligibleAt; }
}
