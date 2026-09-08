<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class FutureOperationsIntelligenceService
{
    /**
     * @param list<array<string,mixed>> $providers
     * @return array<string,mixed>
     */
    public function selectProvider(array $providers, string $currency, string $jurisdiction): array
    {
        $eligible = array_values(array_filter($providers, static function (array $provider) use ($currency, $jurisdiction): bool {
            return ($provider['approved'] ?? false) === true
                && ($provider['healthy'] ?? false) === true
                && in_array(strtoupper($currency), array_map('strtoupper', (array)($provider['currencies'] ?? [])), true)
                && in_array(strtoupper($jurisdiction), array_map('strtoupper', (array)($provider['jurisdictions'] ?? [])), true);
        }));
        if ($eligible === []) {
            throw new RuntimeException('No approved healthy provider is available for the requested currency/jurisdiction.');
        }
        usort($eligible, static function (array $a, array $b): int {
            $priority = ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0));
            if ($priority !== 0) {
                return $priority;
            }
            $latency = ((int)($a['latency_ms'] ?? PHP_INT_MAX)) <=> ((int)($b['latency_ms'] ?? PHP_INT_MAX));
            return $latency !== 0 ? $latency : strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });
        $chosen = $eligible[0];
        return [
            'provider' => (string)($chosen['code'] ?? ''),
            'selection_basis' => ['approved', 'healthy', 'currency', 'jurisdiction', 'priority', 'latency'],
            'donor_status_considered' => false,
            'donation_amount_privilege_considered' => false,
        ];
    }

    /** @param list<array<string,mixed>> $providers @return array<string,mixed> */
    public function providerHealth(array $providers): array
    {
        $summary = [];
        foreach ($providers as $provider) {
            $code = (string)($provider['code'] ?? '');
            if ($code === '') {
                throw new InvalidArgumentException('Provider code is required.');
            }
            $summary[$code] = [
                'healthy' => (bool)($provider['healthy'] ?? false),
                'error_rate_basis_points' => max(0, (int)($provider['error_rate_basis_points'] ?? 0)),
                'webhook_lag_seconds' => max(0, (int)($provider['webhook_lag_seconds'] ?? 0)),
                'settlement_lag_seconds' => max(0, (int)($provider['settlement_lag_seconds'] ?? 0)),
                'key_expiry_at' => $provider['key_expiry_at'] ?? null,
                'secrets_redacted' => true,
            ];
        }
        ksort($summary);
        return ['providers' => $summary, 'operational_only' => true];
    }

    /** @param list<array<string,mixed>> $providers @return array<string,mixed> */
    public function failoverProvider(string $currentProvider, string $intentState, array $providers, string $currency, string $jurisdiction): array
    {
        if ($intentState !== 'new_intent') {
            throw new InvalidArgumentException('Provider failover may only select a provider for a new intent.');
        }
        $candidates = array_values(array_filter($providers, static fn(array $p): bool => (string)($p['code'] ?? '') !== $currentProvider));
        $selected = $this->selectProvider($candidates, $currency, $jurisdiction);
        $selected['existing_intent_moved'] = false;
        return $selected;
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function webhookForensics(string $eventId, string $rawBody, array $headers, string $mappedState): array
    {
        $safe = [];
        $sensitive = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'api-key'];
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower($name), $sensitive, true)) {
                $safe[strtolower($name)] = substr($value, 0, 512);
            }
        }
        ksort($safe);
        return [
            'event_id' => $eventId,
            'raw_body_sha256' => hash('sha256', $rawBody),
            'safe_headers' => $safe,
            'mapped_state' => $mappedState,
            'replay_key' => hash('sha256', $eventId.'|'.hash('sha256', $rawBody)),
            'raw_secrets_retained' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function uncertainResolution(string $providerState, bool $ledgerSettled, bool $trustedEvidence): array
    {
        if (!$trustedEvidence) {
            return ['state' => 'uncertain', 'requires_reconciliation' => true, 'final' => false];
        }
        if ($providerState === 'settled' && $ledgerSettled) {
            return ['state' => 'settled', 'requires_reconciliation' => false, 'final' => true];
        }
        if ($providerState === 'failed' && !$ledgerSettled) {
            return ['state' => 'failed', 'requires_reconciliation' => false, 'final' => true];
        }
        return ['state' => 'uncertain', 'requires_reconciliation' => true, 'final' => false];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public function reconciliationQueue(array $rows): array
    {
        $allowed = ['missing_settlement', 'duplicate_reference', 'amount_mismatch', 'fee_mismatch', 'refund_mismatch', 'unknown_reference', 'rounding_mismatch'];
        $queue = [];
        foreach ($rows as $row) {
            $type = (string)($row['type'] ?? '');
            if (!in_array($type, $allowed, true)) {
                $type = 'unknown_reference';
            }
            $queue[] = [
                'exception_id' => (string)($row['exception_id'] ?? hash('sha256', json_encode($row))),
                'type' => $type,
                'material' => (bool)($row['material'] ?? true),
                'owner_ref' => $row['owner_ref'] ?? null,
                'state' => (string)($row['state'] ?? 'open'),
            ];
        }
        return ['exceptions' => $queue, 'close_blocked' => count(array_filter($queue, static fn(array $r): bool => $r['material'] && $r['state'] !== 'resolved')) > 0];
    }

    /** @return array<string,mixed> */
    public function reconciliationConfidence(int $matched, int $total, int $openExceptions): array
    {
        if ($matched < 0 || $total < 0 || $matched > $total || $openExceptions < 0) {
            throw new InvalidArgumentException('Invalid reconciliation counts.');
        }
        $basisPoints = $total === 0 ? 10000 : intdiv($matched * 10000, $total);
        return [
            'confidence_basis_points' => $basisPoints,
            'confidence_percent' => number_format($basisPoints / 100, 2, '.', ''),
            'open_exceptions' => $openExceptions,
            'accounting_status_only' => true,
            'public_trust_badge' => false,
        ];
    }

    /** @param array<string,bool> $gates @return array<string,mixed> */
    public function financeCloseChecklist(array $gates): array
    {
        $required = ['settlements_imported', 'material_exceptions_resolved', 'refunds_reconciled', 'chargebacks_accounted', 'audit_complete', 'backup_verified', 'reviewer_signed', 'approver_signed'];
        $missing = [];
        foreach ($required as $gate) {
            if (($gates[$gate] ?? false) !== true) {
                $missing[] = $gate;
            }
        }
        return ['ready_to_close' => $missing === [], 'missing_gates' => $missing, 'required_gates' => $required];
    }

    /** @return array<string,mixed> */
    public function dualApproval(string $requester, string $reviewer, string $approver, ?string $executor = null): array
    {
        $actors = array_filter([$requester, $reviewer, $approver, $executor], static fn(?string $v): bool => $v !== null && $v !== '');
        if (count($actors) !== count(array_unique($actors))) {
            throw new InvalidArgumentException('Requester/reviewer/approver/executor must be separated for high-risk financial actions.');
        }
        return ['approved' => true, 'separation_of_duties' => true, 'actors' => $actors];
    }

    /** @return array<string,mixed> */
    public function refundEligibilityPreview(int $paidMinor, int $alreadyReservedMinor, int $requestedMinor, bool $policyWindowOpen): array
    {
        if ($paidMinor < 0 || $alreadyReservedMinor < 0 || $requestedMinor <= 0) {
            throw new InvalidArgumentException('Refund amounts must be valid integer minor units.');
        }
        $remaining = max(0, $paidMinor - $alreadyReservedMinor);
        return [
            'remaining_refundable_minor' => $remaining,
            'requested_minor' => $requestedMinor,
            'within_policy_window' => $policyWindowOpen,
            'eligible_for_review' => $policyWindowOpen && $requestedMinor <= $remaining,
            'approval_promised' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function refundSlaTimeline(string $state, DateTimeImmutable $requestedAt, ?DateTimeImmutable $updatedAt = null): array
    {
        $allowed = ['received', 'under_review', 'provider_pending', 'completed', 'rejected', 'uncertain'];
        if (!in_array($state, $allowed, true)) {
            throw new InvalidArgumentException('Unknown refund SLA state.');
        }
        return [
            'state' => $state,
            'requested_at' => $requestedAt->format(DATE_ATOM),
            'updated_at' => ($updatedAt ?? $requestedAt)->format(DATE_ATOM),
            'provider_completion_estimate' => null,
            'false_completion_promise' => false,
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function chargebackEvidencePackage(string $caseId, array $evidence): array
    {
        $allowed = ['intent_id', 'receipt_hash', 'consent_hash', 'provider_event_hash', 'refund_status', 'transaction_timeline', 'support_correspondence_hash'];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $evidence)) {
                $safe[$key] = $evidence[$key];
            }
        }
        $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not build chargeback evidence package.');
        }
        return [
            'case_id' => $caseId,
            'evidence' => $safe,
            'package_sha256' => hash('sha256', $json),
            'clinical_data_included' => false,
            'raw_credentials_included' => false,
        ];
    }
}
