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
            'policy_name'=>PlatformFinancialPolicy::POLICY_NAME,
            'decision_id'=>PlatformFinancialPolicy::DECISION_ID,
            'supersedes_decision_id'=>PlatformFinancialPolicy::SUPERSEDES_DECISION_ID,
            'founder_owned'=>true,
            'is_trust'=>false,
            'transparency_path'=>'/transparency/',
            'amounts'=>[
                ['currency'=>'USD','minor_units'=>1000,'label'=>'$10'],
                ['currency'=>'USD','minor_units'=>1400,'label'=>'$14'],
                ['currency'=>'USD','minor_units'=>5000,'label'=>'$50'],
                ['custom'=>true,'label'=>'Custom Amount']
            ],
            'preselected_amount'=>null,
            'monthly_checkbox'=>['label'=>'Make this a monthly donation','checked'=>false],
            'actions'=>['Donate','Remind Me Later','Not Now','Close'],
            'ur'=>[
                'heading'=>'اس علمی اور انسانی خدمت کو قائم رکھنے میں ہمارا ساتھ دیجیے',
                'message'=>'صابری سوشل ہومیوپیتھی پلیٹ فارم کسی مقرر فیس کے بغیر علمی، طبی اور انسانی خدمت کے لیے قائم کیا گیا ہے۔ اس ادارے کی بنیادی ضروریات، بقا، تکنیکی و علمی دیکھ بھال، نئی سہولتوں، مسلسل ترقی، اور ہومیوپیتھی کی تعلیم، تحقیق اور عالمی ترویج کے لیے آپ اپنی استطاعت کے مطابق رضاکارانہ عطیہ دے سکتے ہیں۔',
                'assurance'=>'عطیہ دینا مکمل اختیاری ہے۔ عطیہ نہ دینے سے آپ کی رسائی، membership، verification، ranking، profile، treatment، publishing یا کسی بنیادی سہولت پر کوئی اثر نہیں پڑے گا۔'
            ],
            'en-US'=>[
                'heading'=>'Help Sustain This Educational and Humanitarian Service',
                'message'=>'Sabri Social Homeopathy Platform operates without a fixed platform fee. Voluntary donations support essential operations, institutional sustainability, technical and educational development, new facilities, and the advancement of homeopathy.',
                'assurance'=>'Donating is entirely optional. Donating or not donating will never affect access, membership, verification, ranking, profile, treatment, publishing, or any core service.'
            ],
            'frequency'=>['calendar_month_maximum'=>1,'remind_later_minimum_days'=>30,'not_now_minimum_days'=>30,'close_minimum_days'=>30,'completed_donation_minimum_days'=>30,'active_monthly_donor_general_prompt'=>false,'per_session_maximum'=>1,'per_page_view_maximum'=>1],
            'guest_storage'=>['first_party_only'=>true,'keys'=>['sabri_donation_prompt_seen_at','sabri_donation_prompt_next_at']],
            'logged_in_fields'=>['last_donation_prompt_at','next_donation_prompt_at','donation_prompt_status','donation_prompt_snoozed_until','last_donation_completed_at','recurring_donation_status'],
            'legacy_migration_aliases'=>['donation_frequency_preference'=>'recurring_donation_status','sabri_donation_prompt_seen'=>'sabri_donation_prompt_seen_at']
        ];
    }
}
