<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

final class WordPressRequestGuard
{
    private const MAX_MUTATION_BYTES = 65536;
    private const MAX_WEBHOOK_BYTES = 1048576;

    /**
     * Defense-in-depth for privileged routes that historically had plain capability
     * callbacks. High-risk finance actions additionally require File 02 recent-auth
     * assurance and the toxic-capability separation enforced by the sensitive guard.
     *
     * @var list<array{method:string,pattern:string,capability:string,purpose:string}>
     */
    private const SENSITIVE_ROUTES = [
        [
            'method' => 'POST',
            'pattern' => '#^/sabri-finance/v1/admin/refunds/[A-Za-z0-9._:-]+$#',
            'capability' => 'sabri_review_refunds',
            'purpose' => 'refund_review',
        ],
        [
            'method' => 'POST',
            'pattern' => '#^/sabri-finance/v1/admin/refunds/[A-Za-z0-9._:-]+/execute$#',
            'capability' => 'sabri_execute_refunds',
            'purpose' => 'refund_execute',
        ],
        [
            'method' => 'GET',
            'pattern' => '#^/sabri-finance/v1/admin/health$#',
            'capability' => 'sabri_manage_finance',
            'purpose' => 'finance_health_read',
        ],
        [
            'method' => 'GET',
            'pattern' => '#^/sabri-finance/v1/admin/dashboard$#',
            'capability' => 'sabri_view_finance_audit',
            'purpose' => 'finance_dashboard_read',
        ],
        [
            'method' => 'GET',
            'pattern' => '#^/sabri-finance/v1/admin/incidents/status$#',
            'capability' => 'sabri_view_finance_audit',
            'purpose' => 'incident_status_read',
        ],
        [
            'method' => 'GET',
            'pattern' => '#^/sabri-finance/v1/admin/integrity$#',
            'capability' => 'sabri_view_finance_audit',
            'purpose' => 'finance_integrity_read',
        ],
    ];

    public static function guard(mixed $result, mixed $server = null, mixed $request = null): mixed
    {
        if (!is_object($request)
            || !method_exists($request, 'get_route')
            || !method_exists($request, 'get_method')
            || !method_exists($request, 'get_body')
        ) {
            return $result;
        }
        $route = (string)$request->get_route();
        if (!str_starts_with($route, '/'.WordPressRestApi::NAMESPACE.'/')) {
            return $result;
        }
        $method = strtoupper((string)$request->get_method());

        $sensitive = self::sensitiveRequirement($route, $method);
        if ($sensitive !== null
            && !WordPressSensitiveActionGuard::can($sensitive['capability'], $sensitive['purpose'])
        ) {
            return self::error(
                'sabri_cf03_recent_auth_required',
                'This privileged financial action requires an independently authorized role and recent authentication.',
                403
            );
        }

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $result;
        }
        $maximum = str_contains($route, '/webhooks/')
            ? self::MAX_WEBHOOK_BYTES
            : self::MAX_MUTATION_BYTES;
        if (strlen((string)$request->get_body()) <= $maximum) {
            return $result;
        }
        return self::error(
            'sabri_cf03_request_too_large',
            'The financial request exceeds its accepted size limit.',
            413
        );
    }

    /** @return array{capability:string,purpose:string}|null */
    private static function sensitiveRequirement(string $route, string $method): ?array
    {
        foreach (self::SENSITIVE_ROUTES as $rule) {
            if ($rule['method'] === $method && preg_match($rule['pattern'], $route) === 1) {
                return [
                    'capability' => $rule['capability'],
                    'purpose' => $rule['purpose'],
                ];
            }
        }
        return null;
    }

    private static function error(string $code, string $message, int $status): mixed
    {
        if (class_exists('WP_Error')) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }
        return [
            'code' => $code,
            'message' => $message,
            'status' => $status,
        ];
    }
}
