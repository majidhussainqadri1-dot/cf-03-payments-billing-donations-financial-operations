<?php

declare(strict_types=1);

namespace Sabri\CF03;

use RuntimeException;
use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\WordPressDailyReconciliation;
use Sabri\CF03\Infrastructure\WordPressFinanceAdminApi;
use Sabri\CF03\Infrastructure\WordPressFinancialDashboardApi;
use Sabri\CF03\Infrastructure\WordPressFinancialDocumentApi;
use Sabri\CF03\Infrastructure\WordPressIncidentStateStore;
use Sabri\CF03\Infrastructure\WordPressPrivacy;
use Sabri\CF03\Infrastructure\WordPressProviderRegistryFactory;
use Sabri\CF03\Infrastructure\WordPressPublicUi;
use Sabri\CF03\Infrastructure\WordPressRequestGuard;
use Sabri\CF03\Infrastructure\WordPressRestApi;
use Sabri\CF03\Infrastructure\WordPressRuntimeConfiguration;
use Sabri\CF03\Infrastructure\WordPressScheduler;
use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\CompleteSchema;
use Throwable;

final class Plugin
{
    public const OPTION_VERSION = 'sabri_cf03_version';
    public const OPTION_SCHEMA_VERSION = 'sabri_cf03_schema_version';
    public const OPTION_RUNTIME_STATUS = 'sabri_cf03_runtime_status';
    public const OPTION_ACTIVATION_RECORD = 'sabri_cf03_activation_record';
    public const OPTION_FINANCIAL_POLICY_DECISION = 'sabri_cf03_financial_policy_decision';
    public const OPTION_LAST_MIGRATION = 'sabri_cf03_last_migration';
    public const OPTION_UPGRADE_LOCK = 'sabri_cf03_upgrade_lock';

    private const RUNTIME_STATUS = 'source_candidate_runtime_fail_closed_founder_donation_transparency_ready_paid_services_suspended';
    private const DONATION_PROMPT_META = [
        'last_donation_prompt_at',
        'next_donation_prompt_at',
        'donation_prompt_status',
        'donation_prompt_snoozed_until',
        'last_donation_completed_at',
        'recurring_donation_status',
    ];
    private const FINANCE_CAPABILITIES = [
        'sabri_manage_finance','sabri_review_refunds','sabri_execute_refunds',
        'sabri_import_settlements','sabri_reconcile_finance','sabri_close_finance',
        'sabri_record_expenses','sabri_publish_financial_transparency',
        'sabri_manage_finance_exports','sabri_manage_finance_adjustments',
        'sabri_manage_finance_risk','sabri_manage_finance_retention',
        'sabri_manage_finance_incidents','sabri_view_finance_audit',
    ];

    public static function activate(): void
    {
        if (!function_exists('add_option') || !function_exists('update_option')) {
            throw new RuntimeException('WordPress option APIs are unavailable during CF-03 activation.');
        }
        add_option(self::OPTION_ACTIVATION_RECORD, [], '', false);
        update_option(self::OPTION_RUNTIME_STATUS, 'schema_installing_fail_closed', false);
        update_option(self::OPTION_FINANCIAL_POLICY_DECISION, PlatformFinancialPolicy::DECISION_ID, false);
        add_option(WordPressRuntimeConfiguration::OPTION_MODE, 'preparing', '', false);
        add_option(WordPressRuntimeConfiguration::OPTION_PROVIDER, 'provider.unconfigured', '', false);
        add_option(WordPressRuntimeConfiguration::OPTION_GATES, [], '', false);
        add_option(WordPressRuntimeConfiguration::OPTION_WEBHOOK, false, '', false);
        add_option(WordPressRuntimeConfiguration::OPTION_DOWNLOAD, false, '', false);
        add_option(WordPressIncidentStateStore::OPTION, WordPressIncidentStateStore::normal(), '', false);

        self::installAndRecordSchema();
        self::grantAdministratorCapabilities();
        WordPressScheduler::schedule();
        WordPressDailyReconciliation::schedule();
    }

    public static function deactivate(): void
    {
        WordPressScheduler::unschedule();
        WordPressDailyReconciliation::unschedule();
    }

