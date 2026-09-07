# CF-03 Requirements Traceability — Current Governing Plans / 1.3.0-rc.1

This matrix is the current **source-code traceability** for the two governing documents:

1. `SSH-PMP-2026-v3.0` — Sabri Social Homeopathy Platform Definitive Master Plan v3.0;
2. `CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.0`.

It does **not** claim provider, legal, PCI, staging, Live or operational acceptance. Where a requirement contains an external acceptance test, the source contract is implemented but the external acceptance gate remains pending.

| Requirement | Current source implementation | Source status | External acceptance |
|---|---|---|---|
| CF03-FR-001 Financial product registry | `FinancialProduct`, `DonationCatalogSeeder`, `CatalogDisclosureService`; only active collectible product is `donation.one_time`, no entitlement mapping | Implemented | Staging catalog/admin review pending |
| CF03-FR-002 Price/version policy | `PriceVersion`, `PriceCatalog`, `PriceLifecycle`, policy/version snapshots; historical records are not silently reinterpreted | Implemented | Future provider/legal price-policy acceptance where applicable |
| CF03-FR-003 Pre-action disclosure | `PrePurchaseDisclosure`, `DonationAppealCopy`, `WordPressPublicUi`; amount/currency/purpose/one-time/no-privilege disclosure, no preselection | Implemented | Real-browser/mobile/RTL/accessibility acceptance pending |
| CF03-FR-004 Zero commission | `CommissionPolicy`, `PlatformFinancialPolicy`; Clinic/Marketplace = 0 bp | Implemented invariant | Cross-file File 08/18 staging parity pending |
| CF03-FR-005 Donation non-privilege | `DonationNeutralityPolicy`, no-access outbox facts, File 00 boundary | Implemented invariant | Cross-file donor/non-donor end-to-end staging parity pending |
| CF03-FR-006 Hosted checkout | `DonationPaymentProvider`, `HostedPaymentProvider`, provider registry and fail-closed null adapters; platform never accepts raw card secrets | Implemented contract | Approved real hosted/tokenized provider + PCI responsibility pending |
| CF03-FR-007 Payment intent lifecycle | `DonationIntentDraft`, `DonationCheckoutService`, `PaymentIntent`, state/version/idempotency/expiry binding | Implemented | Provider sandbox acceptance pending |
| CF03-FR-008 Signed webhook verification | `ProviderWebhookVerifier`, `ProviderEvidence`, `WebhookIngestionService`; signature/key/timestamp/replay/body-hash/event-ID checks | Implemented | Approved provider signature/key-rotation drill pending |
| CF03-FR-009 Provider state mapping | `ProviderEventStateMapper`, unknown/retired-product quarantine, trusted transition checks | Implemented | Provider event-matrix sandbox acceptance pending |
| CF03-FR-010 Payment confirmation | `PaymentConfirmationService` and `WebhookIngestionService`; browser redirect cannot settle; durable ledger/outbox required | Implemented | Real provider/browser return tests pending |
| CF03-FR-011 Provider abstraction/exit | `PaymentProvider`, `DonationPaymentProvider`, `ProviderRegistry`, health/readiness gates | Implemented contract | Shadow provider switch/exit/rollback drill pending |
| CF03-FR-012 Immutable balanced ledger | `LedgerTransaction`, `LedgerEntry`, `LedgerJournal`, immutable repository collections, reversal model | Implemented | Restore/reconciliation acceptance pending |
| CF03-FR-013 Invoice/receipt | `Invoice`, `FinancialDocumentService`, immutable snapshot/hash, owner-scoped download contract | Implemented | Legal invoice fields + rendered HTML/PDF/staging acceptance pending |
| CF03-FR-014 Provider settlement | `SettlementBatch`, `SettlementOperationsService`, import/source hashes and matching | Implemented | Real settlement file/sandbox/bank adapter acceptance pending |
| CF03-FR-015 Daily reconciliation | `DailyReconciliationService`, `ReconciliationEngine`, WordPress scheduler/exception records | Implemented | Fault-injection and operational daily-close drill pending |
| CF03-FR-016 Financial adjustment | `FinancialAdjustment`, `FinancialAdjustmentService`, new ledger transaction/reason/evidence/dual-control model | Implemented | Role/threshold staging acceptance pending |
| CF03-FR-017 Currency/rounding | `Money` integer minor units, currency-aware balance logic; no floating-point financial truth | Implemented invariant | Additional jurisdiction/tax/FX acceptance only if activated |
| CF03-FR-018 Donation attempt lifecycle | `DonationCheckoutService`, `WebhookIngestionService`; one-time product only, recurring=false, explicit independent attempts | Implemented | Provider sandbox journey acceptance pending |
| CF03-FR-019 Seven-day appeal + explicit one-time consent | `DonationPromptPolicy`, `DonationPromptState`, `DonationAppealCopy`, REST/UI/JS consent gate; 7-day minimum | Implemented | Real browser/session/storage acceptance pending |
| CF03-FR-020 Failure recovery without recurrence | Stable idempotency/checkpoints, expiry/replay controls, no automatic repeat charge | Implemented | Provider timeout/outage fault-injection pending |
| CF03-FR-021 Access/donor parity | Donation facts are receipt/accounting facts only; `DonationNeutralityPolicy`; no access event | Implemented invariant | File 00/05/16/21/26 consumer contract tests pending across repos |
| CF03-FR-022 Abandon/revoke/refund clarity | One-time history/refund status; recurring management retired/fail-closed; new independent attempt only | Implemented | Browser/support journey acceptance pending |
| CF03-FR-023 Free AI and education boundary | `PlatformFinancialPolicy`; paid AI metering service is a compatibility tombstone; subscription runtime retired | Implemented invariant | File 05/16 cross-file parity acceptance pending |
| CF03-FR-024 Refund request | `RefundRequest`, `RefundBalance`, `RefundWorkflowService`, bounded refundable balance/idempotency | Implemented | Provider sandbox/user-support journey pending |
| CF03-FR-025 Refund authorization/execution | Reviewer/executor separation, provider checkpoint, uncertain-state reconciliation, ledger reversal | Implemented | Real provider timeout/retry/dual-control acceptance pending |
| CF03-FR-026 Chargeback/dispute | `ChargebackCase`, `RiskOperationsService`, settlement/reconciliation hooks and evidence lifecycle | Implemented | Provider dispute lifecycle/notification acceptance pending |
| CF03-FR-027 Donation receipt/refund | One-time receipt snapshot, private identity, lawful refund ledger/outbox, recurring unavailable | Implemented | Legal/accounting receipt review pending |
| CF03-FR-028 Fraud/manual review | `FraudReviewCase`, `RiskOperationsService`; bounded signals/holds/reasoned manual path | Implemented | Approved risk policy/manual-review staging acceptance pending |
| CF03-FR-029 Separation of duties | `SeparationOfDutiesPolicy`, granular WordPress finance capabilities, reviewer/executor/approver distinctions | Implemented | Quarterly-access-review operational process pending |
| CF03-FR-030 Financial minimization | Provider-safe donor clone, sensitive-header stripping, bounded public projections, prohibited-credential scans | Implemented | Independent privacy/security review pending |
| CF03-FR-031 Secure finance export | `SecureExportJob`, `SecureExportService`, manifest/hash/limits/audit/time-bound contracts | Implemented contract | Encrypted artifact store + delivery/staging acceptance pending |
| CF03-FR-032 Close and lock | `FinancePeriod`, settlement/reconciliation close controls and later adjustment model | Implemented | Finance close/reopen drill pending |
| CF03-FR-033 Provider/key incident | `IncidentControl`, `IncidentPathGuard`, `IncidentOperationsService`, kill switches and fail-closed runtime | Implemented | Key rotation/provider outage incident drill pending |
| CF03-FR-034 Backup/restore reconciliation | `BackupManifest`, `RestoreReconciliation`, provider comparison/outbox/replay controls; active schema has no recurring mandate state | Implemented contract | Isolated restore + provider-authoritative reconciliation drill pending |

## New-plan supersession lock

The following are not active CF-03 capabilities and must not be revived without separate dated Founder-approved Change-Control:

- 30-day/calendar-month donation appeal rule;
- recurring/monthly donation mandate or automatic repeat charge;
- paid AI usage/metering or AI access gating;
- paid core membership/education checkout;
- subscription renewal/grace/dunning;
- donor access/ranking/support/verification/feature advantage;
- Clinic/Marketplace platform commission above 0%;
- wallet/escrow/custody/payout/split-payment flows.

Schema `4.0.0` removes `recurring_consents`, `subscriptions`, `usage_authorizations` and `usage_facts` from the active canonical schema. Existing legacy physical tables are not treated as current truth and require bounded retention/reconciliation before eventual approved retirement.
