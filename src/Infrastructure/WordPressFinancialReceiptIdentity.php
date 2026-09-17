<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Domain\FinancialReceiptIdentity;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressFinancialReceiptIdentity
{
    public const OPTION_SELLER_LEGAL_NAME = 'sabri_cf03_seller_legal_name';
    public const OPTION_SELLER_COUNTRY = 'sabri_cf03_seller_country';

    public static function isConfigured(): bool
    {
        if (!function_exists('get_option')) { return false; }
        try {
            self::load();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function load(): FinancialReceiptIdentity
    {
        if (!function_exists('get_option')) {
            throw new InvariantViolation('Financial receipt legal identity is unavailable.');
        }
        $name = get_option(self::OPTION_SELLER_LEGAL_NAME, '');
        $country = get_option(self::OPTION_SELLER_COUNTRY, '');
        if (!is_string($name) || !is_string($country)) {
            throw new InvariantViolation('Financial receipt legal identity is malformed.');
        }
        try {
            return new FinancialReceiptIdentity(trim($name), strtoupper(trim($country)));
        } catch (Throwable $error) {
            throw new InvariantViolation('Financial receipt legal identity is incomplete or invalid.', 0, $error);
        }
    }
}
