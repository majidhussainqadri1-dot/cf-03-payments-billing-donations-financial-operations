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
            'runtime' => $runtime->toArray(),
            'incident' => (new WordPressIncidentStateStore())->get(),
            'live_collection_enabled' => $runtime->state()->value === 'live'
                && $runtime->missingFinancialGates() === [],
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
            return self::safeError($error);
        }
    }

    public static function donationIntent(mixed $request = null): mixed
    {
        try {
            self::incident()->assertAvailable('checkout');
            $amount = self::positiveInteger(self::param($request, 'amount_minor'));
            $currency = (string)(self::param($request, 'currency') ?? 'USD');
            if ($currency !== 'USD') {
                throw new InvalidArgumentException('Donation currency must be USD.');
            }

            $monthly = self::boolean(self::param($request, 'monthly'), false);
            $consent = self::boolean(self::param($request, 'monthly_consent'), false);
            $key = (string)(
                self::header($request, 'Idempotency-Key')
                ?? self::param($request, 'idempotency_key')
                ?? ''
            );
            $actor = self::actorReference();
            $intent = 'intent.'.substr(hash('sha256', $actor.'|'.$key), 0, 40);
            $runtime = WordPressRuntimeConfiguration::load();
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
            return (new DonationCheckoutService(
                $runtime,
                WordPressProviderRegistryFactory::donations(),
                WordPressFinancialRepository::fromWordPress()
            ))->create($draft, $runtime->providerCode());
        } catch (Throwable $error) {
            return self::safeError($error);
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
            $key = (string)(
                self::header($request, 'Idempotency-Key')
                ?? self::param($request, 'idempotency_key')
                ?? ''
            );
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
            $key = (string)(
                self::header($request, 'Idempotency-Key')
                ?? self::param($request, 'idempotency_key')
                ?? ''
            );
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
            $body = is_object($request) && method_exists($request, 'get_body')
                ? (string)$request->get_body()
                : '';
            $headers = [];
            if (is_object($request) && method_exists($request, 'get_headers')) {
                foreach ((array)$request->get_headers() as $name => $value) {
                    $headers[(string)$name] = is_array($value) ? implode(',', $value) : (string)$value;
                }
            }
            return (new WebhookIngestionService(
                WordPressRuntimeConfiguration::load(),
                WordPressProviderRegistryFactory::payments(),
                WordPressFinancialRepository::fromWordPress()
            ))->ingest($provider, $body, $headers, time());
        } catch (Throwable $error) {
            return self::safeError($error);
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
            'runtime' => $runtime->toArray(),
            'incident' => (new WordPressIncidentStateStore())->get(),
            'providers' => WordPressProviderRegistryFactory::payments()->health(),
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
        if (!headers_sent()) {
            setcookie('sabri_cf03_guest_ref', $actor, [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
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