    public static function boot(): void
    {
        if (function_exists('add_action')) {
            add_action('init', [self::class, 'maybeUpgrade'], 1);
            add_action('init', [self::class, 'registerDonationPromptMeta']);
            add_action('init', [WordPressPublicUi::class, 'register']);
            add_action('rest_api_init', [WordPressRestApi::class, 'register']);
            add_action('rest_api_init', [WordPressFinanceAdminApi::class, 'register']);
            add_action('rest_api_init', [WordPressFinancialDocumentApi::class, 'register']);
            add_action('rest_api_init', [WordPressFinancialDashboardApi::class, 'register']);
            add_action('admin_notices', [self::class, 'renderConditionalNotice']);
            add_action(WordPressScheduler::HOOK, [WordPressScheduler::class, 'run']);
            add_action(WordPressDailyReconciliation::HOOK, [WordPressDailyReconciliation::class, 'run']);
        }
        if (function_exists('add_filter')) {
            add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']);
            add_filter('cron_schedules', [WordPressScheduler::class, 'schedules']);
            add_filter('wp_privacy_personal_data_exporters', [WordPressPrivacy::class, 'exporters']);
            add_filter('wp_privacy_personal_data_erasers', [WordPressPrivacy::class, 'erasers']);
            add_filter('rest_pre_dispatch', [WordPressRequestGuard::class, 'guard'], 10, 3);
        }
        WordPressScheduler::schedule();
        WordPressDailyReconciliation::schedule();
    }

    public static function maybeUpgrade(): void
    {
        foreach (['get_option','add_option','update_option','delete_option'] as $function) {
            if (!function_exists($function)) {
                return;
            }
        }
        $currentVersion = (string)get_option(self::OPTION_VERSION, '');
        $currentSchema = (string)get_option(self::OPTION_SCHEMA_VERSION, '');
        $targetVersion = defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.2.0-rc.3';
        if (hash_equals($targetVersion, $currentVersion)
            && hash_equals(CompleteSchema::VERSION, $currentSchema)
        ) {
            return;
        }

        $now = time();
        $existingLock = get_option(self::OPTION_UPGRADE_LOCK, null);
        if (is_numeric($existingLock) && $now - (int)$existingLock < 300) {
            return;
        }
        if ($existingLock !== null && $existingLock !== false) {
            delete_option(self::OPTION_UPGRADE_LOCK);
        }
        if (!add_option(self::OPTION_UPGRADE_LOCK, $now, '', false)) {
            return;
        }

        try {
            update_option(self::OPTION_RUNTIME_STATUS, 'schema_upgrade_in_progress_fail_closed', false);
            update_option(WordPressRuntimeConfiguration::OPTION_MODE, 'preparing', false);
            update_option(WordPressRuntimeConfiguration::OPTION_WEBHOOK, false, false);
            update_option(WordPressRuntimeConfiguration::OPTION_DOWNLOAD, false, false);
            self::installAndRecordSchema();
            self::grantAdministratorCapabilities();
        } catch (Throwable) {
            update_option(self::OPTION_RUNTIME_STATUS, 'schema_upgrade_failed_fail_closed', false);
            update_option(WordPressRuntimeConfiguration::OPTION_MODE, 'preparing', false);
            update_option(WordPressRuntimeConfiguration::OPTION_WEBHOOK, false, false);
            update_option(WordPressRuntimeConfiguration::OPTION_DOWNLOAD, false, false);
        } finally {
            delete_option(self::OPTION_UPGRADE_LOCK);
        }
    }

