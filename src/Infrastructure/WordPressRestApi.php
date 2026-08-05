<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Application\BillingQueryService;
use Sabri\CF03\Application\DonationAppealCopy;
use Sabri\CF03\Application\DonationCheckoutService;
use Sabri\CF03\Application\DonationIntentDraft;
use Sabri\CF03\Application\DonationManagementService;
use Sabri\CF03\Application\FinancialDownloadContract;
use Sabri\CF03\Application\IncidentPathGuard;
use Sabri\CF03\Application\RefundWorkflowService;
use Sabri\CF03\Application\RouteCatalogue;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\WebhookIngestionService;
use Sabri\CF03\Domain\DonationNeutralityPolicy;
use Sabri\CF03\Domain\FounderOwnershipPolicy;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressRestApi
{
    public const NAMESPACE = 'sabri-finance/v1';
    private const MAX_PUBLIC_JSON_BYTES = 65536;
    private const MAX_WEBHOOK_BYTES = 1048576;
    private const MAX_WEBHOOK_HEADERS = 64;
    private const MAX_WEBHOOK_HEADER_BYTES = 8192;
    private const GUEST_REFERENCE_TTL_SECONDS = 2592000;
    private const SENSITIVE_WEBHOOK_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-wp-nonce',
        'php-auth-user', 'php-auth-pw', 'x-forwarded-authorization',
    ];

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        $routes = [
            ['/policy', 'GET', 'policy', 'public'],
            ['/public-disclosure', 'GET', 'publicDisclosure', 'public'],
            ['/donation-appeal', 'GET', 'donationAppeal', 'public'],
            ['/donation-neutrality', 'GET', 'donationNeutrality', 'public'],
            ['/due-process', 'GET', 'dueProcess', 'public'],
            ['/download-contract', 'GET', 'downloadContract', 'public'],
            ['/transparency', 'GET', 'transparency', 'public'],
            ['/checkout/(?P<product>[A-Za-z0-9._:-]+)', 'POST', 'checkoutUnavailable', 'authenticated'],
            ['/donation-intents', 'POST', 'donationIntent', 'public'],
            ['/billing', 'GET', 'billing', 'authenticated'],
            ['/donation-management', ['GET', 'POST'], 'donationManagement', 'authenticated'],
            ['/refunds', 'POST', 'refundRequest', 'authenticated'],
            ['/admin/refunds/(?P<id>[A-Za-z0-9._:-]+)', 'POST', 'refundDecision', 'refund_review'],
            ['/admin/refunds/(?P<id>[A-Za-z0-9._:-]+)/execute', 'POST', 'refundExecute', 'refund_execute'],
            ['/webhooks/(?P<provider>[A-Za-z0-9._:-]+)', 'POST', 'webhook', 'public'],
            ['/admin/health', 'GET', 'adminHealth', 'finance'],
        ];

        foreach ($routes as [$route, $methods, $callback, $permission]) {
            register_rest_route(self::NAMESPACE, $route, [
                'methods' => $methods,
                'callback' => [self::class, $callback],
                'permission_callback' => match ($permission) {
                    'authenticated' => [self::class, 'authenticated'],
                    'finance' => [self::class, 'manageFinance'],
                    'refund_review' => [self::class, 'reviewRefunds'],
                    'refund_execute' => [self::class, 'executeRefunds'],
                    default => '__return_true',
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function policy(): array
    {
        $policy = new PlatformFinancialPolicy();
        $runtime = WordPressRuntimeConfiguration::load();
        $incident = (new WordPressIncidentStateStore())->get();
        $liveCollectionEnabled = $runtime->state()->value === 'live'
            && $runtime->missingDonationCollectionGates() === []
            && ($incident['checkout_enabled'] ?? false) === true
            && ($incident['webhooks_enabled'] ?? false) === true
            && self::providerReady($runtime);

        return [
            'policy_name' => PlatformFinancialPolicy::POLICY_NAME,
            'decision_id' => PlatformFinancialPolicy::DECISION_ID,
            'supersedes_decision_id' => PlatformFinancialPolicy::SUPERSEDES_DECISION_ID,
            'effective_at' => PlatformFinancialPolicy::EFFECTIVE_AT,
            'mode' => PlatformFinancialPolicy::MODE,
            'founder_owned' => $policy->founderOwned(),
            'is_trust' => $policy->isTrust(),
            'all_core_services_free' => true,
            'fixed_fees_prohibited' => $policy->fixedFeesProhibited(),
            'paid_services_suspended' => $policy->paidServicesSuspended(),
            'platform_commission_basis_points' => $policy->platformCommissionBasisPoints(),
            'donation_only' => true,
            'transparency_required' => $policy->transparencyRequired(),
            'founder_withdrawal_disclosure_required' => $policy->founderWithdrawalDisclosureRequired(),
            'approved_expense_categories' => $policy->approvedExpenseCategories(),
            'prohibited_uses' => $policy->prohibitedUses(),
            'monthly_prompt_minimum_days' => PlatformFinancialPolicy::MONTHLY_PROMPT_MINIMUM_DAYS,
            'runtime' => [
                'state' => $runtime->state()->value,
                'live_collection_enabled' => $liveCollectionEnabled,
                'operational_details_redacted' => true,
            ],
            'live_collection_enabled' => $liveCollectionEnabled,
        ];
    }

    /** @return array<string,mixed> */
    public static function publicDisclosure(): array
    {
        $ownership = (new FounderOwnershipPolicy())->toPublicDisclosure();
        $ownership['statement'] = (new PlatformFinancialPolicy())->publicDisclosure();
        $ownership['transparency_path'] = '/transparency/';
        return $ownership;
    }

    /** @return array<string,mixed> */
    public static function donationAppeal(): array
    {
        return DonationAppealCopy::contract();
    }

    /** @return array<string,mixed> */
    public static function donationNeutrality(): array
    {
        return (new DonationNeutralityPolicy())->publicContract();
    }

    /** @return array<string,mixed> */
    public static function dueProcess(): array
    {
        return (new \Sabri\CF03\Domain\FinancialDueProcessPolicy())->contract();
    }

    /** @return array<string,mixed> */
    public static function downloadContract(): array
    {
        return FinancialDownloadContract::contract();
    }

    /** @return array<string,mixed> */
    public static function transparency(): array
    {
        $snapshot = (new WordPressTransparencyRepository())->latestPublished();
        if ($snapshot === null) {
            return [
                'status' => 'not_published',
                'message' => 'No verified public financial snapshot has been published yet; no financial values are fabricated.',
                'policy' => self::policy(),
                'disclosure' => self::publicDisclosure(),
                'neutrality' => self::donationNeutrality(),
                'download_contract' => self::downloadContract(),
                'snapshot' => null,
            ];
        }

        return [
            'status' => 'published',
            'policy' => self::policy(),
            'disclosure' => self::publicDisclosure(),
            'neutrality' => self::donationNeutrality(),
            'download_contract' => self::downloadContract(),
            'snapshot' => $snapshot->toPublicProjection(),
        ];
    }

    public static function checkoutUnavailable(mixed $request = null): mixed
    {
        return self::error(
            'sabri_cf03_paid_checkout_suspended',
            'Fixed membership, education, AI and platform-service checkout is prohibited under the current Founder policy.',
            409
        );
    }

    public static function donationPreparing(mixed $request = null): mixed
    {
        try {
            self::assertBodyLimit($request, self::MAX_PUBLIC_JSON_BYTES);
            self::positiveInteger(self::param($request, 'amount_minor'));
            $currency = (string)(self::param($request, 'currency') ?? 'USD');
            if ($currency !== 'USD') {
                throw new InvalidArgumentException('Donation currency must be USD.');
            }
            self::boolean(self::param($request, 'monthly'), false);
            return self::error(
                'sabri_cf03_donation_preparing',
                'Donation collection remains fail closed until approved provider and activation evidence are configured.',
                409
            );
        } catch (Throwable $error) {
            return self::safePublicError($error);
        }
    }

    public static function donationIntent(mixed $request = null): mixed
    {
        try {
            self::assertBodyLimit($request, self::MAX_PUBLIC_JSON_BYTES);
            self::incident()->assertAvailable('checkout');
            $amount = self::positiveInteger(self::param($request, 'amount_minor'));
            $currency = (string)(self::param($request, 'currency') ?? 'USD');
            if ($currency !== 'USD') {
                throw new InvalidArgumentException('Donation currency must be USD.');
            }

            $monthly = self::boolean(self::param($request, 'monthly'), false);
            $consent = self::boolean(self::param($request, 'monthly_consent'), false);
            $key = self::idempotencyKey($request);
            $actor = self::actorReference();
            $intent = 'intent.'.substr(hash('sha256', $actor.'|'.$key), 0, 40);
            $runtime = WordPressRuntimeConfiguration::load();
            if (!self::providerReady($runtime)) {
                throw new InvariantViolation('The configured donation provider is not ready.');
            }
            $draft = new DonationIntentDraft(
                $intent,
                $actor,
                new Money($amount, $currency),
                $monthly,
                $consent,
                $runtime->state(),
                $key,
                new DateTimeImmutable('now')
            );
            $result = (new DonationCheckoutService(
                $runtime,
                WordPressProviderRegistryFactory::donations(),
                WordPressFinancialRepository::fromWordPress()
            ))->create($draft, $runtime->providerCode());
            return self::publicDonationResult($result);
        } catch (Throwable $error) {
            return self::safePublicError($error);
        }
    }

    public static function billing(mixed $request = null): mixed
    {
        try {
            return (new BillingQueryService(WordPressFinancialRepository::fromWordPress()))
                ->forActor(self::actorReference());
        } catch (Throwable $error) {
            return self::safeError($error);
        }
    }

    public static function donationManagement(mixed $request = null): mixed
    {
        try {
            $service = new DonationManagementService(
                WordPressFinancialRepository::fromWordPress(),
                WordPressProviderRegistryFactory::donations(),
                WordPressRuntimeConfiguration::load()
            );
            $actor = self::actorReference();
            if (self::method($request) === 'GET') {
                return $service->view($actor);
            }

            $action = (string)self::param($request, 'action');
            $consent = (string)self::param($request, 'consent_id');
            $key = self::idempotencyKey($request);
            $version = self::positiveInteger(self::param($request, 'expected_version'));

            if ($action === 'cancel') {
                return $service->cancel($consent, $actor, $key, $version, new DateTimeImmutable('now'));
            }
            if ($action === 'change_amount') {
                self::incident()->assertAvailable('checkout');
                return $service->changeAmount(
                    $consent,
                    $actor,
                    new Money(self::positiveInteger(self::param($request, 'amount_minor')), 'USD'),
                    $key,
                    $version,
                    new DateTimeImmutable('now')
                );
            }
            throw new InvalidArgumentException('Unknown recurring donation action.');
        } catch (Throwable $error) {
            return self::safeError($error);
        }
    }

    public static function refundRequest(mixed $request = null): mixed
    {
        try {
            self::incident()->assertAvailable('refunds');
            return (new RefundWorkflowService(
                WordPressFinancialRepository::fromWordPress(),
                WordPressProviderRegistryFactory::payments(),
                WordPressRuntimeConfiguration::load()
            ))->request(
                (string)self::param($request, 'refund_id'),
                (string)self::param($request, 'intent_id'),
                self::actorReference(),
                new Money(
                    self::positiveInteger(self::param($request, 'amount_minor')),
                    (string)(self::param($request, 'currency') ?? 'USD')
                ),
                (string)self::param($request, 'reason_code'),
                new DateTimeImmutable('now')
            );
        } catch (Throwable $error) {
            return self::safeError($error);
        }
    }

    public static function refundDecision(mixed $request = null): mixed
    {
        try {
            self::incident()->assertAvailable('refunds');
            return (new RefundWorkflowService(
                WordPressFinancialRepository::fromWordPress(),
                WordPressProviderRegistryFactory::payments(),
                WordPressRuntimeConfiguration::load()
            ))->review(
                (string)self::param($request, 'id'),
                'user:'.self::currentUserId(),
                self::boolean(self::param($request, 'approve'), false),
                (string)self::param($request, 'decision_reason'),
                self::positiveInteger(self::param($request, 'expected_version')),
                new DateTimeImmutable('now')
            );
        } catch (Throwable $error) {
            return self::safeError($error);
        }
    }

    public static function refundExecute(mixed $request = null): mixed
    {
        try {
            self::incident()->assertAvailable('refunds');
            $key = self::idempotencyKey($request);
            return (new RefundWorkflowService(
                WordPressFinancialRepository::fromWordPress(),
                WordPressProviderRegistryFactory::payments(),
                WordPressRuntimeConfiguration::load()
            ))->execute(
                (string)self::param($request, 'id'),
                'user:'.self::currentUserId(),
                self::positiveInteger(self::param($request, 'expected_version')),
                $key,
                new DateTimeImmutable('now')
            );
        } catch (Throwable $error) {
            return self::safeError($error);
        }
    }

    public static function webhook(mixed $request = null): mixed
    {
        try {
            self::incident()->assertAvailable('webhooks');
            $provider = (string)self::param($request, 'provider');
            $runtime = WordPressRuntimeConfiguration::load();
            if (!hash_equals($runtime->providerCode(), $provider) || !self::providerReady($runtime)) {
                throw new InvariantViolation('Webhook provider is not the approved ready provider.');
            }
            $body = is_object($request) && method_exists($request, 'get_body')
                ? (string)$request->get_body()
                : '';
            if ($body === '' || strlen($body) > self::MAX_WEBHOOK_BYTES) {
                throw new InvalidArgumentException('Provider webhook body is empty or exceeds the one-megabyte limit.');
            }
            $headers = self::boundedHeaders($request);
            return (new WebhookIngestionService(
                $runtime,
                WordPressProviderRegistryFactory::payments(),
                WordPressFinancialRepository::fromWordPress()
            ))->ingest($provider, $body, $headers, time());
        } catch (Throwable $error) {
            return self::safePublicError($error);
        }
    }

    /** @return array<string,mixed> */
    public static function adminHealth(): array
    {
        $runtime = WordPressRuntimeConfiguration::load();
        return [
            'policy' => self::policy(),
            'routes' => RouteCatalogue::definitions(),
            'download_contract' => self::downloadContract(),
            'runtime_diagnostics' => $runtime->toArray(),
            'incident_diagnostics' => (new WordPressIncidentStateStore())->get(),
            'providers' => WordPressProviderRegistryFactory::payments()->health(),
            'donation_provider_codes' => WordPressProviderRegistryFactory::donations()->registeredProviderCodes(),
            'transparency_snapshot' => self::transparency()['status'],
            'schema_version' => defined('SABRI_CF03_SCHEMA_VERSION')
                ? SABRI_CF03_SCHEMA_VERSION
                : 'unknown',
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

    public static function reviewRefunds(): bool
    {
        return function_exists('current_user_can') && current_user_can('sabri_review_refunds');
    }

    public static function executeRefunds(): bool
    {
        return function_exists('current_user_can') && current_user_can('sabri_execute_refunds');
    }

    private static function incident(): IncidentPathGuard
    {
        return new IncidentPathGuard(new WordPressIncidentStateStore());
    }

    private static function providerReady(RuntimeConfiguration $runtime): bool
    {
        $provider = $runtime->providerCode();
        if ($provider === 'provider.unconfigured'
            || !in_array($provider, WordPressProviderRegistryFactory::donations()->registeredProviderCodes(), true)
        ) {
            return false;
        }
        return (WordPressProviderRegistryFactory::payments()->health()[$provider] ?? null) === 'healthy';
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function publicDonationResult(array $result): array
    {
        $safe = [];
        foreach ([
            'status', 'intent_id', 'hosted_url', 'expires_at',
            'monthly', 'amount_minor', 'currency', 'reused',
        ] as $field) {
            if (array_key_exists($field, $result)) {
                $safe[$field] = $result[$field];
            }
        }
        if (!isset($safe['hosted_url']) || !is_string($safe['hosted_url'])) {
            throw new InvariantViolation('Hosted donation checkout did not return a safe public continuation URL.');
        }
        return $safe;
    }

    private static function idempotencyKey(mixed $request): string
    {
        $header = self::header($request, 'Idempotency-Key');
        $body = self::param($request, 'idempotency_key');
        if ($body !== null && !is_string($body)) {
            throw new InvalidArgumentException('Idempotency key must be a string.');
        }
        $body = is_string($body) && $body !== '' ? $body : null;
        if ($header !== null && $body !== null && !hash_equals($header, $body)) {
            throw new InvalidArgumentException('Idempotency header and request body do not match.');
        }
        return $header ?? $body ?? '';
    }

    private static function actorReference(): string
    {
        if (self::currentUserId() > 0) {
            return 'user:'.self::currentUserId();
        }

        $cookie = $_COOKIE['sabri_cf03_guest_ref'] ?? '';
        if (is_string($cookie) && preg_match('/^guest:[a-f0-9]{40}$/', $cookie) === 1) {
            return $cookie;
        }

        try {
            $actor = 'guest:'.bin2hex(random_bytes(20));
        } catch (Throwable) {
            throw new InvariantViolation('Secure guest identity could not be generated.');
        }
        if (headers_sent() || !function_exists('setcookie')) {
            throw new InvariantViolation('A durable guest financial identity could not be established safely.');
        }
        $stored = setcookie('sabri_cf03_guest_ref', $actor, [
            'expires' => time() + self::GUEST_REFERENCE_TTL_SECONDS,
            'path' => '/',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($stored !== true) {
            throw new InvariantViolation('A durable guest financial identity could not be established safely.');
        }
        return $actor;
    }

    private static function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
    }

    private static function method(mixed $request): string
    {
        return is_object($request) && method_exists($request, 'get_method')
            ? strtoupper((string)$request->get_method())
            : 'GET';
    }

    private static function param(mixed $request, string $name): mixed
    {
        return is_object($request) && method_exists($request, 'get_param')
            ? $request->get_param($name)
            : null;
    }

    private static function header(mixed $request, string $name): ?string
    {
        $value = is_object($request) && method_exists($request, 'get_header')
            ? $request->get_header($name)
            : null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string,string> */
    private static function boundedHeaders(mixed $request): array
    {
        if (!is_object($request) || !method_exists($request, 'get_headers')) {
            return [];
        }
        $raw = (array)$request->get_headers();
        if (count($raw) > self::MAX_WEBHOOK_HEADERS) {
            throw new InvalidArgumentException('Provider webhook contains too many headers.');
        }
        $headers = [];
        foreach ($raw as $name => $value) {
            $normalizedName = strtolower(trim((string)$name));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $normalizedName) !== 1) {
                throw new InvalidArgumentException('Provider webhook contains an invalid header name.');
            }
            if (in_array($normalizedName, self::SENSITIVE_WEBHOOK_HEADERS, true)) {
                continue;
            }
            $normalizedValue = is_array($value) ? implode(',', $value) : (string)$value;
            if (strlen($normalizedValue) > self::MAX_WEBHOOK_HEADER_BYTES
                || preg_match('/[\x00\r\n]/', $normalizedValue) === 1
            ) {
                throw new InvalidArgumentException('Provider webhook contains an invalid or oversized header value.');
            }
            $headers[$normalizedName] = $normalizedValue;
        }
        return $headers;
    }

    private static function assertBodyLimit(mixed $request, int $maximumBytes): void
    {
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('Request body limit is invalid.');
        }
        if (is_object($request) && method_exists($request, 'get_body')) {
            $body = (string)$request->get_body();
            if (strlen($body) > $maximumBytes) {
                throw new InvalidArgumentException('Request body exceeds the accepted size limit.');
            }
        }
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => throw new InvalidArgumentException('Boolean field is invalid.'),
        };
    }

    private static function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($parsed)) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException('A positive integer amount or version is required.');
    }

    private static function safePublicError(Throwable $error): mixed
    {
        if ($error instanceof InvalidArgumentException) {
            return self::error('sabri_cf03_invalid_request', $error->getMessage(), 422);
        }
        if ($error instanceof InvariantViolation) {
            return self::error(
                'sabri_cf03_unavailable',
                'The financial service is unavailable or the request conflicts with its current safe state.',
                409
            );
        }
        return self::error(
            'sabri_cf03_internal_error',
            'The financial operation could not be completed safely.',
            500
        );
    }

    private static function safeError(Throwable $error): mixed
    {
        $status = $error instanceof InvalidArgumentException
            ? 422
            : ($error instanceof InvariantViolation ? 409 : 500);
        $message = $status === 500
            ? 'The financial operation could not be completed safely.'
            : $error->getMessage();
        return self::error(
            'sabri_cf03_'.($status === 422 ? 'invalid_request' : ($status === 409 ? 'conflict' : 'internal_error')),
            $message,
            $status
        );
    }

    private static function error(string $code, string $message, int $status): mixed
    {
        if (class_exists('WP_Error')) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }
}
