<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Persists the first half of high-risk two-person control operations.
 * The authenticated requester is stored server-side; an approver can never supply
 * or spoof that identity in the approval request body.
 */
final class FinancialControlRequestService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly FinancialAuditService $audit,
        private readonly IncidentStateStore $incidentState,
        private readonly IncidentOperationsService $incidents
    ) {}

    /** @return array<string,mixed> */
    public function requestPeriodReopen(
        string $periodId,
        string $requesterReference,
        string $reasonReference,
        DateTimeImmutable $requestedAt
    ): array {
        self::period($periodId);
        self::reference($requesterReference, 'Finance reopen requester');
        self::reference($reasonReference, 'Finance reopen reason');
        $period = $this->repository->get('finance_periods', $periodId);
        if ($period === null || ($period['state'] ?? null) !== 'locked') {
            throw new InvariantViolation('Only a locked finance period may receive a reopen request.');
        }
        $version = (int)($period['version'] ?? $period['record_version'] ?? 0);
        if ($version < 1) {
            throw new InvariantViolation('Finance period version is unavailable for reopen control.');
        }
        $claimId = self::periodClaimId($periodId, $version);
        $requestHash = self::hash([
            'operation' => 'finance_period_reopen',
            'period_id' => $periodId,
            'period_version' => $version,
            'reason_reference' => $reasonReference,
        ]);
        $record = [
            'scope' => 'finance_period_reopen',
            'idempotency_key' => $claimId,
            'actor_ref' => $requesterReference,
            'request_hash' => $requestHash,
            'state' => 'pending',
            'result_ref' => $reasonReference,
            'created_at' => $requestedAt,
            'completed_at' => null,
            'expires_at' => $requestedAt->modify('+24 hours'),
        ];
        $existing = $this->repository->get('idempotency', $claimId);
        if ($existing !== null) {
            self::assertClaimParity($existing, $record, $requestedAt);
            return [
                'period_id' => $periodId,
                'request_state' => 'pending',
                'requester_ref' => $requesterReference,
                'reason_reference' => $reasonReference,
                'expires_at' => self::date($existing['expires_at'])->format(DATE_ATOM),
                'reused' => true,
            ];
        }

        $this->repository->transaction(function () use ($claimId, $record, $periodId, $requesterReference, $reasonReference, $requestedAt, $version): void {
            $this->repository->insert('idempotency', $claimId, $record);
            $this->audit->append(new AuditEnvelope(
                'audit:period-reopen-request:'.substr(hash('sha256', $claimId), 0, 32),
                $requesterReference,
                'finance_period_reopen_requested',
                'finance_period',
                $periodId,
                'financial_close_control',
                AuditOutcome::SUCCEEDED,
                $requestedAt,
                'trace:period:'.substr(hash('sha256', $periodId), 0, 24),
                ['reason_reference' => $reasonReference, 'period_version' => $version]
            ));
        });
        return [
            'period_id' => $periodId,
            'request_state' => 'pending',
            'requester_ref' => $requesterReference,
            'reason_reference' => $reasonReference,
            'expires_at' => $record['expires_at']->format(DATE_ATOM),
            'reused' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function approvePeriodReopen(string $periodId, string $approverReference, DateTimeImmutable $approvedAt): array
    {
        self::period($periodId);
        self::reference($approverReference, 'Finance reopen approver');
        $period = $this->repository->get('finance_periods', $periodId);
        if ($period === null || ($period['state'] ?? null) !== 'locked') {
            throw new InvariantViolation('Only a locked finance period may be reopened.');
        }
        $version = (int)($period['version'] ?? $period['record_version'] ?? 0);
        $claimId = self::periodClaimId($periodId, $version);
        $claim = $this->requirePendingClaim($claimId, 'finance_period_reopen', $approvedAt);
        $requester = (string)$claim['actor_ref'];
        $reason = (string)$claim['result_ref'];
        if (hash_equals($requester, $approverReference)) {
            throw new InvariantViolation('Finance period reopen requires two distinct authenticated actors.');
        }
        $expectedHash = self::hash([
            'operation' => 'finance_period_reopen',
            'period_id' => $periodId,
            'period_version' => $version,
            'reason_reference' => $reason,
        ]);
        if (!hash_equals($expectedHash, (string)$claim['request_hash'])) {
            throw new InvariantViolation('Finance period reopen request evidence does not match current locked state.');
        }

        return $this->repository->transaction(function () use ($period, $periodId, $version, $claimId, $requester, $approverReference, $reason, $approvedAt): array {
            $updated = $this->repository->compareAndSwap(
                'finance_periods',
                $periodId,
                $version,
                static function (array $current) use ($reason): array {
                    if (($current['state'] ?? null) !== 'locked') {
                        throw new InvariantViolation('Finance period changed before reopen approval.');
                    }
                    $current['state'] = 'exception_review';
                    $current['reviewed_by'] = null;
                    $current['reviewed_at'] = null;
                    $current['approved_by'] = null;
                    $current['accepted_risk_ref'] = $reason;
                    $current['closed_at'] = null;
                    return $current;
                }
            );
            $completed = $this->repository->updateWhere('idempotency', [
                'idempotency_key' => $claimId,
                'scope' => 'finance_period_reopen',
                'state' => 'pending',
            ], [
                'state' => 'completed',
                'completed_at' => $approvedAt,
            ]);
            if ($completed !== 1) {
                throw new InvariantViolation('Finance period reopen control request could not be completed exactly once.');
            }
            $this->audit->append(new AuditEnvelope(
                'audit:period-reopened:'.substr(hash('sha256', $claimId.'|'.$approverReference), 0, 32),
                $approverReference,
                'finance_period_reopened',
                'finance_period',
                $periodId,
                'financial_close_control',
                AuditOutcome::SUCCEEDED,
                $approvedAt,
                'trace:period:'.substr(hash('sha256', $periodId), 0, 24),
                [
                    'requester_ref' => $requester,
                    'approver_ref' => $approverReference,
                    'reason_reference' => $reason,
                    'closed_version' => $version,
                ]
            ));
            return [
                'period_id' => $periodId,
                'state' => (string)$updated['state'],
                'version' => (int)$updated['version'],
                'requester_ref' => $requester,
                'approver_ref' => $approverReference,
                'reason_reference' => $reason,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function requestIncidentRecovery(
        string $incidentId,
        string $requesterReference,
        string $resolutionEvidenceReference,
        DateTimeImmutable $requestedAt,
        bool $enableCheckout,
        bool $enableRefunds,
        bool $enableWebhooks
    ): array {
        self::reference($incidentId, 'Incident ID');
        self::reference($requesterReference, 'Incident recovery requester');
        self::reference($resolutionEvidenceReference, 'Incident recovery evidence');
        if ($enableCheckout && !$enableWebhooks) {
            throw new InvariantViolation('Checkout recovery requires simultaneous trusted webhook recovery.');
        }
        $state = $this->incidentState->get();
        if (($state['state'] ?? null) !== 'contained' || ($state['incident_id'] ?? null) !== $incidentId) {
            throw new InvariantViolation('Financial incident is not awaiting a recovery request.');
        }
        $version = (int)($state['record_version'] ?? 0);
        if ($version < 1) {
            throw new InvariantViolation('Financial incident version is unavailable.');
        }
        $claimId = self::incidentClaimId($incidentId, $version);
        $requestHash = self::hash([
            'operation' => 'incident_recovery',
            'incident_id' => $incidentId,
            'incident_version' => $version,
            'resolution_evidence_reference' => $resolutionEvidenceReference,
            'enable_checkout' => $enableCheckout,
            'enable_refunds' => $enableRefunds,
            'enable_webhooks' => $enableWebhooks,
        ]);
        $record = [
            'scope' => 'incident_recovery',
            'idempotency_key' => $claimId,
            'actor_ref' => $requesterReference,
            'request_hash' => $requestHash,
            'state' => 'pending',
            'result_ref' => $resolutionEvidenceReference,
            'created_at' => $requestedAt,
            'completed_at' => null,
            'expires_at' => $requestedAt->modify('+4 hours'),
        ];
        $existing = $this->repository->get('idempotency', $claimId);
        if ($existing !== null) {
            self::assertClaimParity($existing, $record, $requestedAt);
            return ['incident_id' => $incidentId, 'request_state' => 'pending', 'reused' => true];
        }
        $this->repository->transaction(function () use ($claimId, $record, $incidentId, $requesterReference, $resolutionEvidenceReference, $requestedAt, $enableCheckout, $enableRefunds, $enableWebhooks): void {
            $this->repository->insert('idempotency', $claimId, $record);
            $this->audit->append(new AuditEnvelope(
                'audit:incident-recovery-request:'.substr(hash('sha256', $claimId), 0, 32),
                $requesterReference,
                'incident_recovery_requested',
                'financial_incident',
                $incidentId,
                'incident_recovery',
                AuditOutcome::SUCCEEDED,
                $requestedAt,
                'trace:incident:'.substr(hash('sha256', $incidentId), 0, 24),
                [
                    'resolution_evidence_ref' => $resolutionEvidenceReference,
                    'checkout_enabled' => $enableCheckout,
                    'refunds_enabled' => $enableRefunds,
                    'webhooks_enabled' => $enableWebhooks,
                ]
            ));
        });
        return ['incident_id' => $incidentId, 'request_state' => 'pending', 'reused' => false];
    }

    /** @return array<string,mixed> */
    public function approveIncidentRecovery(
        string $incidentId,
        string $approverReference,
        string $resolutionEvidenceReference,
        DateTimeImmutable $approvedAt,
        bool $enableCheckout,
        bool $enableRefunds,
        bool $enableWebhooks
    ): array {
        self::reference($incidentId, 'Incident ID');
        self::reference($approverReference, 'Incident recovery approver');
        self::reference($resolutionEvidenceReference, 'Incident recovery evidence');
        $state = $this->incidentState->get();
        if (($state['state'] ?? null) !== 'contained' || ($state['incident_id'] ?? null) !== $incidentId) {
            throw new InvariantViolation('Financial incident is not in a contained state awaiting approval.');
        }
        $version = (int)($state['record_version'] ?? 0);
        $claimId = self::incidentClaimId($incidentId, $version);
        $claim = $this->requirePendingClaim($claimId, 'incident_recovery', $approvedAt);
        $requester = (string)$claim['actor_ref'];
        if (hash_equals($requester, $approverReference)) {
            throw new InvariantViolation('Incident recovery requires two distinct authenticated actors.');
        }
        $expectedHash = self::hash([
            'operation' => 'incident_recovery',
            'incident_id' => $incidentId,
            'incident_version' => $version,
            'resolution_evidence_reference' => $resolutionEvidenceReference,
            'enable_checkout' => $enableCheckout,
            'enable_refunds' => $enableRefunds,
            'enable_webhooks' => $enableWebhooks,
        ]);
        if (!hash_equals($expectedHash, (string)$claim['request_hash'])
            || !hash_equals($resolutionEvidenceReference, (string)$claim['result_ref'])
        ) {
            throw new InvariantViolation('Incident recovery approval differs from the persisted requester proposal.');
        }

        try {
            return $this->repository->transaction(function () use (
                $incidentId,
                $requester,
                $approverReference,
                $resolutionEvidenceReference,
                $approvedAt,
                $enableCheckout,
                $enableRefunds,
                $enableWebhooks,
                $claimId
            ): array {
                $recovered = $this->incidents->recover(
                    $incidentId,
                    $requester,
                    $approverReference,
                    $resolutionEvidenceReference,
                    $approvedAt,
                    $enableCheckout,
                    $enableRefunds,
                    $enableWebhooks
                );
                $completed = $this->repository->updateWhere('idempotency', [
                    'idempotency_key' => $claimId,
                    'scope' => 'incident_recovery',
                    'state' => 'pending',
                ], [
                    'state' => 'completed',
                    'completed_at' => $approvedAt,
                ]);
                if ($completed !== 1) {
                    throw new InvariantViolation('Incident recovery completed but its dual-control request was not durably acknowledged.');
                }
                return $recovered + ['requester_ref' => $requester, 'approver_ref' => $approverReference];
            });
        } catch (\Throwable $error) {
            $current = $this->incidentState->get();
            if (($current['state'] ?? null) === 'recovered'
                && ($current['incident_id'] ?? null) === $incidentId
                && (int)($current['record_version'] ?? 0) === $version + 1
            ) {
                try {
                    $this->incidentState->save($state);
                } catch (\Throwable $restoreError) {
                    throw new \RuntimeException(
                        'Incident recovery failed and the prior containment state could not be restored safely.',
                        0,
                        $restoreError
                    );
                }
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function requirePendingClaim(string $claimId, string $scope, DateTimeImmutable $now): array
    {
        $claim = $this->repository->get('idempotency', $claimId);
        if ($claim === null
            || ($claim['scope'] ?? null) !== $scope
            || ($claim['state'] ?? null) !== 'pending'
            || self::date($claim['expires_at'] ?? null) <= $now
        ) {
            throw new InvariantViolation('High-risk control request is missing, expired or already consumed.');
        }
        return $claim;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private static function assertClaimParity(array $existing, array $expected, DateTimeImmutable $now): void
    {
        foreach (['scope','actor_ref','request_hash','state','result_ref'] as $field) {
            if (($existing[$field] ?? null) !== ($expected[$field] ?? null)) {
                throw new InvariantViolation('High-risk control request identifier was reused with different evidence.');
            }
        }
        if (($existing['state'] ?? null) !== 'pending' || self::date($existing['expires_at'] ?? null) <= $now) {
            throw new InvariantViolation('High-risk control request is expired or no longer pending.');
        }
    }

    private static function periodClaimId(string $periodId, int $version): string
    {
        return 'control.period-reopen.'.substr(hash('sha256', $periodId.'|'.$version), 0, 32);
    }

    private static function incidentClaimId(string $incidentId, int $version): string
    {
        return 'control.incident-recovery.'.substr(hash('sha256', $incidentId.'|'.$version), 0, 32);
    }

    private static function period(string $periodId): void
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodId) !== 1) {
            throw new InvalidArgumentException('Finance period ID is invalid.');
        }
    }

    private static function reference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) { return $value; }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('High-risk control request timestamp is missing.');
        }
        return new DateTimeImmutable($value);
    }

    private static function hash(array $value): string
    {
        ksort($value, SORT_STRING);
        try {
            return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $error) {
            throw new InvariantViolation('High-risk control request could not be canonically encoded.', 0, $error);
        }
    }
}
