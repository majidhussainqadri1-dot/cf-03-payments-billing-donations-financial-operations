<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use InvalidArgumentException;
use Sabri\CF03\Contracts\DonationPromptStateStore;
use Sabri\CF03\Domain\DonationPromptState;

final class WordPressDonationPromptStateStore implements DonationPromptStateStore
{
    private const KEYS = [
        'last_donation_prompt_at',
        'donation_prompt_status',
        'donation_prompt_snoozed_until',
        'last_donation_completed_at',
        'donation_frequency_preference',
    ];

    public function __construct(private readonly int $userId)
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('WordPress donation prompt store requires a valid user ID.');
        }
    }

    public function load(string $subjectReference): DonationPromptState
    {
        if (! function_exists('get_user_meta')) {
            return new DonationPromptState();
        }

        $data = [];
        foreach (self::KEYS as $key) {
            $value = get_user_meta($this->userId, $key, true);
            $data[$key] = is_scalar($value) ? (string) $value : null;
        }

        return DonationPromptState::fromStorage($data);
    }

    public function save(string $subjectReference, DonationPromptState $state): void
    {
        if (! function_exists('update_user_meta') || ! function_exists('delete_user_meta')) {
            return;
        }

        foreach ($state->toStorage() as $key => $value) {
            if ($value === null || $value === '') {
                delete_user_meta($this->userId, $key);
            } else {
                update_user_meta($this->userId, $key, $value);
            }
        }
    }
}
