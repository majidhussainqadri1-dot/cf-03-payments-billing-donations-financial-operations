<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PlatformFinancialPolicy
{
    public const POLICY_NAME='Sabri Platform Voluntary Donation and Financial Transparency Policy';
    public const DECISION_ID='SSH-FIN-DONATION-2026-08-04-01';
    public const SUPERSEDES_DECISION_ID='SSH-FIN-2026-08-04-01';
    public const EFFECTIVE_AT='2026-08-04T22:02:00+05:00';
    public const MODE='founder_owned_voluntary_donation_transparency';
    public const MONTHLY_PROMPT_MINIMUM_DAYS=30;

    /** @return list<Money> */
    public function suggestedDonationAmounts(): array{return [new Money(1000,'USD'),new Money(1400,'USD'),new Money(5000,'USD')];}
    public function defaultDonationAmount(): ?Money{return null;}
    public function defaultRecurringDonation(): bool{return false;}
    public function paidServicesSuspended(): bool{return true;}
    public function fixedFeesProhibited(): bool{return true;}
    public function platformCommissionBasisPoints(): int{return 0;}
    public function founderOwned(): bool{return true;}
    public function isTrust(): bool{return false;}
    public function transparencyRequired(): bool{return true;}
    public function founderWithdrawalDisclosureRequired(): bool{return true;}
    /** @return list<string> */ public function approvedExpenseCategories(): array{return DonationExpenseCategory::allowed();}
    /** @return list<string> */ public function prohibitedUses(): array{return ['general_unrelated_charity','unrelated_grants','political_or_election_activity','unrelated_business_investment','personal_luxury','donor_ranking_or_privilege'];}

    public function assertCollectibleProduct(FinancialProduct $product): void
    {
        if($product->kind()!==ProductKind::DONATION){throw new InvariantViolation('Fixed membership, education, AI and platform-service fees are suspended under '.self::DECISION_ID.'.');}
    }
    public function assertNoPlatformFee(int $basisPoints): void{if($basisPoints!==0){throw new InvariantViolation('Platform fees and Clinic/Marketplace commissions must remain 0%.');}}
    public function assertSuggestedOrCustomDonation(Money $amount): void{if($amount->currency()!=='USD'||$amount->minorUnits()<=0){throw new InvalidArgumentException('Donation amount must be a positive USD amount.');}}
    public function assertApprovedExpenseCategory(string $category): void{DonationExpenseCategory::assertAllowed($category);}

    /** @return array<string,string> */
    public function publicDisclosure(): array
    {
        return [
            'ur'=>'Sabri Social Homeopathy Platform ایک Founder-Owned ادارہ ہے، Trust یا charitable trust نہیں۔ پلیٹ فارم کسی مقرر فیس کے بغیر چلایا جارہا ہے اور رضاکارانہ عطیات سے اس کی بنیادی ضروریات، بقا، تکنیکی و علمی ترقی، نئی سہولتوں اور ہومیوپیتھی کی ترویج میں مدد حاصل کی جاتی ہے۔ موصول شدہ رقم، اہم اخراجات، موجودہ balance اور بانی کو ادا یا نکالی گئی رقم شفافیت کے صفحے پر ظاہر کی جاتی ہے۔',
            'en-US'=>'Sabri Social Homeopathy Platform is a founder-owned platform and is not a trust or charitable trust. The platform currently charges no fixed fee and is supported through voluntary donations. Donations are used for essential operational needs, institutional sustainability, technical and educational development, new facilities, and the advancement of homeopathy. Summary information about funds received, major expenses, current balance, and amounts paid to or withdrawn by the Founder is published on the financial transparency page.'
        ];
    }
}
