<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Application\CatalogDisclosureService;
use Sabri\CF03\Application\ExpenseTransparencyService;
use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\IncidentOperationsService;
use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressFinanceAdminApi
{
    public const NAMESPACE = 'sabri-finance/v1';

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        $routes = [
            ['/catalog', 'GET', 'catalog', 'public'],
            ['/exports', 'POST', 'requestExport', 'exports'],
            ['/exports/(?P<id>[A-Za-z0-9._:-]+)/(?:grant|download)', 'POST', 'grantExport', 'exports'],
            ['/exports/(?P<id>[A-Za-z0-9._:-]+)/revoke', 'POST', 'revokeExport', 'exports'],
            ['/donor-acknowledgments', 'POST', 'acknowledgeDonor', 'authenticated'],
            ['/donor-acknowledgments/(?P<id>[A-Za-z0-9._:-]+)/revoke', 'POST', 'revokeAcknowledgment', 'authenticated'],
            ['/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/appeal', 'POST', 'appealFraud', 'authenticated'],
            ['/admin/settlements', 'POST', 'importSettlement', 'settlements'],
            ['/admin/settlements/(?P<id>[A-Za-z0-9._:-]+)/post', 'POST', 'postSettlement', 'settlements'],
            ['/admin/reconciliation/(?P<id>[A-Za-z0-9._:-]+)', 'POST', 'resolveReconciliation', 'reconciliation'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/review', 'POST', 'reviewPeriod', 'close'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/close', 'POST', 'closePeriod', 'close'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/reopen', 'POST', 'reopenPeriod', 'close'],
            ['/admin/expenses', 'POST', 'recordExpense', 'expenses'],
            ['/admin/transparency', 'POST', 'publishTransparency', 'transparency'],
            ['/admin/exports/(?P<id>[A-Za-z0-9._:-]+)/process', 'POST', 'processExport', 'exports'],
            ['/admin/adjustments', 'POST', 'requestAdjustment', 'adjustments'],
            ['/admin/adjustments/(?P<id>[A-Za-z0-9._:-]+)/decision', 'POST', 'decideAdjustment', 'adjustments'],
            ['/admin/adjustments/(?P<id>[A-Za-z0-9._:-]+)/execute', 'POST', 'executeAdjustment', 'adjustments'],
            ['/admin/fraud-reviews', 'POST', 'openFraud', 'risk'],
            ['/admin/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/decision', 'POST', 'decideFraud', 'risk'],
            ['/admin/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/close', 'POST', 'closeFraud', 'risk'],
            ['/admin/chargebacks', 'POST', 'openChargeback', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/evidence-required', 'POST', 'requireChargebackEvidence', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/evidence', 'POST', 'submitChargebackEvidence', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/accepted', 'POST', 'acceptChargebackEvidence', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/outcome', 'POST', 'chargebackOutcome', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/ledger', 'POST', 'adjustChargeback', 'risk'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/close', 'POST', 'closeChargeback', 'risk'],
            ['/admin/retention', 'POST', 'scheduleRetention', 'retention'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/hold', 'POST', 'placeHold', 'retention'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/release', 'POST', 'releaseHold', 'retention'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/execute', 'POST', 'executeRetention', 'retention'],
            ['/admin/incidents', 'POST', 'declareIncident', 'incidents'],
            ['/admin/incidents/(?P<id>[A-Za-z0-9._:-]+)/recover', 'POST', 'recoverIncident', 'incidents'],
            ['/admin/incidents/status', 'GET', 'incidentStatus', 'audit'],
            ['/admin/integrity', 'GET', 'integrity', 'audit'],
        ];

        foreach ($routes as [$route, $methods, $callback, $permission]) {
            register_rest_route(self::NAMESPACE, $route, [
                'methods' => $methods,
                'callback' => [self::class, $callback],
                'permission_callback' => match ($permission) {
                    'authenticated' => [WordPressRestApi::class, 'authenticated'],
                    'settlements' => static fn (): bool => self::cap('sabri_import_settlements'),
                    'reconciliation' => static fn (): bool => self::cap('sabri_reconcile_finance'),
                    'close' => static fn (): bool => self::cap('sabri_close_finance'),
                    'expenses' => static fn (): bool => self::cap('sabri_record_expenses'),
                    'transparency' => static fn (): bool => self::cap('sabri_publish_financial_transparency'),
                    'exports' => static fn (): bool => self::cap('sabri_manage_finance_exports'),
                    'adjustments' => static fn (): bool => self::cap('sabri_manage_finance_adjustments'),
                    'risk' => static fn (): bool => self::cap('sabri_manage_finance_risk'),
                    'retention' => static fn (): bool => self::cap('sabri_manage_finance_retention'),
                    'incidents' => static fn (): bool => self::cap('sabri_manage_finance_incidents'),
                    'audit' => static fn (): bool => self::cap('sabri_view_finance_audit'),
                    default => '__return_true',
                },
            ]);
        }
    }

    public static function catalog(mixed $request = null): mixed
    {
        return self::handle(static function (): array {
            $repo = self::repo();
            return (new CatalogDisclosureService($repo, new FinancialAuditService($repo)))
                ->publicCatalog(new DateTimeImmutable('now'));
        });
    }

    public static function requestExport(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::exports()->request(
            (string)self::param($request, 'job_id'),
            self::actor(),
            self::stringList(self::param($request, 'fields')),
            self::arrayValue(self::param($request, 'filters'), 'filters'),
            self::positive(self::param($request, 'maximum_rows')),
            self::date(self::param($request, 'expires_at')),
            new DateTimeImmutable('now')
        ));
    }

    public static function processExport(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::exports()->process(
            (string)self::param($request, 'id'),
            self::actor(),
            self::positive(self::param($request, 'expected_version')),
            new DateTimeImmutable('now')
        ));
    }

    public static function grantExport(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $grant = self::exports()->grant(
                (string)self::param($request, 'id'),
                self::actor(),
                true,
                new DateTimeImmutable('now'),
                self::date(self::param($request, 'expires_at'))
            );
            return $grant->toPresentationContract();
        });
    }

    public static function revokeExport(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::exports()->revoke(
            (string)self::param($request, 'id'),
            self::actor(),
            true,
            self::positive(self::param($request, 'expected_version')),
            new DateTimeImmutable('now')
        ));
    }

    public static function acknowledgeDonor(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::transparency()->consentToAcknowledgment(
            (string)self::param($request, 'acknowledgment_id'),
            (string)self::param($request, 'donation_id'),
            self::actor(),
            (string)self::param($request, 'display_name'),
            (string)self::param($request, 'consent_text'),
            new DateTimeImmutable('now')
        ));
    }

    public static function revokeAcknowledgment(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::transparency()->revokeAcknowledgment(
            (string)self::param($request, 'id'),
            self::actor(),
            new DateTimeImmutable('now')
        ));
    }

    public static function importSettlement(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $currency = (string)self::param($request, 'currency');
            $batch = new SettlementBatch(
                (string)self::param($request, 'batch_id'),
                (string)self::param($request, 'provider'),
                new Money(self::nonNegative(self::param($request, 'gross_minor')), $currency),
                new Money(self::nonNegative(self::param($request, 'fee_minor')), $currency),
                new Money(self::nonNegative(self::param($request, 'refund_minor')), $currency),
                new Money(self::nonNegative(self::param($request, 'net_minor')), $currency),
                self::date(self::param($request, 'settled_at')),
                (string)self::param($request, 'source_hash'),
                self::arrayValue(self::param($request, 'lines'), 'lines')
            );
            return self::settlements()->importAndReconcile(
                $batch,
                self::arrayValue(self::param($request, 'internal_lines'), 'internal_lines'),
                self::integerMap(self::param($request, 'materiality_by_currency')),
                self::actor(),
                new DateTimeImmutable('now')
            );
        });
    }

    public static function postSettlement(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::settlements()->postResolvedBatch(
            (string)self::param($request, 'id'),
            self::actor(),
            new DateTimeImmutable('now')
        ));
    }

    public static function resolveReconciliation(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::settlements()->resolveException(
            (string)self::param($request, 'id'),
            self::actor(),
            (string)self::param($request, 'resolution_reference'),
            self::boolean(self::param($request, 'accepted_risk')),
            new DateTimeImmutable('now')
        ));
    }

    public static function reviewPeriod(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::settlements()->reviewPeriod(
            (string)self::param($request, 'id'),
            self::actor(),
            new DateTimeImmutable('now')
        ));
    }

    public static function closePeriod(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $periodId = (string)self::param($request, 'id');
            $period = self::repo()->get('finance_periods', $periodId);
            if ($period === null || !is_string($period['reviewed_by'] ?? null)) {
                throw new InvariantViolation('Finance period has no persisted independent review.');
            }
            return self::settlements()->closePeriod(
                $periodId,
                (string)$period['reviewed_by'],
                self::actor(),
                new DateTimeImmutable('now')
            );
        });
    }

    public static function reopenPeriod(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::settlements()->reopenPeriod(
            (string)self::param($request, 'id'),
            (string)self::param($request, 'requester_reference'),
            self::actor(),
            (string)self::param($request, 'reason_reference')
        ));
    }

    public static function recordExpense(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $expense = new DonationExpense(
                (string)self::param($request, 'expense_id'),
                self::date(self::param($request, 'occurred_at')),
                self::money($request),
                (string)self::param($request, 'category'),
                (string)self::param($request, 'purpose'),
                (string)self::param($request, 'payee_reference'),
                (string)self::param($request, 'approval_reference'),
                (string)self::param($request, 'receipt_status'),
                self::boolean(self::param($request, 'founder_related')),
                (string)self::param($request, 'public_disclosure_category')
            );
            $source = self::param($request, 'source_transaction_id');
            return self::transparency()->recordExpense(
                $expense,
                self::actor(),
                new DateTimeImmutable('now'),
                is_string($source) && $source !== '' ? $source : null
            );
        });
    }

    public static function publishTransparency(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::transparency()->buildAndPublishSnapshot(
            (string)self::param($request, 'period_key'),
            (string)self::param($request, 'currency'),
            self::actor(),
            new DateTimeImmutable('now')
        ));
    }

    public static function requestAdjustment(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::adjustments()->request(
            (string)self::param($request, 'adjustment_id'),
            (string)self::param($request, 'source_transaction_id'),
            self::money($request),
            (string)self::param($request, 'debit_account'),
            (string)self::param($request, 'credit_account'),
            (string)self::param($request, 'reason_code'),
            (string)self::param($request, 'evidence_sha256'),
            self::actor(),
            new DateTimeImmutable('now')
        ));
    }

    public static function decideAdjustment(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::adjustments()->decide(
            (string)self::param($request, 'id'),
            self::boolean(self::param($request, 'approve')),
            self::actor(),
            self::positive(self::param($request, 'expected_version')),
            new DateTimeImmutable('now')
        ));
    }

    public static function executeAdjustment(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::adjustments()->execute(
            (string)self::param($request, 'id'),
            self::actor(),
            self::positive(self::param($request, 'expected_version')),
            new DateTimeImmutable('now')
        ));
    }

    public static function openFraud(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $now = new DateTimeImmutable('now');
            return self::risk()->openFraudReview(new FraudReviewCase(
                (string)self::param($request, 'review_id'),
                (string)self::param($request, 'subject_reference'),
                self::arrayValue(self::param($request, 'signals'), 'signals'),
                $now,
                self::date(self::param($request, 'hold_until'))
            ), $now);
        });
    }

    public static function decideFraud(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->decideFraudReview(
            (string)self::param($request, 'id'),
            self::boolean(self::param($request, 'approved')),
            self::actor(),
            (string)self::param($request, 'reason'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function appealFraud(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->appealFraudReview(
            (string)self::param($request, 'id'),
            self::actor(),
            (string)self::param($request, 'reason'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function closeFraud(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->closeFraudReview(
            (string)self::param($request, 'id'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function openChargeback(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $now = new DateTimeImmutable('now');
            return self::risk()->openChargeback(new ChargebackCase(
                (string)self::param($request, 'case_id'),
                (string)self::param($request, 'provider'),
                (string)self::param($request, 'provider_case_reference'),
                (string)self::param($request, 'intent_id'),
                self::money($request),
                (string)self::param($request, 'reason_code'),
                $now,
                self::date(self::param($request, 'response_deadline'))
            ), $now);
        });
    }

    public static function requireChargebackEvidence(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->requireChargebackEvidence(
            (string)self::param($request, 'id'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function submitChargebackEvidence(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->submitChargebackEvidence(
            (string)self::param($request, 'id'),
            (string)self::param($request, 'evidence_sha256'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function acceptChargebackEvidence(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->acceptChargebackEvidence(
            (string)self::param($request, 'id'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function chargebackOutcome(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->recordChargebackOutcome(
            (string)self::param($request, 'id'),
            self::boolean(self::param($request, 'won')),
            new Money(
                self::nonNegative(self::param($request, 'provider_fee_minor')),
                (string)self::param($request, 'currency')
            ),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function adjustChargeback(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->adjustChargebackLedger(
            (string)self::param($request, 'id'),
            self::actor(),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function closeChargeback(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::risk()->closeChargeback(
            (string)self::param($request, 'id'),
            new DateTimeImmutable('now'),
            self::positive(self::param($request, 'expected_version'))
        ));
    }

    public static function scheduleRetention(mixed $request = null): mixed
    {
        return self::handle(static function () use ($request): array {
            $expires = self::param($request, 'expires_at');
            return self::retention()->schedule(
                (string)self::param($request, 'record_type'),
                (string)self::param($request, 'record_reference'),
                (string)self::param($request, 'data_class'),
                self::date(self::param($request, 'created_at')),
                is_string($expires) && $expires !== '' ? self::date($expires) : null,
                (string)self::param($request, 'delete_mode')
            );
        });
    }

    public static function placeHold(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::retention()->placeLegalHold(
            (string)self::param($request, 'id'),
            (string)self::param($request, 'hold_reference')
        ));
    }

    public static function releaseHold(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::retention()->releaseLegalHold(
            (string)self::param($request, 'id'),
            (string)self::param($request, 'hold_reference')
        ));
    }

    public static function executeRetention(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::retention()->executeDue(
            (string)self::param($request, 'id'),
            new DateTimeImmutable('now')
        ));
    }

    public static function declareIncident(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::incidents()->declare(
            (string)self::param($request, 'incident_id'),
            self::nonNegative(self::param($request, 'severity')),
            (string)self::param($request, 'reason_code'),
            self::actor(),
            new DateTimeImmutable('now'),
            self::boolean(self::param($request, 'kill_checkout'), true),
            self::boolean(self::param($request, 'kill_refunds'), true),
            self::boolean(self::param($request, 'kill_webhooks'), true)
        ));
    }

    public static function recoverIncident(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::incidents()->recover(
            (string)self::param($request, 'id'),
            (string)self::param($request, 'requester_reference'),
            self::actor(),
            (string)self::param($request, 'resolution_evidence_reference'),
            new DateTimeImmutable('now'),
            self::boolean(self::param($request, 'enable_checkout')),
            self::boolean(self::param($request, 'enable_refunds')),
            self::boolean(self::param($request, 'enable_webhooks'))
        ));
    }

    public static function incidentStatus(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::incidents()->status());
    }

    public static function integrity(mixed $request = null): mixed
    {
        return self::handle(static fn (): array => self::integrityService()->health());
    }

    private static function repo(): WordPressFinancialRepository
    {
        return WordPressFinancialRepository::fromWordPress();
    }

    private static function settlements(): SettlementOperationsService
    {
        $repo = self::repo();
        return new SettlementOperationsService($repo, WordPressRuntimeConfiguration::load(), new FinancialAuditService($repo));
    }

    private static function transparency(): ExpenseTransparencyService
    {
        $repo = self::repo();
        return new ExpenseTransparencyService($repo, new FinancialAuditService($repo));
    }

    private static function exports(): SecureExportService
    {
        $repo = self::repo();
        return new SecureExportService(
            $repo,
            WordPressSecureArtifactStoreFactory::make(),
            WordPressRuntimeConfiguration::load(),
            new FinancialAuditService($repo)
        );
    }

    private static function adjustments(): FinancialAdjustmentService
    {
        $repo = self::repo();
        return new FinancialAdjustmentService($repo, WordPressRuntimeConfiguration::load(), new FinancialAuditService($repo));
    }

    private static function risk(): RiskOperationsService
    {
        return new RiskOperationsService(self::repo(), WordPressRuntimeConfiguration::load());
    }

    private static function retention(): RetentionOperationsService
    {
        return new RetentionOperationsService(self::repo(), WordPressRetentionActionExecutorFactory::make());
    }

    private static function incidents(): IncidentOperationsService
    {
        $repo = self::repo();
        return new IncidentOperationsService(
            new WordPressIncidentStateStore(),
            new FinancialAuditService($repo),
            WordPressRuntimeConfiguration::load()
        );
    }

    private static function integrityService(): SystemIntegrityService
    {
        $repo = self::repo();
        return new SystemIntegrityService($repo, new FinancialAuditService($repo));
    }

    private static function cap(string $capability): bool
    {
        return function_exists('current_user_can') && current_user_can($capability);
    }

    private static function actor(): string
    {
        $id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
        if ($id < 1) {
            throw new InvariantViolation('Authenticated actor identity is unavailable.');
        }
        return 'user:'.$id;
    }

    private static function param(mixed $request, string $name): mixed
    {
        return is_object($request) && method_exists($request, 'get_param')
            ? $request->get_param($name)
            : null;
    }

    /** @return array<string,mixed> */
    private static function arrayValue(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($name.' must be an array.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected a string list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Expected a string list.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @return array<string,int> */
    private static function integerMap(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected an integer map.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_int($item) || $item < 0) {
                throw new InvalidArgumentException('Expected a non-negative integer map.');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A date-time is required.');
        }
        return new DateTimeImmutable($value);
    }

    private static function positive(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            return (int)$value;
        }
        throw new InvalidArgumentException('A positive integer is required.');
    }

    private static function nonNegative(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,18}$/', $value) === 1) {
            return (int)$value;
        }
        throw new InvalidArgumentException('A non-negative integer is required.');
    }

    private static function money(mixed $request): Money
    {
        return new Money(
            self::positive(self::param($request, 'amount_minor')),
            (string)self::param($request, 'currency')
        );
    }

    private static function boolean(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => throw new InvalidArgumentException('Boolean value is invalid.'),
        };
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
                ? 'The financial administration operation failed safely.'
                : $error->getMessage();
            if (class_exists('WP_Error')) {
                return new \WP_Error(
                    'sabri_cf03_admin_'.($status === 422 ? 'invalid' : ($status === 409 ? 'conflict' : 'error')),
                    $message,
                    ['status' => $status]
                );
            }
            return ['code' => 'sabri_cf03_admin_error', 'message' => $message, 'status' => $status];
        }
    }
}
