<?php

declare(strict_types=1);

namespace Sabri\CF03;

use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Domain\PlatformFinancialPolicy;

final class Plugin
{
    public const OPTION_VERSION = 'sabri_cf03_version';
    public const OPTION_SCHEMA_VERSION = 'sabri_cf03_schema_version';
    public const OPTION_RUNTIME_STATUS = 'sabri_cf03_runtime_status';
    public const OPTION_ACTIVATION_RECORD = 'sabri_cf03_activation_record';
    public const OPTION_FINANCIAL_POLICY_DECISION = 'sabri_cf03_financial_policy_decision';

    private const DONATION_PROMPT_META = [
        'last_donation_prompt_at',
        'donation_prompt_status',
        'donation_prompt_snoozed_until',
        'last_donation_completed_at',
        'donation_frequency_preference',
    ];

    public static function activate(): void
    {
        if (! function_exists('add_option') || ! function_exists('update_option')) { return; }
        add_option(self::OPTION_RUNTIME_STATUS, 'donation_preparing_paid_services_suspended', '', false);
        add_option(self::OPTION_ACTIVATION_RECORD, [], '', false);
        update_option(self::OPTION_VERSION, defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.0.0-rc.2', false);
        update_option(self::OPTION_SCHEMA_VERSION, defined('SABRI_CF03_SCHEMA_VERSION') ? SABRI_CF03_SCHEMA_VERSION : '1.0.0', false);
        update_option(self::OPTION_FINANCIAL_POLICY_DECISION, PlatformFinancialPolicy::DECISION_ID, false);
    }

    public static function boot(): void
    {
        if (function_exists('add_action')) {
            add_action('init', [self::class, 'registerDonationPromptMeta']);
            add_action('admin_notices', [self::class, 'renderConditionalNotice']);
        }
        if (function_exists('add_filter')) {
            add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']);
        }
    }

    public static function registerDonationPromptMeta(): void
    {
        if (! function_exists('register_meta')) { return; }
        foreach (self::DONATION_PROMPT_META as $key) {
            register_meta('user', $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'default' => '',
            ]);
        }
    }

    public static function renderConditionalNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) { return; }
        $status = EvidenceBoundActivationGate::forWordPress()->evaluate();
        $message = 'CF-03 policy ' . PlatformFinancialPolicy::DECISION_ID
            . ': all membership, education, AI and platform-service charges are suspended. '
            . 'Only voluntary donation infrastructure may proceed; live collection remains unavailable until all provider and external gates pass. '
            . ($status->approved() ? 'Financial evidence gates are configured.' : 'Missing financial gates: ' . implode(', ', $status->missingGates()) . '.');
        echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';
    }

    /** @param array<string,mixed> $tests @return array<string,mixed> */
    public static function registerSiteHealthTest(array $tests): array
    {
        $tests['direct']['sabri_cf03_activation_gate'] = ['label'=>'CF-03 free-platform financial policy','test'=>[self::class,'runSiteHealthTest']];
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function runSiteHealthTest(): array
    {
        $status = EvidenceBoundActivationGate::forWordPress()->evaluate();
        return [
            'label' => 'Platform fees suspended; donation service preparing',
            'status' => 'recommended',
            'badge' => ['label'=>'Sabri CF-03','color'=>'blue'],
            'description' => '<p>' . esc_html(
                'Decision ' . PlatformFinancialPolicy::DECISION_ID
                . ' is active. Paid products are dormant and non-collectible; platform commission is 0%; donation collection is not live. '
                . ($status->approved() ? 'Configured evidence gates are present.' : 'Missing gates: '.implode(', ',$status->missingGates()).'.')
            ) . '</p>',
            'actions' => '',
            'test' => 'sabri_cf03_activation_gate',
        ];
    }
}
