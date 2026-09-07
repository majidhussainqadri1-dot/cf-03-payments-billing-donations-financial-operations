<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use Sabri\CF03\Domain\PlatformFinancialPolicy;

final class DonationAppealCopy
{
    /** @return array<string,mixed> */
    public static function contract(): array
    {
        return [
            'policy_name' => PlatformFinancialPolicy::POLICY_NAME,
            'decision_id' => PlatformFinancialPolicy::DECISION_ID,
            'governing_cf03_plan' => PlatformFinancialPolicy::GOVERNING_CF03_PLAN,
            'founder_owned' => true,
            'is_trust' => false,
            'transparency_path' => '/transparency/',
            'donation_type' => 'one_time',
            'recurring_available' => false,
            'automatic_repeat_charge' => false,
            'explicit_one_time_consent_required' => true,
            'purpose_code' => 'institutional_sustainability_and_homeopathy_advancement',
            'amounts' => [
                ['currency' => 'USD', 'minor_units' => 1000, 'label' => '$10', 'preselected' => false],
                ['currency' => 'USD', 'minor_units' => 1400, 'label' => '$14', 'preselected' => false],
                ['currency' => 'USD', 'minor_units' => 5000, 'label' => '$50', 'preselected' => false],
                ['custom' => true, 'label' => 'Custom Amount', 'preselected' => false],
            ],
            'preselected_amount' => null,
            'actions' => ['Donate Once', 'Remind Me Later', 'Not Now', 'Close'],
            'ur' => [
                'heading' => 'اس علمی و انسانی خدمت کو قائم رکھنے میں رضاکارانہ تعاون',
                'message' => 'صابری سوشل ہومیوپیتھی پلیٹ فارم کی منظور شدہ بنیادی خدمات، منظم تعلیم اور Sabri Classical Homeopathy AI ایک ہی مفت درجے میں دستیاب ہیں۔ ادارے کی بنیادی ضروریات، تکنیکی و علمی ترقی اور ہومیوپیتھی کی ترویج کے لیے آپ چاہیں تو اپنی استطاعت کے مطابق صرف یک وقتی رضاکارانہ عطیہ دے سکتے ہیں۔',
                'assurance' => 'عطیہ مکمل اختیاری اور صرف یک وقتی ہے۔ کوئی رقم پہلے سے منتخب نہیں، recurring یا خودکار دوبارہ چارج موجود نہیں، اور عطیہ دینے یا نہ دینے سے رسائی، رینکنگ، تصدیق، پروفائل، علاج، تعلیم، AI، اشاعت یا سپورٹ میں کوئی امتیاز نہیں ہوگا۔',
            ],
            'en-US' => [
                'heading' => 'Optional One-Time Support for This Educational Service',
                'message' => 'Approved core services, structured education, and Sabri Classical Homeopathy AI are available on one free tier. If you wish, you may make a voluntary one-time donation to support essential operations, technical and educational development, and the advancement of homeopathy.',
                'assurance' => 'Donating is optional and one-time only. No amount is preselected, recurring or automatic repeat charging is unavailable, and donating or not donating never changes access, ranking, verification, treatment, education, AI, publishing, or support.',
            ],
            'frequency' => [
                'minimum_days_between_appeals' => PlatformFinancialPolicy::APPEAL_MINIMUM_DAYS,
                'remind_later_minimum_days' => 7,
                'not_now_minimum_days' => 7,
                'close_minimum_days' => 7,
                'completed_donation_minimum_days' => 7,
                'per_session_maximum' => 1,
                'per_page_view_maximum' => 1,
            ],
            'guest_storage' => [
                'first_party_only' => true,
                'keys' => ['sabri_donation_prompt_seen_at','sabri_donation_prompt_next_at'],
            ],
            'logged_in_fields' => [
                'last_donation_prompt_at','next_donation_prompt_at','donation_prompt_status',
                'donation_prompt_snoozed_until','last_donation_completed_at','donation_frequency_preference',
            ],
            'legacy_migration_aliases' => [
                'recurring_donation_status' => 'donation_frequency_preference',
                'sabri_donation_prompt_seen' => 'sabri_donation_prompt_seen_at',
            ],
        ];
    }
}
