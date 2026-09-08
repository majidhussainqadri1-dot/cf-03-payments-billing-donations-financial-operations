<?php

declare(strict_types=1);

namespace Sabri\CF03\Contracts;

use Sabri\CF03\Domain\DonationPromptState;

interface DonationPromptStateStore
{
    public function load(string $subjectReference): DonationPromptState;

    public function save(string $subjectReference, DonationPromptState $state): void;
}
