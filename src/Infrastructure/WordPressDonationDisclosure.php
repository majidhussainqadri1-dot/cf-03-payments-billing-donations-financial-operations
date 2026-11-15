<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Domain\PlatformFinancialPolicy;

/**
 * CF03-FR-003: fail closed unless the currently approved provider's public
 * donation terms are complete. This only validates disclosure metadata:
 * it does not approve a provider, taxes or live financial collection.
 */
final class WordPressDonationDisclosure
{
    public const OPTION = 'sabri_cf03_approved_donation_disclosure';

    /** @return null|array<string,string> */
    public static function forProvider(string $providerCode): ?array
    {
        if ($providerCode === 'provider.unconfigured' || !function_exists('get_option')) {
            return null;
        }
        $record = get_option(self::OPTION, []);
        if (!is_array($record)
            || ($record['approved'] ?? null) !== true
            || ($record['provider_code'] ?? null) !== $providerCode
            || ($record['policy_version'] ?? null) !== PlatformFinancialPolicy::DECISION_ID
            || ($record['currency'] ?? null) !== 'USD'
        ) {
            return null;
        }
        $required = [
            'approval_ref', 'provider_name', 'donation_purpose', 'provider_fee_terms',
            'tax_terms', 'refund_terms', 'support_terms', 'data_use_terms',
        ];
        $public = [];
        foreach ($required as $name) {
            $value = $record[$name] ?? null;
            if (!is_string($value) || ($value = trim($value)) === ''
                || strlen($value) > 800
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            ) {
                return null;
            }
            $public[$name] = $value;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $public['approval_ref']) !== 1) {
            return null;
        }
        return $public;
    }
}
