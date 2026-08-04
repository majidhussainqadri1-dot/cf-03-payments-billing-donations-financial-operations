<?php

declare(strict_types=1);

namespace Sabri\CF03;

use Sabri\CF03\Application\ActivationGate;

final class Plugin
{
    public const OPTION_VERSION = 'sabri_cf03_version';
    public const OPTION_SCHEMA_VERSION = 'sabri_cf03_schema_version';
    public const OPTION_RUNTIME_STATUS = 'sabri_cf03_runtime_status';
    public const OPTION_ACTIVATION_RECORD = 'sabri_cf03_activation_record';

    public static function activate(): void
    {
        if (! function_exists('add_option') || ! function_exists('update_option')) { return; }
        add_option(self::OPTION_RUNTIME_STATUS, 'disabled', '', false);
        add_option(self::OPTION_ACTIVATION_RECORD, [], '', false);
        update_option(self::OPTION_VERSION, defined('SABRI_CF03_VERSION') ? SABRI_CF03_VERSION : '1.0.0-rc.1', false);
        update_option(self::OPTION_SCHEMA_VERSION, defined('SABRI_CF03_SCHEMA_VERSION') ? SABRI_CF03_SCHEMA_VERSION : '1.0.0', false);
    }

    public static function boot(): void
    {
        if (function_exists('add_action')) { add_action('admin_notices', [self::class, 'renderConditionalNotice']); }
        if (function_exists('add_filter')) { add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']); }
    }

    public static function renderConditionalNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) { return; }
        $status = ActivationGate::forWordPress()->evaluate();
        $message = $status->approved()
            ? 'CF-03 evidence gate is approved, but no live provider adapter or payment route is enabled in this source candidate.'
            : 'CF-03 financial runtime is safely disabled. Missing activation gates: ' . implode(', ', $status->missingGates()) . '.';
        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }

    /** @param array<string,mixed> $tests @return array<string,mixed> */
    public static function registerSiteHealthTest(array $tests): array
    {
        $tests['direct']['sabri_cf03_activation_gate'] = ['label'=>'CF-03 activation gate','test'=>[self::class,'runSiteHealthTest']];
        return $tests;
    }

    /** @return array<string,mixed> */
    public static function runSiteHealthTest(): array
    {
        $status = ActivationGate::forWordPress()->evaluate();
        return [
            'label' => $status->approved() ? 'CF-03 evidence gates approved; runtime still intentionally disabled' : 'CF-03 runtime remains safely disabled',
            'status' => 'recommended',
            'badge' => ['label'=>'Sabri CF-03','color'=>'blue'],
            'description' => '<p>' . esc_html($status->approved() ? 'Source candidate is present. Provider, legal, PCI, staging and operational gates must still be evidenced before enabling any route.' : 'Expected conditional state. Missing gates: '.implode(', ',$status->missingGates()).'.') . '</p>',
            'actions' => '',
            'test' => 'sabri_cf03_activation_gate',
        ];
    }
}
