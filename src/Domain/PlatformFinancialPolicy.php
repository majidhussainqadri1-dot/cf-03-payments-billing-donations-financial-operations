<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PlatformFinancialPolicy
{
    public const POLICY_NAME = 'Sabri Platform Free-Core and Voluntary One-Time Donation Policy';
    public const DECISION_ID = 'CF03-2026-v1.0';
    public const SUPERSEDES_DECISION_ID = 'SSH-FIN-DONATION-2026-08-04-01';
    public const EFFECTIVE_AT = '2026-08-02T17:09:00+05:00';
    public const MODE = 'single_free_tier_voluntary_one_time_donation';
    public const APPEAL_MINIMUM_DAYS = 7;
    /** @deprecated Kept only for source compatibility; the governing rule is seven days, not monthly. */
    public const MONTHLY_PROMPT_MINIMUM_DAYS = self::APPEAL_MINIMUM_DAYS;
    public const GOVERNING_MASTER_PLAN = 'SSH-PMP-2026-v3.0';
    public const GOVERNING_CF03_PLAN = 'CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.0';

    /** @return list<Money> */
    public function suggestedDonationAmounts(): array
    {
        // Suggestions are optional conveniences only. No amount is ever preselected.
        return [new Money(1000, 'USD'), new Money(1400, 'USD'), new Money(5000, 'USD')];
    }

    public function defaultDonationAmount(): ?Money { return null; }
    public function defaultRecurringDonation(): bool { return false; }
    public function recurringDonationAvailable(): bool { return false; }
    public function oneTimeDonationOnly(): bool { return true; }
    public function allCoreServicesFree(): bool { return true; }
    public function aiCoreFree(): bool { return true; }
    public function structuredEducationFree(): bool { return true; }
    public function paidServicesSuspended(): bool { return true; }
    public function fixedFeesProhibited(): bool { return true; }
    public function platformCommissionBasisPoints(): int { return 0; }
    public function founderOwned(): bool { return true; }
    public function isTrust(): bool { return false; }
    public function transparencyRequired(): bool { return true; }
    public function founderWithdrawalDisclosureRequired(): bool { return true; }

    /** @return list<string> */
    public function approvedExpenseCategories(): array { return DonationExpenseCategory::allowed(); }

    /** @return list<string> */
    public function prohibitedUses(): array
    {
        return [
            'general_unrelated_charity',
            'unrelated_grants',
            'political_or_election_activity',
            'unrelated_business_investment',
            'personal_luxury',
            'donor_ranking_or_privilege',
            'paid_core_access',
            'paid_ai_access',
            'paid_education_access',
            'recurring_donation_mandate',
        ];
    }

    public function assertCollectibleProduct(FinancialProduct $product): void
    {
        if ($product->kind() !== ProductKind::DONATION) {
            throw new InvariantViolation(
                'Paid membership, education, AI and other core-platform access are prohibited under '.self::GOVERNING_CF03_PLAN.'.'
            );
        }
    }

    public function assertOneTimeDonation(bool $recurring): void
    {
        if ($recurring) {
            throw new InvariantViolation('Recurring donations are unavailable; each donation must be a new voluntary one-time action.');
        }
    }

    public function assertNoPlatformFee(int $basisPoints): void
    {
        if ($basisPoints !== 0) {
            throw new InvariantViolation('Platform fees and Clinic/Marketplace commissions must remain 0%.');
        }
    }

    public function assertSuggestedOrCustomDonation(Money $amount): void
    {
        if ($amount->currency() !== 'USD' || $amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Donation amount must be a positive USD amount.');
        }
    }

    public function assertApprovedExpenseCategory(string $category): void
    {
        DonationExpenseCategory::assertAllowed($category);
    }

    /** @return array<string,string> */
    public function publicDisclosure(): array
    {
        return [
            'ur' => 'Sabri Social Homeopathy Platform ایک Founder-Owned ادارہ ہے، Trust یا charitable trust نہیں۔ منظور شدہ بنیادی پلیٹ فارم خدمات، منظم تعلیم اور Sabri Classical Homeopathy AI ایک ہی مفت درجے میں دستیاب ہیں۔ عطیہ مکمل طور پر رضاکارانہ، صرف یک وقتی، بلا امتیاز اور رسائی، رینکنگ، تصدیق، سپورٹ یا کسی بنیادی سہولت سے غیر مربوط ہے۔ کوئی رقم پہلے سے منتخب نہیں ہوتی، بار بار خودکار چارج یا recurring mandate موجود نہیں، اور Clinic/Marketplace platform commission صفر فیصد ہے۔',
            'en-US' => 'Sabri Social Homeopathy Platform is founder-owned and is not a trust or charitable trust. Approved core platform services, structured education, and Sabri Classical Homeopathy AI are available on one free tier. Donations are voluntary, one-time only, non-privileging, and unrelated to access, ranking, verification, support, or any core capability. No amount is preselected, no recurring mandate or automatic repeat charge is permitted, and Clinic/Marketplace platform commission is 0%.'
        ];
    }
}