    public static function registerDonationPromptMeta(): void
    {
        if (!function_exists('register_meta')) {
            return;
        }
        foreach (self::DONATION_PROMPT_META as $key) {
            register_meta('user', $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'default' => '',
                'auth_callback' => static function (bool $allowed, string $metaKey, int $objectId): bool {
                    if (!function_exists('get_current_user_id') || !function_exists('current_user_can')) {
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
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        $status = EvidenceBoundActivationGate::forWordPress()->evaluate();
        $runtime = WordPressRuntimeConfiguration::load();
        $message = 'CF-03 policy '.PlatformFinancialPolicy::DECISION_ID.
            ': founder-owned, not a Trust; fixed fees prohibited; commission 0%; donations voluntary; aggregate transparency required. Runtime state: '.
            $runtime->state()->value.'. Schema '.CompleteSchema::VERSION.'. '.
            ($status->approved()
                ? 'Activation evidence exists; provider, webhook, staging and every runtime gate must still pass.'
                : 'Missing activation gates: '.implode(', ', $status->missingGates()).'.');
        echo '<div class="notice notice-info"><p>'.esc_html($message).'</p></div>';
    }

    /** @param array<string,mixed> $tests @return array<string,mixed> */
    public static function registerSiteHealthTest(array $tests): array
    {
        $tests['direct']['sabri_cf03_activation_gate'] = [
            'label' => 'CF-03 financial runtime gates',
            'test' => [self::class, 'runSiteHealthTest'],
        ];
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function runSiteHealthTest(): array
    {
        $runtime = WordPressRuntimeConfiguration::load();
        $missing = $runtime->missingDonationCollectionGates();
        $incident = (new WordPressIncidentStateStore())->get();
        $donations = WordPressProviderRegistryFactory::donations()->registeredProviderCodes();
        $paymentHealth = WordPressProviderRegistryFactory::payments()->health();
        $provider = $runtime->providerCode();
        $providerReady = in_array($provider, $donations, true)
            && ($paymentHealth[$provider] ?? null) === 'healthy';
        $pathsReady = ($incident['checkout_enabled'] ?? false) === true
            && ($incident['webhooks_enabled'] ?? false) === true;
        $schemaReady = function_exists('get_option')
            && hash_equals(CompleteSchema::VERSION, (string)get_option(self::OPTION_SCHEMA_VERSION, ''));
        $ready = $missing === [] && $providerReady && $pathsReady && $schemaReady;

        return [
            'label' => $ready ? 'CF-03 donation collection gates are complete' : 'CF-03 remains fail closed',
            'status' => $ready ? 'good' : 'recommended',
            'badge' => ['label' => 'Sabri CF-03', 'color' => 'blue'],
            'description' => '<p>'.esc_html(
                'Policy '.PlatformFinancialPolicy::DECISION_ID.'; schema '.CompleteSchema::VERSION.
                '; runtime '.$runtime->state()->value.'; missing gates: '.($missing === [] ? 'none' : implode(', ', $missing)).
                '; provider adapter: '.($providerReady ? 'ready' : 'not ready').
                '; incident paths: '.($pathsReady ? 'ready' : 'blocked').'.'
            ).'</p>',
            'actions' => '',
            'test' => 'sabri_cf03_activation_gate',
        ];
    }

    private static function installAndRecordSchema(): void
    {
        $migrations = WordPressSchemaInstaller::install();
        $expectedCount = count(CompleteSchema::tables(''));
        if (count($migrations) !== $expectedCount) {
            update_option(self::OPTION_RUNTIME_STATUS, 'schema_incomplete_fail_closed', false);
            throw new RuntimeException('CF-03 schema installation did not verify every canonical schema migration.');
        }
        $version = defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.2.0-rc.3';
        update_option(self::OPTION_VERSION, $version, false);
        update_option(self::OPTION_SCHEMA_VERSION, CompleteSchema::VERSION, false);
        update_option(self::OPTION_LAST_MIGRATION, [
            'schema_version' => CompleteSchema::VERSION,
            'base_schema_version' => CompleteSchema::BASE_VERSION,
            'migration_ids' => $migrations,
            'completed_at' => gmdate(DATE_ATOM),
        ], false);
        update_option(self::OPTION_RUNTIME_STATUS, self::RUNTIME_STATUS, false);
    }

    private static function grantAdministratorCapabilities(): void
    {
        if (!function_exists('get_role')) {
            return;
        }
        $administrator = get_role('administrator');
        if (!is_object($administrator) || !method_exists($administrator, 'add_cap')) {
            return;
        }
        foreach (self::FINANCE_CAPABILITIES as $capability) {
            $administrator->add_cap($capability);
        }
    }
}
