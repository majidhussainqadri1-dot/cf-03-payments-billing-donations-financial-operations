<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\IncidentStateStore;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Support\InvariantViolation;

final class IncidentOperationsService
{
    public function __construct(
        private readonly IncidentStateStore $store,
        private readonly FinancialAuditService $audit,
        private readonly RuntimeConfiguration $runtime
    ) {}

    /** @return array<string,mixed> */
    public function declare(
        string $incidentId,
        int $severity,
        string $reasonCode,
        string $commanderReference,
        DateTimeImmutable $at,
        bool $killCheckout = true,
        bool $killRefunds = true,
        bool $killWebhooks = true
    ): array {
        foreach ([$incidentId, $commanderReference] as $reference) {
            self::reference($reference);
        }
        if ($severity < 0
            || $severity > 4
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $reasonCode) !== 1
        ) {
            throw new InvalidArgumentException('Incident severity or reason is invalid.');
        }

        $current = $this->store->get();
        $mode = (string)($current['state'] ?? 'corrupt');
        if ($mode === 'contained') {
            throw new InvariantViolation('An active financial incident must be recovered before another incident is declared.');
        }
        if (!in_array($mode, ['normal', 'recovered'], true)) {
            throw new InvariantViolation('Corrupt financial incident state blocks incident mutation.');
        }
        if ($mode === 'recovered' && isset($current['recovered_at']) && is_string($current['recovered_at'])) {
            $recoveredAt = new DateTimeImmutable($current['recovered_at']);
            if ($at <= $recoveredAt) {
                throw new InvariantViolation('A new incident declaration must follow the previous recovery.');
            }
        }

        $version = (int)($current['record_version'] ?? 1);
        $state = [
            'state' => 'contained',
            'incident_id' => $incidentId,
            'severity' => $severity,
            'reason_code' => $reasonCode,
            'checkout_enabled' => $killCheckout ? false : (bool)$current['checkout_enabled'],
            'refunds_enabled' => $killRefunds ? false : (bool)$current['refunds_enabled'],
            'webhooks_enabled' => $killWebhooks ? false : (bool)$current['webhooks_enabled'],
            'declared_at' => $at->format(DATE_ATOM),
            'declared_by' => $commanderReference,
            'recovered_at' => null,
            'recovered_by' => null,
            'resolution_evidence_ref' => null,
            'record_version' => $version + 1,
        ];
        if ($state['checkout_enabled'] && !$state['webhooks_enabled']) {
            $state['checkout_enabled'] = false;
        }
        $this->store->save($state);
        $this->audit->append(new AuditEnvelope(
            'audit:incident:'.substr(hash('sha256', $incidentId.'|'.$at->format(DATE_ATOM)), 0, 32),
            $commanderReference,
            'incident_declared',
            'financial_incident',
            $incidentId,
            'incident_containment',
            AuditOutcome::SUCCEEDED,
            $at,
            'trace:incident:'.substr(hash('sha256', $incidentId), 0, 24),
            [
                'severity' => $severity,
                'reason_code' => $reasonCode,
                'checkout_killed' => $killCheckout,
                'refunds_killed' => $killRefunds,
                'webhooks_killed' => $killWebhooks,
            ]
        ));
        return $state;
    }

    /** @return array<string,mixed> */
    public function recover(
        string $incidentId,
        string $requesterReference,
        string $approverReference,
        string $resolutionEvidenceReference,
        DateTimeImmutable $at,
        bool $enableCheckout,
        bool $enableRefunds,
        bool $enableWebhooks
    ): array {
        foreach ([$incidentId, $requesterReference, $approverReference, $resolutionEvidenceReference] as $reference) {
            self::reference($reference);
        }
        if ($requesterReference === $approverReference) {
            throw new InvariantViolation('Incident recovery requires independent approval.');
        }
        if ($enableCheckout && !$enableWebhooks) {
            throw new InvariantViolation('Checkout recovery requires simultaneous trusted webhook recovery.');
        }

        $state = $this->store->get();
        if (($state['state'] ?? null) !== 'contained' || ($state['incident_id'] ?? null) !== $incidentId) {
            throw new InvariantViolation('Financial incident is not in a recoverable contained state.');
        }
        $declaredAt = new DateTimeImmutable((string)$state['declared_at']);
        if ($at <= $declaredAt) {
            throw new InvariantViolation('Financial incident recovery must follow declaration.');
        }

        $this->runtime->assertFinancialMutationReady();
        if ($enableCheckout || $enableWebhooks) {
            $this->runtime->assertDonationCheckoutReady();
        }

        $state['state'] = 'recovered';
        $state['checkout_enabled'] = $enableCheckout;
        $state['refunds_enabled'] = $enableRefunds;
        $state['webhooks_enabled'] = $enableWebhooks;
        $state['recovered_at'] = $at->format(DATE_ATOM);
        $state['recovered_by'] = $approverReference;
        $state['resolution_evidence_ref'] = $resolutionEvidenceReference;
        $state['record_version'] = (int)$state['record_version'] + 1;
        $this->store->save($state);
        $this->audit->append(new AuditEnvelope(
            'audit:recovery:'.substr(hash('sha256', $incidentId.'|'.$at->format(DATE_ATOM)), 0, 32),
            $approverReference,
            'incident_recovered',
            'financial_incident',
            $incidentId,
            'incident_recovery',
            AuditOutcome::SUCCEEDED,
            $at,
            'trace:incident:'.substr(hash('sha256', $incidentId), 0, 24),
            [
                'requester_ref' => $requesterReference,
                'resolution_evidence_ref' => $resolutionEvidenceReference,
                'checkout_enabled' => $enableCheckout,
                'refunds_enabled' => $enableRefunds,
                'webhooks_enabled' => $enableWebhooks,
            ]
        ));
        return $state;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return $this->store->get();
    }

    private static function reference(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException('Incident reference is invalid.');
        }
    }
}
