<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\FinancialDocumentService;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressFinancialDocumentApi
{
    public const NAMESPACE = 'sabri-finance/v1';

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        register_rest_route(self::NAMESPACE, '/billing/invoices/(?P<id>[A-Za-z0-9._:-]+)/download', [
            'methods' => 'GET',
            'callback' => [self::class, 'invoice'],
            'permission_callback' => [WordPressRestApi::class, 'authenticated'],
        ]);
        register_rest_route(self::NAMESPACE, '/billing/receipts/(?P<id>[A-Za-z0-9._:-]+)/download', [
            'methods' => 'GET',
            'callback' => [self::class, 'receipt'],
            'permission_callback' => [WordPressRestApi::class, 'authenticated'],
        ]);
        register_rest_route(self::NAMESPACE, '/transparency/(?P<id>[A-Za-z0-9._:-]+)/download', [
            'methods' => 'GET',
            'callback' => [self::class, 'transparency'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function invoice(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $grant = self::service()->grantInvoice(
                (string)self::param($request, 'id'),
                self::actor(),
                self::financeOverride(),
                new DateTimeImmutable('now'),
                self::expiry($request)
            );
            return $grant->toPresentationContract();
        });
    }

    public static function receipt(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $grant = self::service()->grantReceipt(
                (string)self::param($request, 'id'),
                self::actor(),
                self::financeOverride(),
                new DateTimeImmutable('now'),
                self::expiry($request)
            );
            return $grant->toPresentationContract();
        });
    }

    public static function transparency(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $grant = self::service()->grantTransparencySnapshot(
                (string)self::param($request, 'id'),
                new DateTimeImmutable('now'),
                self::expiry($request)
            );
            return $grant->toPresentationContract();
        });
    }

    private static function service(): FinancialDocumentService
    {
        $repository = WordPressFinancialRepository::fromWordPress();
        return new FinancialDocumentService(
            $repository,
            WordPressSecureArtifactStoreFactory::make(),
            WordPressRuntimeConfiguration::load(),
            new FinancialAuditService($repository)
        );
    }

    private static function expiry(mixed $request): DateTimeImmutable
    {
        $value = self::param($request, 'expires_at');
        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : new DateTimeImmutable('+15 minutes');
    }

    private static function actor(): string
    {
        $id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
        if ($id < 1) {
            throw new InvariantViolation('Authenticated document owner identity is unavailable.');
        }
        return 'user:'.$id;
    }

    private static function financeOverride(): bool
    {
        return function_exists('current_user_can') && current_user_can('sabri_manage_finance');
    }

    private static function param(mixed $request, string $name): mixed
    {
        return is_object($request) && method_exists($request, 'get_param')
            ? $request->get_param($name)
            : null;
    }

    private static function handle(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException
                ? 422
                : ($error instanceof InvariantViolation ? 409 : 500);
            $message = $status === 500
                ? 'The financial document could not be prepared safely.'
                : $error->getMessage();
            if (class_exists('WP_Error')) {
                return new \WP_Error('sabri_cf03_document_error', $message, ['status' => $status]);
            }
            return ['code' => 'sabri_cf03_document_error', 'message' => $message, 'status' => $status];
        }
    }
}
