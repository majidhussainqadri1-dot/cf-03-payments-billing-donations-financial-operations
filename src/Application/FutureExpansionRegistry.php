<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use RuntimeException;

final class FutureExpansionRegistry
{
    public const VERSION = '1.0';
    public const AMENDMENT_ID = 'CF03-FUTURE40-2026-09-08';
    public const SOFTWARE_TARGET = '1.4.0-rc.1';

    /**
     * These are coded future capabilities, not proof of staging, Live or operational activation.
     * No entry may weaken the current free-core, zero-commission, one-time-donation or donor-neutrality law.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $safe = 'coded_future_fail_closed';
        $conditional = 'coded_conditional_change_control';

        return [
            'FX-01' => self::feature('donation-purpose-funds', 'Donation Purpose Funds', 'donation_experience', $safe, false, true, 'Approved purpose IDs with accounting categories; no access or ranking mapping.'),
            'FX-02' => self::feature('donation-reminder-preferences', 'Donation Reminder Preference Center', 'donation_experience', $safe, false, false, '7/30/90-day or never-remind preference; never less than the governing seven-day minimum.'),
            'FX-03' => self::feature('donation-privacy-controls', 'Donation Privacy Controls', 'donation_experience', $safe, false, false, 'Private, anonymous-public or aggregate-only presentation; financial evidence remains auditable.'),
            'FX-04' => self::feature('receipt-vault', 'Complete Receipt Vault', 'donation_experience', $safe, false, true, 'Historical immutable receipt/refund/dispute document projections without exposing payment secrets.'),
            'FX-05' => self::feature('receipt-authenticity-verification', 'Receipt Authenticity Verification', 'donation_experience', $safe, false, true, 'Hash/token verification for issued receipt snapshots; verification never exposes private financial fields.'),
            'FX-06' => self::feature('donation-transparency-dashboard', 'Donation Transparency Dashboard', 'transparency', $safe, false, true, 'Aggregate received/refunded/provider-fee/use totals without donor identity or behavioral profiling.'),
            'FX-07' => self::feature('use-of-funds-impact-reporting', 'Use-of-Funds and Impact Reporting', 'transparency', $safe, false, true, 'Period/purpose allocation reporting kept distinct from immutable accounting truth.'),
            'FX-08' => self::feature('multi-currency-donation-engine', 'Multi-Currency Donation Engine', 'donation_experience', $safe, true, true, 'Currency is accepted only where both approved provider and jurisdiction configuration explicitly allow it; no hidden FX.'),
            'FX-09' => self::feature('automatic-provider-selection', 'Automatic Provider Selection', 'provider_operations', $safe, true, true, 'Deterministic selection by approved capability/health/currency/jurisdiction; never by donor status or amount privilege.'),
            'FX-10' => self::feature('provider-health-dashboard', 'Provider Health Dashboard', 'provider_operations', $safe, true, true, 'Operational health, webhook lag and error-state projection with sensitive provider data redacted.'),
            'FX-11' => self::feature('provider-failover', 'Provider Failover and Disaster Switching', 'provider_operations', $safe, true, true, 'Failover may route only a new intent; an existing intent is never silently moved between providers.'),
            'FX-12' => self::feature('webhook-forensics-center', 'Webhook Forensics Center', 'provider_operations', $safe, true, true, 'Replay/duplicate/order/quarantine evidence with raw-secret stripping and immutable hashes.'),
            'FX-13' => self::feature('uncertain-transaction-resolution', 'Uncertain Transaction Resolution Center', 'provider_operations', $safe, true, true, 'Unknown provider outcomes remain uncertain until trusted evidence and ledger reconciliation resolve them.'),
            'FX-14' => self::feature('reconciliation-exception-queue', 'Smart Reconciliation Exception Queue', 'accounting', $safe, true, true, 'Missing/duplicate/amount/fee/refund/unknown-reference exceptions are categorized and ownership-ready.'),
            'FX-15' => self::feature('reconciliation-confidence-score', 'Reconciliation Confidence Score', 'accounting', $safe, true, false, 'Accounting-only reconciled percentage and exception count; never a donor/user trust badge.'),
            'FX-16' => self::feature('finance-close-checklist', 'Finance Close Checklist', 'accounting', $safe, true, true, 'Period close requires settlement, reconciliation, refund/dispute, audit, backup and independent sign-off gates.'),
            'FX-17' => self::feature('dual-approval-workbench', 'Digital Dual-Approval Workbench', 'governance', $safe, true, true, 'Requester/reviewer/approver/executor toxic combinations are rejected.'),
            'FX-18' => self::feature('refund-eligibility-preview', 'Refund Eligibility Preview', 'refunds', $safe, true, false, 'Shows bounded eligibility/policy facts without promising approval or provider outcome.'),
            'FX-19' => self::feature('refund-sla-tracker', 'Refund SLA Tracker', 'refunds', $safe, true, false, 'Tracks received/review/provider-pending/completed stages; no fabricated completion estimate.'),
            'FX-20' => self::feature('chargeback-evidence-builder', 'Chargeback Evidence Builder', 'disputes', $safe, true, true, 'Privacy-minimized financial evidence package; clinical/private material is excluded unless separately lawful and approved.'),
            'FX-21' => self::feature('financial-privacy-center', 'Financial Privacy Center', 'privacy', $safe, false, false, 'User-readable inventory of retained financial categories, purpose, retention and lawful restrictions.'),
            'FX-22' => self::feature('user-finance-data-export', 'My Finance Data Export', 'privacy', $safe, false, true, 'Scoped temporary export contract for user receipts/donations/refunds/disputes with expiry and audit.'),
            'FX-23' => self::feature('accountant-export', 'Advanced Accountant Export', 'accounting', $safe, true, true, 'Scoped journal/export contract with formula-injection neutralization and checksum manifest.'),
            'FX-24' => self::feature('immutable-audit-evidence-package', 'Immutable Audit Evidence Package', 'governance', $safe, true, true, 'Period evidence manifest binds ledger/reconciliation/configuration/approval hashes.'),
            'FX-25' => self::feature('financial-configuration-version-history', 'Financial Configuration Version History', 'governance', $safe, true, true, 'Immutable version record for purposes/currencies/provider/disclosures/refund/tax configuration.'),
            'FX-26' => self::feature('policy-simulation-preview', 'Policy Simulation and Preview Mode', 'governance', $safe, true, false, 'Pure simulation that cannot mutate production financial truth.'),
            'FX-27' => self::feature('finance-sandbox-laboratory', 'Finance Sandbox Laboratory', 'quality_assurance', $safe, true, false, 'Approved deterministic failure/success scenarios without real-money execution.'),
            'FX-28' => self::feature('deployment-readiness-dashboard', 'Deployment Readiness Dashboard', 'deployment', $safe, true, true, 'Provider/PCI/legal/tax/accounting/security/staging/restore/integration/Founder gates; collection remains fail closed until all required gates pass.'),
            'FX-29' => self::feature('financial-kill-switch-console', 'Financial Kill-Switch Console', 'incident_response', $safe, true, true, 'Independent switches for new donations, refunds, providers, webhooks and exports; history/ledger remain readable.'),
            'FX-30' => self::feature('restore-verification-dashboard', 'Restore Verification Dashboard', 'resilience', $safe, true, true, 'Post-restore counts/hashes/balances/receipts/outbox/dedupe parity must pass before writes reopen.'),
            'FX-31' => self::feature('privacy-preserving-finance-analytics', 'Privacy-Preserving Finance Analytics', 'analytics', $safe, false, false, 'Aggregate operational metrics only; no donor profiling, targeting or entitlement decisions.'),
            'FX-32' => self::feature('accessibility-first-financial-ux', 'Accessibility-First Financial UX', 'accessibility', $safe, false, false, 'WCAG-oriented keyboard/screen-reader/zoom/reflow/RTL/reduced-motion/low-bandwidth contract.'),
            'FX-33' => self::feature('cf02-support-bridge', 'CF-02 Support Bridge', 'integration', $safe, true, true, 'Support receives masked status/read-only case facts; no ledger mutation, refund approval or provider secrets.'),
            'FX-34' => self::feature('financial-notification-preference-center', 'Financial Notification Preference Center', 'notifications', $safe, false, false, 'Optional donation appeals can be muted; mandatory transaction/refund/dispute notices cannot be disabled.'),
            'FX-35' => self::feature('internal-finance-event-explorer', 'Internal Finance Event Explorer', 'observability', $safe, true, true, 'Authorized event projection with source/trace/audit IDs; events never become entitlement commands.'),
            'FX-36' => self::feature('country-jurisdiction-financial-registry', 'Country and Jurisdiction Financial Registry', 'compliance', $safe, true, true, 'Per-jurisdiction currencies/providers/disclosures/refund terms/launch approval; unsupported jurisdictions fail closed.'),
            'FX-37' => self::feature('tax-legal-disclosure-registry', 'Tax and Legal Disclosure Registry', 'compliance', $safe, true, true, 'Versioned jurisdiction disclosure; no blanket tax-deductible claim without explicit legal approval.'),
            'FX-38' => self::feature('sharia-financial-classification-layer', 'Sharia Financial Classification Layer', 'conditional_sustainability', $conditional, true, true, 'Donation/sadaqah/zakat/waqf classifications remain separate and disabled until Founder + Sharia + legal change-control approval.'),
            'FX-39' => self::feature('waqf-grant-sustainability-module', 'Waqf and Grant Sustainability Module', 'conditional_sustainability', $conditional, true, true, 'Institutional grant/waqf flows are distinct from public one-time donation and remain disabled until separate approval/accounting/legal controls.'),
            'FX-40' => self::feature('founder-financial-command-center', 'Founder Financial Command Center', 'governance', $safe, true, true, 'Read/command readiness aggregation for provider health, reconciliation, disputes, close, audit, deployment and kill switches; no direct ledger edit capability.'),
        ];
    }

    /** @return array<string,mixed> */
    public static function get(string $id): array
    {
        $all = self::all();
        if (!isset($all[$id])) {
            throw new InvalidArgumentException('Unknown CF-03 future feature ID: '.$id);
        }
        return $all[$id];
    }

