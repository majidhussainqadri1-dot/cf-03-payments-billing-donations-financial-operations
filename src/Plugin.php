<?php

declare(strict_types=1);

namespace Sabri\CF03;

use RuntimeException;
use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\Schema;

final class Plugin
{
    public const OPTION_VERSION = 'sabri_cf03_version';
    public const OPTION_SCHEMA_VERSION = 'sabri_cf03_schema_version';
    public const OPTION_RUNTIME_STATUS = 'sabri_cf03_runtime_status';
    public const OPTION_ACTIVATION_RECORD = 'sabri_cf03_activation_record';
    public const OPTION_FINANCIAL_POLICY_DECISION = 'sabri_cf03_financial_policy_decision';
    public const OPTION_LAST_MIGRATION = 'sabri_cf03_last_migration';

    private const RUNTIME_STATUS = 'source_complete_runtime_fail_closed_donation_preparing_paid_services_suspended';

    private const DONATION_PROMPT_META = [
        'last_donation_prompt_at',
        'donation_prompt_status',
        'donation_prompt_snoozed_until',
        'last_donation_completed_at',
        'donation_frequency_preference',
    ];

    public static function activate(): void
    {
        if (! function_exists('add_option') || ! function_exists('update_option')) {
            throw new RuntimeException('WordPress option APIs are unavailable during CF-03 activation.');
        }

        add_option(self::OPTION_ACTIVATION_RECORD, [], '', false);
        update_option(self::OPTION_RUNTIME_STATUS, 'schema_installing_fail_closed', false);
        update_option(self::OPTION_FINANCIAL_POLICY_DECISION, PlatformFinancialPolicy::DECISION_ID, false);

        $migrations = WordPressSchemaInstaller::install();
        $expectedCount = count(Schema::tables(''));
        if (count($migrations) !== $expectedCount) {
            update_option(self::OPTION_RUNTIME_STATUS, 'schema_incomplete_fail_closed', false);
            throw new RuntimeException('CF-03 activation did not verify every canonical schema migration.');
        }

        update_option(self::OPTION_VERSION, defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.0.0-rc.3', false);
        update_option(self::OPTION_SCHEMA_VERSION, Schema::VERSION, false);
        update_option(self::OPTION_LAST_MIGRATION, [
            'schema_version' => Schema::VERSION,
            'migration_ids' => $migrations,
            'completed_at' => gmdate(DATE_ATOM),
        ], false);
        update_option(self::OPTION_RUNTIME_STATUS, self::RUNTIME_STATUS, false);
    }

    public static function boot(): void
    {
        if (function_exists('add_action')) {
            add_action('init', [self::class, 'registerDonationPromptMeta']);
            add_action('rest_api_init', [WordPressRestApi::class, 'register']);
            add_action('admin_notices', [self::class, 'renderConditionalNotice']);
        }
        if (function_exists('add_filter')) {
            add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']);
        }
    }

    public static function registerDonationPromptMeta(): void
    {
        if (! function_exists('register_meta')) {
            return;
        }
        foreach (self::DONATION_PROMPT_META as $key) {
            register_meta('user', $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'default' => '',
                'auth_callback' => static function (
                    bool $allowed,
                    string $metaKey,
                    int $objectId
                ): bool {
                    if (! function_exists('get_current_user_id') || ! function_exists('current_user_can')) {
                        return false;
                    }
                    $currentUserId = get_current_user_id();
                    return ($currentUserId > 0 && $currentUserId === $objectId)
                        || current_user_can('sabri_manage_finance');
                },
            ]);
        }
    }

    public static function renderConditionalNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
            return;
        }
        $status = EvidenceBoundActivationGate::forWordPress()->evaluate();
        $message = 'CF-03 policy ' . PlatformFinancialPolicy::DECISION_ID
            . ': all membership, education, AI and platform-service charges are suspended. '
            . 'The complete financial source model and schema are installed, but live collection remains fail closed pending provider and external acceptance. '
            . ($status->approved() ? 'Activation evidence is configured, but no live provider route exists in this candidate.' : 'Missing activation gates: ' . implode(', ', $status->missingGates()) . '.');
        echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';
    }

    /** @param array<string,mixed> $tests @return array<string,mixed> */
    public static function registerSiteHealthTest(array $tests): array
    {
        $tests['direct']['sabri_cf03_activation_gate'] = [
            'label' => 'CF-03 complete financial source and free-platform policy',
            'test' => [self::class, 'runSiteHealthTest'],
        ];
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function runSiteHealthTest(): array
    {
        $status = EvidenceBoundActivationGate::forWordPress()->evaluate();
        return [
            'label' => 'CF-03 source complete; collection remains fail closed',
            'status' => 'recommended',
            'badge' => ['label' => 'Sabri CF-03', 'color' => 'blue'],
            'description' => '<p>' . esc_html(
                'Decision ' . PlatformFinancialPolicy::DECISION_ID
                . ' is active. Schema ' . Schema::VERSION
                . ' is canonical. Paid products are dormant, commission is 0%, donation collection is not live. '
                . ($status->approved() ? 'Configured activation evidence is present.' : 'Missing gates: ' . implode(', ', $status->missingGates()) . '.')
            ) . '</p>',
            'actions' => '',
            'test' => 'sabri_cf03_activation_gate',
        ];
    }
}
