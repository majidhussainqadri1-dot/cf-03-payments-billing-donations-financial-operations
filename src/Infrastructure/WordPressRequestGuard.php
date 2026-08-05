<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

final class WordPressRequestGuard
{
    private const MAX_MUTATION_BYTES = 65536;
    private const MAX_WEBHOOK_BYTES = 1048576;

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
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $result;
        }
        $maximum = str_contains($route, '/webhooks/')
            ? self::MAX_WEBHOOK_BYTES
            : self::MAX_MUTATION_BYTES;
        if (strlen((string)$request->get_body()) <= $maximum) {
            return $result;
        }
        if (class_exists('WP_Error')) {
            return new \WP_Error(
                'sabri_cf03_request_too_large',
                'The financial request exceeds its accepted size limit.',
                ['status' => 413]
            );
        }
        return [
            'code' => 'sabri_cf03_request_too_large',
            'message' => 'The financial request exceeds its accepted size limit.',
            'status' => 413,
        ];
    }
}
