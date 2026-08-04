# CF-03 Dual-Plan Source Completion — 1.0.0-rc.3

## Governing basis

1. Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0.
2. CF-03 Payments, Billing, Donations and Financial Operations Conditional Complete Master Plan 2026 v1.0.
3. Founder Decision `SSH-FIN-2026-08-04-01`, which keeps the platform free and all paid products dormant/non-collectible.

This ledger means **repository source completion**, not provider, legal, staging, live or operational acceptance.

## Functional requirements traceability

| Requirement | Implemented source evidence |
|---|---|
| CF03-FR-001 | `FinancialProduct`, `ProductLifecycle`, complete product table and owner/policy/version fields |
| CF03-FR-002 | `PriceVersion`, `PriceLifecycle`, `PriceCatalog`, exact money and overlapping-window rejection |
| CF03-FR-003 | `PrePurchaseDisclosure`, recurring/tax/fee/provider/support/cancellation/data-region disclosure |
| CF03-FR-004 | `CommissionPolicy`, `PlatformFinancialPolicy`, hard 0% Clinic/Marketplace commission |
| CF03-FR-005 | `DonationPolicy`, `DonationRecord`, weekly appeal policy and non-privilege contract |
| CF03-FR-006 | `PaymentIntent`, `CheckoutCommand`, `DonationIntentDraft`, idempotency and expiry |
| CF03-FR-007 | `ProviderRegistry`, `NullPaymentProvider`, hosted/tokenized provider interfaces |
| CF03-FR-008 | `ProviderWebhookVerifier`, raw HMAC signature, replay and uniqueness controls |
| CF03-FR-009 | `PaymentIntentTransition`, normalized trusted provider states and quarantine |
| CF03-FR-010 | `PaymentConfirmationService`, exact event/provider/intent/amount/ledger parity |
| CF03-FR-011 | `IdempotencyRecord`, unique persistence key and immutable request fingerprint |
| CF03-FR-012 | `LedgerTransaction`, `LedgerEntry`, `LedgerJournal`, double-entry by currency |
| CF03-FR-013 | Source-idempotent append-only journal and immutable transaction IDs |
| CF03-FR-014 | `FinancialAdjustment`, reversal references, evidence and three-person control |
| CF03-FR-015 | `Invoice`, immutable snapshot hash and complete invoice persistence |
| CF03-FR-016 | Receipt and invoice event contracts; donation receipt lifecycle |
| CF03-FR-017 | `RecurringConsent`, explicit unpreselected consent and terms hash |
| CF03-FR-018 | `Subscription`, renewal period, pause, grace, cancellation and resume |
| CF03-FR-019 | `DunningPolicy`, bounded retries, quiet hours and provider-outage distinction |
| CF03-FR-020 | Subscription past-due/grace facts; File 00 remains entitlement authority |
| CF03-FR-021 | Cancellation-at-period-end and resume lifecycle |
| CF03-FR-022 | `AiUsageAuthorization`, signed usage facts, exact rates and hard caps |
| CF03-FR-023 | Separate AI product/usage models retained dormant under current free policy |
| CF03-FR-024 | `RefundRequest`, eligibility review, denial, approval, execution and closure |
| CF03-FR-025 | Refund requester/reviewer/executor separation and capability catalogue |
| CF03-FR-026 | Provider-pending, uncertain, success/failure and reconciliation states |
| CF03-FR-027 | `ChargebackCase`, deadline, evidence, outcome, fee and ledger adjustment |
| CF03-FR-028 | `SettlementBatch`, `ReconciliationEngine`, provider/internal line parity |
| CF03-FR-029 | `FinancialActor`, `FinancialCapability`, `SeparationOfDutiesPolicy` |
| CF03-FR-030 | `AuditEnvelope`, `AuditChain`, purpose/action/outcome/trace and hash chain |
| CF03-FR-031 | `SecureExportJob`, allowlisted fields/filters, encrypted artifact, expiry |
| CF03-FR-032 | `FinancePeriod`, exception review, dual close, lock and controlled reopen |
| CF03-FR-033 | `IncidentControl`, `NullPaymentProvider`, fail-closed REST mutations and kill switches |
| CF03-FR-034 | `RestoreReconciliation`, backup count/hash parity and duplicate-event detection |

## Cross-cutting plan completion

- **Canonical ownership:** CF-03 owns financial truth; File 00 owns entitlement; File 20 owns global shell; File 25 owns visual presentation; File 24 owns assurance; File 19 owns notification delivery.
- **Routes:** canonical route catalogue defines pricing, checkout, billing, donation, refunds, admin finance, pricing administration and versioned finance API ownership.
- **REST:** public policy/appeal contracts are readable; paid checkout and donation collection mutations return fail-closed states.
- **Schema:** version `2.0.0`, 28 additive owner tables, indexes, record versions, trace fields and no raw payment credentials.
- **Security:** exact money, HMAC verification, replay prevention, event uniqueness, capability/object/purpose boundaries, recent authentication, toxic-role rejection, bounded fraud review, minimized export and retention/legal hold.
- **Privacy:** no PAN/CVV/PIN/OTP/bank password/provider secret storage; guest prompt state limited to two approved first-party keys.
- **Resilience:** idempotency, outbox leases/retries/dead-letter state, settlement reconciliation, period locks, backup parity and provider replay checks.
- **Packaging:** deterministic single-root WordPress package and SHA-256 record.
- **Uninstall:** financial/audit data is not destructively purged by ordinary plugin uninstall.

## Review doctrine

### Round 1

Completed the missing aggregates, workflows, schema, routes, security controls and plan-level tests. GitHub Actions identified two invalid test fixtures; event version validation was corrected and all tests passed.

### Round 2 — fresh adversarial review

Identified and corrected:

1. a boolean bypass that could have activated paid products despite the current Founder policy;
2. settlement acceptance from a signed but non-settlement provider event;
3. settlement using a balanced ledger whose amount did not match the payment intent;
4. overly permissive event-envelope naming.

The corrected source now requires a normalized `payment.settled` event and exact ledger amount/currency parity, and paid products cannot activate until a new source change-control replaces the current policy.

## Status boundary

- Source design and coding: complete for both written plans.
- Automated repository QA: required before final assertion on the exact head.
- Provider/legal/PCI/staging/browser/accessibility/operations/Founder live approval: external and unresolved.
- Live collection: disabled.
- PR state: Draft until cross-file and external acceptance.
