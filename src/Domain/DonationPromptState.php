<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class DonationPromptState
{
    private readonly DonationFrequencyPreference $frequencyPreference;

    public function __construct(
        private readonly ?DateTimeImmutable $lastDonationPromptAt = null,
        private readonly DonationPromptStatus $status = DonationPromptStatus::NEVER_SEEN,
        private readonly ?DateTimeImmutable $snoozedUntil = null,
        private readonly ?DateTimeImmutable $lastDonationCompletedAt = null,
        DonationFrequencyPreference $frequencyPreference = DonationFrequencyPreference::NONE,
        private readonly ?DateTimeImmutable $nextDonationPromptAt = null
    ) {
        // Legacy monthly values are migration input only. They are normalized to the
        // one-time/non-recurring model and never create an active recurring state.
        $this->frequencyPreference = match ($frequencyPreference) {
            DonationFrequencyPreference::MONTHLY_ACTIVE,
            DonationFrequencyPreference::MONTHLY_CANCELLED => DonationFrequencyPreference::ONE_TIME,
            default => $frequencyPreference,
        };
    }

    public function lastDonationPromptAt(): ?DateTimeImmutable { return $this->lastDonationPromptAt; }
    public function status(): DonationPromptStatus { return $this->status; }
    public function snoozedUntil(): ?DateTimeImmutable { return $this->snoozedUntil; }
    public function lastDonationCompletedAt(): ?DateTimeImmutable { return $this->lastDonationCompletedAt; }
    public function frequencyPreference(): DonationFrequencyPreference { return $this->frequencyPreference; }
    public function nextDonationPromptAt(): ?DateTimeImmutable { return $this->nextDonationPromptAt; }

    public function apply(DonationPromptAction $action, DateTimeImmutable $at): self
    {
        $latest = $this->latestKnownAt();
        if ($latest !== null && $at < $latest) {
            throw new InvariantViolation('Donation prompt action chronology is invalid.');
        }

        if (in_array($action, [
            DonationPromptAction::DONATION_COMPLETED_MONTHLY,
            DonationPromptAction::MONTHLY_CANCELLED,
        ], true)) {
            throw new InvariantViolation('Recurring donation actions are retired under the current one-time-only financial constitution.');
        }

        $sevenDays = $at->modify('+7 days');
        return match ($action) {
            DonationPromptAction::SHOWN => new self(
                $at,
                DonationPromptStatus::SHOWN,
                $this->snoozedUntil,
                $this->lastDonationCompletedAt,
                $this->frequencyPreference,
                $sevenDays
            ),
            DonationPromptAction::REMIND_LATER => new self(
                $this->lastDonationPromptAt ?? $at,
                DonationPromptStatus::SNOOZED,
                $sevenDays,
                $this->lastDonationCompletedAt,
                $this->frequencyPreference,
                $sevenDays
            ),
            DonationPromptAction::NOT_NOW => new self(
                $this->lastDonationPromptAt ?? $at,
                DonationPromptStatus::NOT_NOW,
                $sevenDays,
                $this->lastDonationCompletedAt,
                $this->frequencyPreference,
                $sevenDays
            ),
            DonationPromptAction::CLOSE => new self(
                $this->lastDonationPromptAt ?? $at,
                DonationPromptStatus::CLOSED,
                $sevenDays,
                $this->lastDonationCompletedAt,
                $this->frequencyPreference,
                $sevenDays
            ),
            DonationPromptAction::DONATION_COMPLETED_ONE_TIME => new self(
                $this->lastDonationPromptAt,
                DonationPromptStatus::DONATION_COMPLETED,
                $sevenDays,
                $at,
                DonationFrequencyPreference::ONE_TIME,
                $sevenDays
            ),
            DonationPromptAction::DONATION_COMPLETED_MONTHLY,
            DonationPromptAction::MONTHLY_CANCELLED => throw new InvariantViolation(
                'Recurring donation actions are retired under the current one-time-only financial constitution.'
            ),
        };
    }

    /** @return array<string,string|null> */
    public function toStorage(): array
    {
        return [
            'last_donation_prompt_at' => $this->lastDonationPromptAt?->format(DATE_ATOM),
            'next_donation_prompt_at' => $this->nextDonationPromptAt?->format(DATE_ATOM),
            'donation_prompt_status' => $this->status->value,
            'donation_prompt_snoozed_until' => $this->snoozedUntil?->format(DATE_ATOM),
            'last_donation_completed_at' => $this->lastDonationCompletedAt?->format(DATE_ATOM),
            'donation_frequency_preference' => $this->frequencyPreference->value,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromStorage(array $data): self
    {
        $statusValue = self::stringValue($data, 'donation_prompt_status');
        $status = $statusValue === ''
            ? DonationPromptStatus::NEVER_SEEN
            : DonationPromptStatus::tryFrom($statusValue);
        if (!$status instanceof DonationPromptStatus) {
            throw new InvalidArgumentException('Stored donation prompt status is invalid.');
        }
        if ($status === DonationPromptStatus::MONTHLY_ACTIVE) {
            $status = DonationPromptStatus::DONATION_COMPLETED;
        }

        $preferenceValue = self::stringValue($data, 'donation_frequency_preference');
        if ($preferenceValue === '') {
            $preferenceValue = self::stringValue($data, 'recurring_donation_status');
        }
        $preference = $preferenceValue === ''
            ? DonationFrequencyPreference::NONE
            : DonationFrequencyPreference::tryFrom($preferenceValue);
        if (!$preference instanceof DonationFrequencyPreference) {
            throw new InvalidArgumentException('Stored donation frequency status is invalid.');
        }

        return new self(
            self::date($data['last_donation_prompt_at'] ?? null, 'last_donation_prompt_at'),
            $status,
            self::date($data['donation_prompt_snoozed_until'] ?? null, 'donation_prompt_snoozed_until'),
            self::date($data['last_donation_completed_at'] ?? null, 'last_donation_completed_at'),
            $preference,
            self::date($data['next_donation_prompt_at'] ?? null, 'next_donation_prompt_at')
        );
    }

    private function latestKnownAt(): ?DateTimeImmutable
    {
        $latest = null;
        foreach ([$this->lastDonationPromptAt, $this->lastDonationCompletedAt] as $candidate) {
            if ($candidate !== null && ($latest === null || $candidate > $latest)) {
                $latest = $candidate;
            }
        }
        return $latest;
    }

    private static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') { return ''; }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Stored donation prompt scalar is invalid: '.$key.'.');
        }
        return $value;
    }

    private static function date(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Stored donation prompt date is invalid: '.$field.'.');
        }
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if (!$date instanceof DateTimeImmutable || $date->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException('Stored donation prompt date is invalid: '.$field.'.');
        }
        return $date;
    }
}
