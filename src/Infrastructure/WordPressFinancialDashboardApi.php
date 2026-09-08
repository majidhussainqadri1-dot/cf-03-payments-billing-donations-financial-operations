<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\FinancialOperationsDashboard;
use Sabri\CF03\Application\SystemIntegrityService;
use Throwable;

final class WordPressFinancialDashboardApi
{
    public const NAMESPACE = 'sabri-finance/v1';

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        register_rest_route(self::NAMESPACE, '/admin/dashboard', [
            'methods' => 'GET',
            'callback' => [self::class, 'dashboard'],
            'permission_callback' => static fn (): bool => function_exists('current_user_can')
                && current_user_can('sabri_view_finance_audit'),
        ]);
    }

    public static function dashboard(mixed $request = null): mixed
    {
        try {
            $repository = WordPressFinancialRepository::fromWordPress();
            $audit = new FinancialAuditService($repository);
            return (new FinancialOperationsDashboard(
                $repository,
                WordPressProviderRegistryFactory::payments(),
                new SystemIntegrityService($repository, $audit)
            ))->snapshot(new DateTimeImmutable('now'));
        } catch (Throwable $error) {
            $message = 'The financial operations dashboard failed safely.';
            if (class_exists('WP_Error')) {
                return new \WP_Error('sabri_cf03_dashboard_error', $message, ['status' => 500]);
            }
            return ['code' => 'sabri_cf03_dashboard_error', 'message' => $message, 'status' => 500];
        }
    }
}
