<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class DonationNeutralityPolicy
{
    /** @var list<string> */
    private const PROHIBITED_SIGNAL_KEYS = [
        'entitlement','grant_access','access_level','rank','ranking_boost','recommendation_boost','visibility_boost',
        'verification','badge','publishing_priority','moderation_priority','support_priority','clinic_priority',
        'marketplace_priority','education_priority','ai_priority','clinical_priority','storage_bonus','speed_bonus',
    ];

    /** @param array<string,mixed> $projection */
    public function assertNeutralProjection(array $projection): void
    {
        $this->walk($projection, 'root');
    }

    /** @return array<string,mixed> */
    public function publicContract(): array
    {
        return [
            'decision_id'=>PlatformFinancialPolicy::DECISION_ID,
            'donor_and_non_donor_core_capabilities_equal'=>true,
            'commission_basis_points'=>0,
            'prohibited_advantages'=>self::PROHIBITED_SIGNAL_KEYS,
            'allowed_differences'=>['private_receipt','private_donation_history','revocable_public_acknowledgment'],
            'file00_entitlement_owner'=>true,
            'file26_ranking_must_ignore_donation'=>true,
        ];
    }

    /** @param array<string,mixed> $data */
    private function walk(array $data, string $path): void
    {
        foreach ($data as $key=>$value) {
            $normalized=strtolower((string)$key);
            foreach (self::PROHIBITED_SIGNAL_KEYS as $prohibited) {
                if ($normalized===$prohibited || str_contains($normalized,$prohibited)) {
                    if ($value!==null && $value!==false && $value!==0 && $value!=='' && $value!==[]) {
                        throw new InvariantViolation('Donation projection contains prohibited privilege signal at '.$path.'.'.$normalized.'.');
                    }
                }
            }
            if (is_array($value)) { $this->walk($value,$path.'.'.$normalized); }
        }
    }
}
