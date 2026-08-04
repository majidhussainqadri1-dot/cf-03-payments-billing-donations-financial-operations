<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class DonationAppealCopy
{
    /** @return array<string,mixed> */
    public static function contract(): array
    {
        return [
            'decision_id' => 'SSH-FIN-2026-08-04-01',
            'amounts' => [
                ['currency' => 'USD', 'minor_units' => 1000, 'label' => '$10'],
                ['currency' => 'USD', 'minor_units' => 1400, 'label' => '$14'],
                ['currency' => 'USD', 'minor_units' => 5000, 'label' => '$50'],
                ['custom' => true, 'label' => 'Custom Amount'],
            ],
            'preselected_amount' => null,
            'monthly_checkbox' => [
                'label' => 'Make this a monthly donation',
                'checked' => false,
            ],
            'actions' => ['Support Now', 'Remind Me Later', 'Not Now', 'Close'],
            'ur' => [
                'heading' => 'اس علمی و انسانی خدمت کو قائم رکھنے میں ہمارا ساتھ دیجیے',
                'message' => 'صابری سوشل ہومیوپیتھی پلیٹ فارم فی الحال تمام صارفین کے لیے فی سبیل اللہ مکمل مفت رکھا گیا ہے۔ اس نظام کو قائم رکھنے، مزید وسعت دینے، نئی آسانیاں پیدا کرنے، علمِ ہومیوپیتھی کی حفاظت و اشاعت، انسانیت کی خدمت، اور دنیا بھر کے ڈاکٹروں اور مریضوں کی بھلائی کے لیے آپ کی اختیاری اعانت ہمارے لیے قیمتی ہے۔',
                'monthly' => 'مستقل خدمت میں تعاون کے لیے آپ اختیاری ماہانہ اعانت بھی منتخب کرسکتے ہیں۔',
                'assurance' => 'اعانت مکمل اختیاری ہے۔ اعانت نہ دینے سے آپ کی رسائی، عزت، ranking، verification یا کسی سہولت پر کوئی اثر نہیں پڑے گا۔',
            ],
            'en-US' => [
                'heading' => 'Help Us Sustain and Expand This Service',
                'message' => 'Sabri Social Homeopathy Platform is currently provided completely free for the sake of serving humanity. Your voluntary support helps us maintain the platform, introduce new facilities, preserve and advance homeopathic knowledge, and serve doctors and patients around the world.',
                'assurance' => 'Donations are entirely optional. Donating or not donating will never affect your access, visibility, verification, ranking, or eligibility for any core service.',
            ],
            'guest_storage' => [
                'first_party_only' => true,
                'keys' => ['sabri_donation_prompt_seen', 'sabri_donation_prompt_next_at'],
            ],
            'logged_in_fields' => [
                'last_donation_prompt_at',
                'donation_prompt_status',
                'donation_prompt_snoozed_until',
                'last_donation_completed_at',
                'donation_frequency_preference',
            ],
        ];
    }
}