    /** @return list<array<string,mixed>> */
    public static function publicCatalogue(): array
    {
        $out = [];
        foreach (self::all() as $id => $feature) {
            $out[] = [
                'id' => $id,
                'slug' => $feature['slug'],
                'name' => $feature['name'],
                'category' => $feature['category'],
                'maturity' => $feature['maturity'],
                'activated' => false,
                'requires_change_control' => $feature['requires_change_control'],
                'requires_external_acceptance' => $feature['requires_external_acceptance'],
                'current_financial_law_unchanged' => true,
            ];
        }
        return $out;
    }

    public static function assertIntegrity(): void
    {
        $all = self::all();
        if (count($all) !== 40) {
            throw new RuntimeException('CF-03 Future Expansion Pack must contain exactly 40 capabilities.');
        }
        $slugs = [];
        foreach ($all as $id => $feature) {
            if (!preg_match('/^FX-(0[1-9]|[1-3][0-9]|40)$/', $id)) {
                throw new RuntimeException('Invalid Future Expansion Pack ID: '.$id);
            }
            if (($feature['activated'] ?? true) !== false) {
                throw new RuntimeException($id.' must remain fail closed until explicitly activated by evidence.');
            }
            if (($feature['donor_privilege_allowed'] ?? true) !== false) {
                throw new RuntimeException($id.' violates donor/non-donor neutrality.');
            }
            if (($feature['recurring_donation_allowed'] ?? true) !== false) {
                throw new RuntimeException($id.' may not reintroduce recurring donation.');
            }
            if (($feature['paid_core_allowed'] ?? true) !== false) {
                throw new RuntimeException($id.' may not introduce paid core/AI/education.');
            }
            $slug = (string)$feature['slug'];
            if (isset($slugs[$slug])) {
                throw new RuntimeException('Duplicate Future Expansion Pack slug: '.$slug);
            }
            $slugs[$slug] = true;
        }
    }

    /** @return array<string,mixed> */
    private static function feature(
        string $slug,
        string $name,
        string $category,
        string $maturity,
        bool $requiresChangeControl,
        bool $requiresExternalAcceptance,
        string $summary
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'maturity' => $maturity,
            'activated' => false,
            'requires_change_control' => $requiresChangeControl,
            'requires_external_acceptance' => $requiresExternalAcceptance,
            'donor_privilege_allowed' => false,
            'recurring_donation_allowed' => false,
            'paid_core_allowed' => false,
            'platform_commission_basis_points' => 0,
            'summary' => $summary,
        ];
    }
}
