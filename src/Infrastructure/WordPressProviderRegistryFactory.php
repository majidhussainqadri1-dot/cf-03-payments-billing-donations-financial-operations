<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\DonationProviderRegistry;
use Sabri\CF03\Application\ProviderRegistry;

final class WordPressProviderRegistryFactory
{
    public static function payments(): ProviderRegistry
    {
        $providers = function_exists('apply_filters') ? apply_filters('sabri_cf03_payment_providers', []) : [];
        return new ProviderRegistry(is_array($providers) ? array_values($providers) : []);
    }

    public static function donations(): DonationProviderRegistry
    {
        $providers = function_exists('apply_filters') ? apply_filters('sabri_cf03_donation_payment_providers', []) : [];
        return new DonationProviderRegistry(is_array($providers) ? array_values($providers) : []);
    }
}
