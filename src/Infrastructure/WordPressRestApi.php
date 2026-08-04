<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Application\RouteCatalogue;
use Sabri\CF03\Domain\PlatformFinancialPolicy;

final class WordPressRestApi
{
    public const NAMESPACE = 'sabri-finance/v1';

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/policy', [
            'methods' => 'GET',
            'callback' => [self::class, 'policy'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/donation-appeal', [
            'methods' => 'GET',
            'callback' => [self::class, 'donationAppeal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/checkout/(?P<product>[A-Za-z0-9._:-]+)', [
            'methods' => 'POST',
            'callback' => [self::class, 'checkoutUnavailable'],
            'permission_callback' => [self::class, 'authenticated'],
        ]);
        register_rest_route(self::NAMESPACE, '/donation-intents', [
            'methods' => 'POST',
            'callback' => [self::class, 'donationPreparing'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/admin/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'adminHealth'],
            'permission_callback' => [self::class, 'manageFinance'],
        ]);
    }

    /** @return array<string,mixed> */
    public static function policy(): array
    {
        $policy = new PlatformFinancialPolicy();
        return [
            'decision_id' => PlatformFinancialPolicy::DECISION_ID,
            'mode' => PlatformFinancialPolicy::MODE,
            'all_core_services_free' => true,
            'paid_services_suspended' => $policy->paidServicesSuspended(),
            'platform_commission_basis_points' => $policy->platformCommissionBasisPoints(),
            'donation_only' => true,
            'live_collection_enabled' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function donationAppeal(): array
    {
        return DonationAppealCopy::contract();
    }

    public static function checkoutUnavailable(mixed $request = null): mixed
    {
        return self::error(
            'sabri_cf03_paid_checkout_suspended',
            'Paid membership, education, AI and platform-service checkout is suspended under the current Founder decision.',
            409
        );
    }

    public static function donationPreparing(mixed $request = null): mixed
    {
        $monthly = false;
        $amountMinor = null;
        $currency = 'USD';
        if (is_object($request) && method_exists($request, 'get_param')) {
            $rawMonthly = $request->get_param('monthly');
            $parsedMonthly = self::strictBoolean($rawMonthly);
            if ($parsedMonthly === null && $rawMonthly !== null) {
                return self::error('sabri_cf03_invalid_monthly_consent', 'Monthly donation consent must be an explicit boolean value.', 422);
            }
            $monthly = $parsedMonthly ?? false;
            $amountMinor = $request->get_param('amount_minor');
            $rawCurrency = $request->get_param('currency');
            if ($rawCurrency !== null) {
                $currency = $rawCurrency;
            }
        }

        if (! is_string($currency) || $currency !== 'USD') {
            return self::error('sabri_cf03_invalid_donation_currency', 'Donation currency must be USD.', 422);
        }
        if ($amountMinor !== null && self::positiveIntegerOrNull($amountMinor) === null) {
            return self::error('sabri_cf03_invalid_donation_amount', 'Donation amount must be a positive integer number of USD minor units.', 422);
        }

        return self::error(
            'sabri_cf03_donation_preparing',
            $monthly
                ? 'Monthly donation service is being prepared and no recurring mandate has been created.'
                : 'Donation service is being prepared and no financial collection has occurred.',
            503
        );
    }

    /** @return array<string,mixed> */
    public static function adminHealth(): array
    {
        return [
            'policy' => self::policy(),
            'routes' => RouteCatalogue::definitions(),
            'runtime' => 'fail_closed',
            'provider' => 'not_selected',
            'webhook' => 'not_registered',
            'staging_acceptance' => false,
            'live_collection' => false,
        ];
    }

    public static function authenticated(): bool
    {
        return function_exists('is_user_logged_in') && is_user_logged_in();
    }

    public static function manageFinance(): bool
    {
        return function_exists('current_user_can') && current_user_can('sabri_manage_finance');
    }

    private static function error(string $code, string $message, int $status): mixed
    {
        if (class_exists('WP_Error')) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }

    private static function strictBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }

    private static function positiveIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value)
            || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1
        ) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($validated) ? $validated : null;
    }
}
