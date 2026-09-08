<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Persistence\CompleteSchema;
use Throwable;

final class WordPressRuntimeConfiguration
{
    public const OPTION_MODE = 'sabri_cf03_runtime_mode';
    public const OPTION_PROVIDER = 'sabri_cf03_provider_id';
    public const OPTION_GATES = 'sabri_cf03_runtime_gates';
    public const OPTION_WEBHOOK = 'sabri_cf03_webhook_enabled';
    public const OPTION_DOWNLOAD = 'sabri_cf03_download_delivery_enabled';

    public static function load(): RuntimeConfiguration
    {
        if (!function_exists('get_option')) {
            return RuntimeConfiguration::preparing();
        }

        $installedSchema = (string)get_option('sabri_cf03_schema_version', '');
        if (!hash_equals(CompleteSchema::VERSION, $installedSchema)) {
            return RuntimeConfiguration::preparing();
        }

        $mode = DonationServiceState::tryFrom((string)get_option(self::OPTION_MODE, 'preparing'));
        $provider = (string)get_option(self::OPTION_PROVIDER, 'provider.unconfigured');
        $rawGates = get_option(self::OPTION_GATES, []);
        if ($mode === null || !is_array($rawGates)) {
            return RuntimeConfiguration::preparing();
        }

        $gates = [];
        foreach ($rawGates as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) !== 1) {
                return RuntimeConfiguration::preparing();
            }
            $normalized = self::strictBoolean($value);
            if ($normalized === null) {
                return RuntimeConfiguration::preparing();
            }
            $gates[$name] = $normalized;
        }

        try {
            $activation = EvidenceBoundActivationGate::forWordPress()->evaluate();
            $gates['founder_change_control'] = ($gates['founder_change_control'] ?? false)
                && $activation->approved();

            return new RuntimeConfiguration(
                $mode,
                $provider,
                $gates,
                self::strictBoolean(get_option(self::OPTION_WEBHOOK, false)) === true,
                self::strictBoolean(get_option(self::OPTION_DOWNLOAD, false)) === true
            );
        } catch (Throwable) {
            return RuntimeConfiguration::preparing();
        }
    }

    private static function strictBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }
}
