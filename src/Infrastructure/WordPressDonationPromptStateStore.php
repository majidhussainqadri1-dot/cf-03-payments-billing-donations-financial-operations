<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use Sabri\CF03\Contracts\DonationPromptStateStore;
use Sabri\CF03\Domain\DonationPromptState;

final class WordPressDonationPromptStateStore implements DonationPromptStateStore
{
    private const CURRENT_KEYS = [
        'last_donation_prompt_at',
        'next_donation_prompt_at',
        'donation_prompt_status',
        'donation_prompt_snoozed_until',
        'last_donation_completed_at',
        'donation_frequency_preference',
    ];

    /** @var list<string> */
    private const LEGACY_KEYS = ['recurring_donation_status'];

    public function __construct(private readonly int $userId)
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('WordPress donation prompt store requires a valid user ID.');
        }
    }

    public function load(string $subjectReference): DonationPromptState
    {
        $this->assertSubject($subjectReference);
        if (!function_exists('get_user_meta')) {
            throw new RuntimeException('WordPress donation prompt state storage is unavailable.');
        }

        $data = [];
        foreach (array_merge(self::CURRENT_KEYS, self::LEGACY_KEYS) as $key) {
            $value = get_user_meta($this->userId, $key, true);
            if ($value === '' || $value === null) {
                $data[$key] = null;
            } elseif (is_scalar($value)) {
                $data[$key] = (string)$value;
            } else {
                throw new RuntimeException('Stored donation prompt state contains an unsupported value.');
            }
        }
        return DonationPromptState::fromStorage($data);
    }

    public function save(string $subjectReference, DonationPromptState $state): void
    {
        $this->assertSubject($subjectReference);
        if (!function_exists('update_user_meta') || !function_exists('delete_user_meta') || !function_exists('get_user_meta')) {
            throw new RuntimeException('WordPress donation prompt state storage is unavailable.');
        }

        $storage = $state->toStorage();
        foreach (self::CURRENT_KEYS as $key) {
            $value = $storage[$key] ?? null;
            if ($value === null || $value === '') {
                delete_user_meta($this->userId, $key);
                continue;
            }
            $result = update_user_meta($this->userId, $key, $value);
            if ($result === false) {
                $current = get_user_meta($this->userId, $key, true);
                if (!is_scalar($current) || (string)$current !== $value) {
                    throw new RuntimeException('Donation prompt state could not be persisted.');
                }
            }
        }
        // Old recurring preference keys are deliberately deleted after successful
        // normalization so they cannot be revived as an active mandate.
        foreach (self::LEGACY_KEYS as $legacy) { delete_user_meta($this->userId, $legacy); }
    }

    private function assertSubject(string $subjectReference): void
    {
        if (!hash_equals('user:'.$this->userId, $subjectReference)) {
            throw new InvalidArgumentException('Donation prompt subject does not match the WordPress user scope.');
        }
    }
}
