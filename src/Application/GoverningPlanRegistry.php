<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use Sabri\CF03\Domain\PlatformFinancialPolicy;

final class GoverningPlanRegistry
{
    public const DEFINITIVE_MASTER_PLAN = 'SSH-PMP-2026-v3.0';
    public const CF03_CONDITIONAL_MASTER_PLAN = 'CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.0';

    public const FREE_BASELINE = 'CHAT-BIZ-022';
    public const SEVEN_DAY_APPEAL = 'CF03-FR-019';
    public const ONE_TIME_ONLY = 'CF03-FR-018';
    public const FREE_AI_EDUCATION = 'CF03-FR-023';
    public const ZERO_COMMISSION = 'CF03-FR-004';

    /** @return list<string> */
    public static function governingPlans(): array
    {
        return [self::DEFINITIVE_MASTER_PLAN, self::CF03_CONDITIONAL_MASTER_PLAN];
    }

    /** @return array<string,array<string,string>> */
    public static function directiveResolutions(): array
    {
        return [
            self::FREE_BASELINE => [
                'status' => 'active_governing',
                'implemented_by' => 'PlatformFinancialPolicy',
                'rule' => 'Single free tier; voluntary donation only; no donor advantage.',
            ],
            self::SEVEN_DAY_APPEAL => [
                'status' => 'active_governing',
                'implemented_by' => 'DonationPromptPolicy and DonationPromptState',
                'rule' => 'At least seven days of silence after dismissal or an appeal display before another eligible appeal.',
            ],
            self::ONE_TIME_ONLY => [
                'status' => 'active_governing',
                'implemented_by' => 'DonationIntentDraft and DonationCheckoutService',
                'rule' => 'Donation attempts are independent one-time actions; recurring state, mandate and automatic repeat charge are unavailable.',
            ],
            self::FREE_AI_EDUCATION => [
                'status' => 'active_governing',
                'implemented_by' => 'PlatformFinancialPolicy and retired finance-metering adapters',
                'rule' => 'Approved structured education and Sabri Classical Homeopathy AI are free core capabilities and are never finance/donor gated.',
            ],
            self::ZERO_COMMISSION => [
                'status' => 'active_governing',
                'implemented_by' => 'CommissionPolicy and PlatformFinancialPolicy',
                'rule' => 'Clinic and Marketplace platform commission is 0%.',
            ],
            'LEGACY-30-DAY-MONTHLY-DONATION' => [
                'status' => 'superseded_prohibited',
                'implemented_by' => 'migration and source guards',
                'rule' => 'The former 30-day/monthly appeal and recurring-donation model is not an active rule.',
            ],
            'LEGACY-PAID-AI-SUBSCRIPTION' => [
                'status' => 'superseded_prohibited',
                'implemented_by' => 'retired billing/subscription adapters and schema v4',
                'rule' => 'Paid AI, subscription, renewal and grace flows are not active CF-03 capabilities.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function auditContract(): array
    {
        return [
            'governing_plans' => self::governingPlans(),
            'financial_policy' => PlatformFinancialPolicy::DECISION_ID,
            'appeal_minimum_days' => PlatformFinancialPolicy::APPEAL_MINIMUM_DAYS,
            'one_time_donation_only' => true,
            'recurring_donation_available' => false,
            'core_ai_and_education_free' => true,
            'platform_commission_basis_points' => 0,
            'directive_resolutions' => self::directiveResolutions(),
            'live_collection_enabled' => false,
        ];
    }
}
