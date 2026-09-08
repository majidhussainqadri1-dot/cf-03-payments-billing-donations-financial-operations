<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Contracts\DonationPromptStateStore;
use Sabri\CF03\Domain\DonationPromptState;

final class MemoryDonationPromptStateStore implements DonationPromptStateStore
{
    /** @var array<string,DonationPromptState> */
    private array $states = [];

    public function load(string $subjectReference): DonationPromptState
    {
        return $this->states[$subjectReference] ?? new DonationPromptState();
    }

    public function save(string $subjectReference, DonationPromptState $state): void
    {
        $this->states[$subjectReference] = $state;
    }
}
