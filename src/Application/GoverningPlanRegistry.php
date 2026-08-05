<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use Sabri\CF03\Domain\PlatformFinancialPolicy;

final class GoverningPlanRegistry
{
    public const DEFINITIVE_MASTER_PLAN='SSH-PMP-2026-v3.0';
    public const ALL_CHATS_REGISTER='Sabri Platform All-Chats Recovered Directive Register 2026 v2.0';
    public const CF03_FINAL_PLAN='CF-03 Integrated Final Plan 2026 v2.0';
    public const RECOVERED_WEEKLY_DIRECTIVE='RCD-022';
    public const RECOVERED_FREE_BASELINE='RCD-020';
    public const RECOVERED_DONATION_AMOUNTS='RCD-021';
    public const RECOVERED_ZERO_COMMISSION='RCD-023';

    /** @return list<string> */
    public static function governingPlans(): array
    {
        return [self::DEFINITIVE_MASTER_PLAN,self::ALL_CHATS_REGISTER,self::CF03_FINAL_PLAN];
    }

    /** @return array<string,array<string,string>> */
    public static function directiveResolutions(): array
    {
        return [
            self::RECOVERED_FREE_BASELINE=>[
                'status'=>'active_consistent',
                'implemented_by'=>'CF03-FR-036',
                'reason'=>'The recovered free-platform baseline is consistent with the later Founder-approved financial decision.',
            ],
            self::RECOVERED_DONATION_AMOUNTS=>[
                'status'=>'active_consistent',
                'implemented_by'=>'CF03-FR-035-through-CF03-FR-042',
                'reason'=>'USD 10, USD 14, USD 50 and positive custom USD remain approved with no preselection.',
            ],
            self::RECOVERED_WEEKLY_DIRECTIVE=>[
                'status'=>'superseded_conflict_resolved',
                'implemented_by'=>'CF03-FR-037',
                'superseded_by'=>PlatformFinancialPolicy::DECISION_ID,
                'replacement'=>'At most one Donation Appeal per calendar month, with at least 30 days after Remind Me Later, Not Now, Close or completed donation.',
                'reason'=>'The dated Founder decision effective 2026-08-04T22:02:00+05:00 is later and more specific than the recovered seven-day wording.',
            ],
            self::RECOVERED_ZERO_COMMISSION=>[
                'status'=>'active_consistent',
                'implemented_by'=>'CF03-FR-036',
                'reason'=>'Clinic and Marketplace platform commission remains 0%, with no donation-linked advantage.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function auditContract(): array
    {
        return [
            'governing_plans'=>self::governingPlans(),
            'latest_financial_decision'=>PlatformFinancialPolicy::DECISION_ID,
            'latest_financial_decision_effective_at'=>PlatformFinancialPolicy::EFFECTIVE_AT,
            'directive_resolutions'=>self::directiveResolutions(),
            'live_collection_enabled'=>false,
        ];
    }
}
