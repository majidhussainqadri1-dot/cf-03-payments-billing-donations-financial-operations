<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationServiceState;

final class WordPressRuntimeConfiguration
{
    public const OPTION_MODE='sabri_cf03_runtime_mode';
    public const OPTION_PROVIDER='sabri_cf03_provider_id';
    public const OPTION_GATES='sabri_cf03_runtime_gates';
    public const OPTION_WEBHOOK='sabri_cf03_webhook_enabled';
    public const OPTION_DOWNLOAD='sabri_cf03_download_delivery_enabled';

    public static function load(): RuntimeConfiguration
    {
        if (!function_exists('get_option')) { return RuntimeConfiguration::preparing(); }
        $mode = DonationServiceState::tryFrom((string)get_option(self::OPTION_MODE, 'preparing')) ?? DonationServiceState::PREPARING;
        $provider = (string)get_option(self::OPTION_PROVIDER, 'provider.unconfigured');
        $rawGates = get_option(self::OPTION_GATES, []); $gates = [];
        if (is_array($rawGates)) {
            foreach ($rawGates as $name => $value) { if (is_string($name)) { $gates[$name] = $value === true || $value === 1 || $value === '1'; } }
        }
        $activation = EvidenceBoundActivationGate::forWordPress()->evaluate();
        $gates['founder_change_control'] = ($gates['founder_change_control'] ?? false) && $activation->approved();
        return new RuntimeConfiguration(
            $mode,
            $provider,
            $gates,
            self::truthy(get_option(self::OPTION_WEBHOOK, false)),
            self::truthy(get_option(self::OPTION_DOWNLOAD, false))
        );
    }

    private static function truthy(mixed $value): bool { return $value === true || $value === 1 || $value === '1'; }
}
