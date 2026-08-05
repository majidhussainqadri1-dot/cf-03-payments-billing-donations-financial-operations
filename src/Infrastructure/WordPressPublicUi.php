<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Domain\DonationNeutralityPolicy;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Throwable;

final class WordPressPublicUi
{
    public static function register(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('sabri_cf03_donate', [self::class, 'donate']);
            add_shortcode('sabri_cf03_billing', [self::class, 'billing']);
            add_shortcode('sabri_cf03_transparency', [self::class, 'transparency']);
        }
        if (function_exists('wp_register_style') && defined('SABRI_CF03_VERSION')) {
            wp_register_style(
                'sabri-cf03-public',
                plugins_url('assets/css/public.css', SABRI_CF03_FILE),
                [],
                SABRI_CF03_VERSION
            );
            wp_register_script(
                'sabri-cf03-public',
                plugins_url('assets/js/public.js', SABRI_CF03_FILE),
                [],
                SABRI_CF03_VERSION,
                true
            );
        }
    }

    public static function donate(): string
    {
        $collectionEnabled = false;
        try {
            $collectionEnabled = WordPressRestApi::policy()['live_collection_enabled'] === true;
        } catch (Throwable) {
            $collectionEnabled = false;
        }
        self::assets($collectionEnabled);

        $copy = DonationAppealCopy::contract();
        $language = function_exists('get_locale') && str_starts_with((string)get_locale(), 'ur') ? 'ur' : 'en-US';
        $text = is_array($copy[$language] ?? null) ? $copy[$language] : $copy['en-US'];
        $options = '';
        foreach ($copy['amounts'] ?? [] as $amount) {
            if (!is_array($amount) || !isset($amount['minor_units'])) {
                continue;
            }
            $value = (int)$amount['minor_units'];
            $label = (string)($amount['label'] ?? ('$'.number_format($value / 100, 2)));
            $options .= '<label class="sabri-cf03-choice">'
                .'<input type="radio" name="amount_minor" value="'.esc_attr((string)$value).'"'
                .($collectionEnabled ? '' : ' disabled').'> '
                .'<span>'.esc_html($label).'</span></label>';
        }

        $heading = (string)($text['heading'] ?? __('Voluntary Donation', 'sabri-cf03-finance'));
        $message = (string)($text['message'] ?? '');
        $assurance = (string)($text['assurance'] ?? '');
        $monthlyLabel = (string)($copy['monthly_checkbox']['label'] ?? 'Make this a monthly donation');
        $transparencyPath = (string)($copy['transparency_path'] ?? '/transparency/');
        $preparedMessage = $language === 'ur'
            ? 'محفوظ عطیہ وصولی کا نظام ابھی تیاری اور منظوری کے مرحلے میں ہے؛ فی الحال کوئی رقم وصول نہیں کی جارہی۔'
            : 'Secure donation collection is still being prepared and approved; no funds are being collected at this time.';
        $disabled = $collectionEnabled ? '' : ' disabled aria-disabled="true"';
        $availability = $collectionEnabled
            ? ''
            : '<p class="sabri-cf03-availability" role="status" aria-live="polite">'.esc_html($preparedMessage).'</p>';

        return '<section class="sabri-cf03-card" dir="auto" aria-labelledby="sabri-cf03-donate-title">'
            .'<h2 id="sabri-cf03-donate-title"><ion-icon name="heart-outline" aria-hidden="true"></ion-icon> '
            .esc_html($heading).'</h2>'
            .'<p>'.esc_html($message).'</p>'
            .'<p id="sabri-cf03-donation-assurance" class="sabri-cf03-assurance">'.esc_html($assurance).'</p>'
            .$availability
            .'<form class="sabri-cf03-donation-form" aria-describedby="sabri-cf03-donation-assurance" novalidate>'
            .'<fieldset class="sabri-cf03-amounts"'.($collectionEnabled ? '' : ' disabled').'><legend>'
            .esc_html__('Choose a suggested amount or enter a custom amount', 'sabri-cf03-finance').'</legend>'
            .$options.'</fieldset>'
            .'<label class="sabri-cf03-field"><span>'.esc_html__('Custom USD amount', 'sabri-cf03-finance').'</span>'
            .'<input inputmode="decimal" type="text" pattern="[0-9]+([.][0-9]{1,2})?" name="custom_amount" autocomplete="off"'
            .$disabled.'></label>'
            .'<label class="sabri-cf03-check"><input type="checkbox" name="monthly" value="1"'.$disabled.'> <span>'
            .esc_html($monthlyLabel).'</span></label>'
            .'<input type="hidden" name="idempotency_key" value="">'
            .'<button type="submit"'.$disabled.'><ion-icon name="heart-outline" aria-hidden="true"></ion-icon> '
            .esc_html__('Continue to secure provider', 'sabri-cf03-finance').'</button>'
            .'<p class="sabri-cf03-status" role="status" aria-live="polite" aria-atomic="true"></p>'
            .'</form>'
            .'<p><a href="'.esc_url(home_url($transparencyPath)).'">'
            .'<ion-icon name="document-text-outline" aria-hidden="true"></ion-icon> '
            .esc_html__('Financial transparency', 'sabri-cf03-finance').'</a></p>'
            .'</section>';
    }

    public static function billing(): string
    {
        self::assets();
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return '<section class="sabri-cf03-card" dir="auto"><h2>'
                .esc_html__('Billing and Receipts', 'sabri-cf03-finance').'</h2><p>'
                .esc_html__('Please sign in to view your private receipts, donations and refund status.', 'sabri-cf03-finance')
                .'</p></section>';
        }
        return '<section class="sabri-cf03-card sabri-cf03-billing" dir="auto"><h2>'
            .'<ion-icon name="receipt-outline" aria-hidden="true"></ion-icon> '
            .esc_html__('Billing and Receipts', 'sabri-cf03-finance').'</h2>'
            .'<button type="button" data-sabri-cf03-load-billing>'
            .'<ion-icon name="refresh-outline" aria-hidden="true"></ion-icon> '
            .esc_html__('Load my records', 'sabri-cf03-finance').'</button>'
            .'<div class="sabri-cf03-billing-results" role="region" aria-live="polite" aria-atomic="false"></div>'
            .'</section>';
    }

    public static function transparency(): string
    {
        self::assets();
        try {
            $result = WordPressRestApi::transparency();
        } catch (Throwable) {
            $result = ['status' => 'unavailable', 'snapshot' => null];
        }
        $snapshot = $result['snapshot'] ?? null;
        $body = '<p>'.esc_html((new PlatformFinancialPolicy())->publicDisclosure()['en-US']).'</p>';
        if (is_array($snapshot)) {
            $body .= '<dl>';
            foreach ($snapshot as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $body .= '<dt>'.esc_html((string)$key).'</dt><dd>'.esc_html((string)$value).'</dd>';
                }
            }
            $body .= '</dl>';
        } else {
            $body .= '<p>'.esc_html__(
                'No verified aggregate financial snapshot has been published. No figures are fabricated.',
                'sabri-cf03-finance'
            ).'</p>';
        }
        $neutrality = (new DonationNeutralityPolicy())->publicContract();
        if ($neutrality['donor_and_non_donor_core_capabilities_equal']) {
            $body .= '<p>'.esc_html__(
                'Donors and non-donors receive the same core platform capabilities.',
                'sabri-cf03-finance'
            ).'</p>';
        }
        return '<section class="sabri-cf03-card" dir="auto"><h2>'
            .'<ion-icon name="analytics-outline" aria-hidden="true"></ion-icon> '
            .esc_html__('Financial Transparency', 'sabri-cf03-finance').'</h2>'.$body.'</section>';
    }

    private static function assets(?bool $collectionEnabled = null): void
    {
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('sabri-cf03-public');
        }
        if (!function_exists('wp_enqueue_script')) {
            return;
        }
        if ($collectionEnabled === null) {
            try {
                $collectionEnabled = WordPressRestApi::policy()['live_collection_enabled'] === true;
            } catch (Throwable) {
                $collectionEnabled = false;
            }
        }
        wp_enqueue_script('sabri-cf03-public');
        $data = [
            'root' => esc_url_raw(rest_url(WordPressRestApi::NAMESPACE.'/')),
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'collectionEnabled' => $collectionEnabled,
            'messages' => [
                'working' => __('Working…', 'sabri-cf03-finance'),
                'failed' => __('The request could not be completed.', 'sabri-cf03-finance'),
                'invalidAmount' => __('Choose or enter a valid positive amount with no more than two decimal places.', 'sabri-cf03-finance'),
                'secureContext' => __('A secure browser context is required.', 'sabri-cf03-finance'),
                'recorded' => __('Request recorded.', 'sabri-cf03-finance'),
                'unavailable' => __('Secure donation collection is not currently available.', 'sabri-cf03-finance'),
            ],
        ];
        if (function_exists('wp_add_inline_script')) {
            wp_add_inline_script(
                'sabri-cf03-public',
                'window.SabriCF03='.wp_json_encode($data).';',
                'before'
            );
        }
    }
}
