<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class DonationPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_PRIVILEGE_SIGNALS = [
        'ranking_boost',
        'verification_advantage',
        'visibility_advantage',
        'moderation_advantage',
        'support_priority',
        'basic_service_unlock',
        'badge',
        'publication_privilege',
        'clinic_priority',
        'recommendation_boost',
    ];

    public function defaultAmount(): ?Money
    {
        return null;
    }

    public function defaultRecurring(): bool
    {
        return false;
    }

    /** @param array<string,mixed> $signals */
    public function assertNoPrivilegeSignals(array $signals): void
    {
        foreach (self::FORBIDDEN_PRIVILEGE_SIGNALS as $key) {
            if (! array_key_exists($key, $signals)) {
                continue;
            }

            $value = $signals[$key];
            if ($value !== null && $value !== false && $value !== 0 && $value !== '') {
                throw new InvariantViolation('Donation must not emit or grant privilege signals.');
            }
        }
    }
}
