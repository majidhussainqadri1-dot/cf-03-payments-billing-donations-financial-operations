<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\DonationPromptStateStore;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptContext;
use Sabri\CF03\Domain\DonationPromptDecision;
use Sabri\CF03\Domain\DonationPromptPolicy;

final class DonationPromptService
{
    /** @param null|callable():DateTimeImmutable $clock */
    public function __construct(
        private readonly DonationPromptStateStore $store,
        private readonly DonationPromptPolicy $policy = new DonationPromptPolicy(),
        private readonly mixed $clock = null
    ) {
        if ($clock !== null && ! is_callable($clock)) {
            throw new InvalidArgumentException('Donation prompt clock must be callable when provided.');
        }
    }

    public function decision(string $subjectReference, DonationPromptContext $context): DonationPromptDecision
    {
        return $this->policy->decide($this->store->load($subjectReference), $context, $this->now());
    }

    public function record(string $subjectReference, DonationPromptAction $action): void
    {
        $current = $this->store->load($subjectReference);
        $this->store->save($subjectReference, $current->apply($action, $this->now()));
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Donation prompt clock must return DateTimeImmutable.');
        }
        return $now;
    }
}
