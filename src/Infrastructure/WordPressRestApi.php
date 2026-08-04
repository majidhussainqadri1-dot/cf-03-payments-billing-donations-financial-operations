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
        if (is_object($request) && method_exists($request, 'get_param')) {
            $monthly = filter_var($request->get_param('monthly'), FILTER_VALIDATE_BOOL);
            $amountMinor = $request->get_param('amount_minor');
        }
        if ($amountMinor !== null && (! is_numeric($amountMinor) || (int) $amountMinor <= 0)) {
            return self::error('sabri_cf03_invalid_donation_amount', 'Donation amount must be a positive USD amount.', 422);
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
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    private static function error(string $code, string $message, int $status): mixed
    {
        if (class_exists('WP_Error')) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }
}
