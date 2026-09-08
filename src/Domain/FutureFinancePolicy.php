<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Application\FutureExpansionRegistry;

final class FutureFinancePolicy
{
    public const AMENDMENT_ID = FutureExpansionRegistry::AMENDMENT_ID;
    public const ACTIVE_DONATION_MODEL = 'voluntary_one_time_only';
    public const PLATFORM_COMMISSION_BASIS_POINTS = 0;

    /** @return array<string,mixed> */
    public function constitutionalLock(): array
    {
        return [
            'single_free_core_tier' => true,
            'structured_education_free' => true,
            'sabri_ai_core_free' => true,
            'platform_commission_basis_points' => 0,
            'donation_type' => 'one_time',
            'recurring_donation_available' => false,
            'automatic_repeat_charge' => false,
            'donor_privilege_allowed' => false,
            'paid_core_allowed' => false,
            'future_pack_activated_by_code_presence' => false,
            'live_state_inferred_from_repository' => false,
        ];
    }

    public function currentLawUnchanged(): bool
    {
        return true;
    }
}
