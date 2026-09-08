<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use RuntimeException;

final class FutureIntegrationSustainabilityService
{
    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public function privacyPreservingAnalytics(array $rows): array
    {
        $totals = ['checkout_attempts' => 0, 'checkout_successes' => 0, 'refunds_completed' => 0, 'reconciliation_exceptions' => 0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += max(0, (int)($row[$key] ?? 0));
            }
            foreach (['actor_ref','donor_ref','email','phone','ip_address'] as $forbidden) {
                if (array_key_exists($forbidden, $row)) {
                    throw new InvalidArgumentException('Privacy-preserving analytics may not ingest direct donor/user identifiers.');
                }
            }
        }
        return [
            'metrics' => $totals,
            'aggregate_only' => true,
            'behavioral_targeting' => false,
            'donor_profiling' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function accessibilityContract(): array
    {
        return [
            'keyboard_only' => true,
            'screen_reader' => true,
            'zoom_200_percent' => true,
            'reflow_320_css_px' => true,
            'rtl_urdu' => true,
            'ltr_english' => true,
            'reduced_motion' => true,
            'low_bandwidth_mode' => true,
            'error_identification' => true,
            'target' => 'WCAG 2.2 AA-oriented',
        ];
    }

    /** @param array<string,mixed> $financialCase @return array<string,mixed> */
    public function supportBridge(array $financialCase): array
    {
        $allowed = ['case_ref','intent_id','receipt_id','refund_id','state','amount_minor','currency','last_updated_at'];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $financialCase)) {
                $safe[$key] = $financialCase[$key];
            }
        }
        return [
            'case' => $safe,
            'read_only' => true,
            'refund_approval_allowed' => false,
            'ledger_mutation_allowed' => false,
            'provider_secrets_visible' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function notificationPreferences(bool $donationAppealsEnabled): array
    {
        return [
            'donation_appeals' => $donationAppealsEnabled,
            'transaction_receipts' => true,
            'refund_updates' => true,
            'dispute_updates' => true,
            'security_notices' => true,
            'mandatory_financial_notices_mutable' => false,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function financeEventEnvelope(string $eventType, string $aggregateId, string $traceId, array $payload): array
    {
        if ($eventType === '' || $aggregateId === '' || $traceId === '') {
            throw new InvalidArgumentException('Finance event type, aggregate and trace IDs are required.');
        }
        foreach (['grant_access','rank_boost','verification_upgrade','ai_quota','education_access'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new InvalidArgumentException('Financial events may not carry entitlement or ranking commands.');
            }
        }
        ksort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode finance event payload.');
        }
        return [
            'event_type' => $eventType,
            'aggregate_id' => $aggregateId,
            'trace_id' => $traceId,
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $json),
            'entitlement_command' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function jurisdictionRule(
        string $country,
        array $currencies,
        array $providers,
        array $requiredDisclosures,
        bool $launchApproved
    ): array {
        $country = strtoupper($country);
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InvalidArgumentException('Jurisdiction must use a two-letter country code.');
        }
        return [
            'country' => $country,
            'currencies' => array_values(array_unique(array_map('strtoupper', $currencies))),
            'providers' => array_values(array_unique($providers)),
            'required_disclosures' => array_values(array_unique($requiredDisclosures)),
            'launch_approved' => $launchApproved,
            'unsupported_fails_closed' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function taxLegalDisclosure(string $jurisdiction, string $version, string $text, bool $legalApproved, bool $taxDeductibleClaim): array
    {
        if ($taxDeductibleClaim && !$legalApproved) {
            throw new InvalidArgumentException('Tax-deductible wording requires explicit legal approval.');
        }
        return [
            'jurisdiction' => strtoupper($jurisdiction),
            'version' => $version,
            'text' => $text,
            'legal_approved' => $legalApproved,
            'tax_deductible_claim' => $taxDeductibleClaim,
            'blanket_global_claim' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function shariaClassification(string $classification, bool $founderApproved, bool $shariaApproved, bool $legalApproved): array
    {
        $allowed = ['general_donation','sadaqah','zakat','waqf'];
        if (!in_array($classification, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported Sharia financial classification.');
        }
        $enabled = $classification === 'general_donation'
            ? true
            : ($founderApproved && $shariaApproved && $legalApproved);
        return [
            'classification' => $classification,
            'enabled' => $enabled,
            'separate_fund_required' => $classification !== 'general_donation',
            'separate_eligibility_rules_required' => in_array($classification, ['zakat','waqf'], true),
            'public_one_time_donation_law_unchanged' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function sustainabilityModule(string $flow, bool $founderApproved, bool $legalAccountingApproved): array
    {
        if (!in_array($flow, ['grant','waqf'], true)) {
            throw new InvalidArgumentException('Only grant or waqf sustainability flow is recognized here.');
        }
        return [
            'flow' => $flow,
            'enabled' => $founderApproved && $legalAccountingApproved,
            'separate_from_public_donation' => true,
            'separate_accounting_required' => true,
            'change_control_required' => true,
        ];
    }

    /**
     * @param array<string,mixed> $provider
     * @param array<string,mixed> $reconciliation
     * @param array<string,mixed> $disputes
     * @param array<string,mixed> $close
     * @param array<string,mixed> $deployment
     * @param array<string,bool> $killSwitches
     * @return array<string,mixed>
     */
    public function founderCommandCenter(
        array $provider,
        array $reconciliation,
        array $disputes,
        array $close,
        array $deployment,
        array $killSwitches
    ): array {
        return [
            'provider_health' => $provider,
            'reconciliation' => $reconciliation,
            'disputes' => $disputes,
            'period_close' => $close,
            'deployment_readiness' => $deployment,
            'kill_switches' => $killSwitches,
            'direct_ledger_edit' => false,
            'donor_privilege_controls' => false,
            'command_scope' => 'operational_governance_only',
        ];
    }
}
