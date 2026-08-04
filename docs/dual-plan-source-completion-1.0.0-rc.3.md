# CF-03 Dual-Plan Source Completion — 1.0.0-rc.3

## Governing basis

1. Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0.
2. CF-03 Payments, Billing, Donations and Financial Operations Conditional Complete Master Plan 2026 v1.0.
3. Founder Decision `SSH-FIN-2026-08-04-01`, which keeps the platform free and all paid products dormant/non-collectible.

This ledger means **repository source completion**, not provider, legal, staging, Live or operational acceptance.

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
| CF03-FR-008 | `ProviderWebhookVerifier`, raw HMAC signature, exact normalized payload, replay and uniqueness controls |
| CF03-FR-009 | `PaymentIntentTransition`, normalized trusted provider states and quarantine |
| CF03-FR-010 | `PaymentConfirmationService`, event chronology and exact provider/intent/amount/ledger parity |
| CF03-FR-011 | `IdempotencyRecord`, unique persistence key and immutable request fingerprint |
| CF03-FR-012 | `LedgerTransaction`, `LedgerEntry`, `LedgerJournal`, double-entry by currency |
| CF03-FR-013 | Source-idempotent append-only journal and immutable transaction IDs |
| CF03-FR-014 | `FinancialAdjustment`, reversal references, evidence and three-person control |
| CF03-FR-015 | `Invoice`, immutable snapshot hash, bounded items and overflow-safe totals |
| CF03-FR-016 | Receipt and invoice event contracts; donation receipt lifecycle |
| CF03-FR-017 | `RecurringConsent`, explicit unpreselected consent, terms hash and revocation |
| CF03-FR-018 | `Subscription`, bounded renewal period, pause, grace, cancellation and resume |
| CF03-FR-019 | `DunningPolicy`, bounded retries, quiet hours, consent/cancellation and provider-outage distinction |
| CF03-FR-020 | Subscription past-due/grace facts; File 00 remains entitlement authority |
| CF03-FR-021 | Cancellation-at-period-end and consent-safe resume lifecycle |
| CF03-FR-022 | `AiUsageAuthorization`, signed usage facts, exact rates and hard caps |
| CF03-FR-023 | Separate AI product/usage models retained dormant under current free policy |
| CF03-FR-024 | `RefundRequest`, eligibility review, denial, approval, execution and closure |
| CF03-FR-025 | Refund requester/reviewer/executor separation, `RefundBalance` and capability catalogue |
| CF03-FR-026 | Provider-pending, uncertain, success/failure and reconciliation states |
| CF03-FR-027 | `ChargebackCase`, opening/deadline, evidence acceptance, outcome, fee and ledger adjustment |
| CF03-FR-028 | `SettlementBatch`, strict line integrity and `ReconciliationEngine` provider/internal parity |
| CF03-FR-029 | `FinancialActor`, `FinancialCapability`, `SeparationOfDutiesPolicy` |
| CF03-FR-030 | `AuditEnvelope`, `AuditChain`, minimized bounded metadata and hash chain |
| CF03-FR-031 | `SecureExportJob`, validated fields/filters, encrypted artifact reference and expiry |
| CF03-FR-032 | `FinancePeriod`, exception review, dual close, lock and controlled reopen without material-exception bypass |
| CF03-FR-033 | `IncidentControl`, `NullPaymentProvider`, fail-closed REST mutations and kill switches |
| CF03-FR-034 | `RestoreReconciliation`, backup count/hash parity, migration checksum drift and duplicate-event detection |

## Cross-cutting plan completion

- **Canonical ownership:** CF-03 owns financial truth; File 00 owns entitlement; File 20 owns global shell; File 25 owns visual presentation; File 24 owns assurance; File 19 owns notification delivery.
- **Routes:** canonical route catalogue defines pricing, checkout, billing, donation, refunds, admin finance, pricing administration and versioned finance API ownership.
- **REST:** public policy/appeal contracts are readable; paid checkout and donation collection mutations return fail-closed states; admin access requires `sabri_manage_finance`.
- **Schema:** version `2.0.0`, 28 additive owner tables, indexes, record versions, trace fields, checksum evidence and post-install table verification.
- **Security:** exact money, HMAC verification, replay prevention, post-validation event reservation, event chronology, capability/object/purpose boundaries, recent authentication, toxic-role rejection, bounded fraud review, minimized export and retention/legal hold.
- **Privacy:** no PAN/CVV/PIN/OTP/bank password/provider secret storage; guest prompt state is limited to two approved first-party keys and logged-in metadata is owner/finance scoped.
- **Resilience:** idempotency, outbox lease chronology/retries/dead-letter state, settlement reconciliation, period locks, backup parity, migration checksum drift detection and provider replay checks.
- **Packaging:** deterministic single-root WordPress package and SHA-256 record.
- **Uninstall:** financial/audit data is not destructively purged by ordinary plugin uninstall.

## Review doctrine and completion evidence

The source underwent **forty separate review → defect-correction cycles**. Each pass examined a distinct integrity surface and every discovered defect was corrected before the next pass. Major hardening areas included:

- activation evidence schema, expiry and separation of duties;
- webhook signature/replay/event reservation and occurrence chronology;
- payment-intent expiry and settlement-window integrity;
- cumulative refunds and donation fact/intent binding;
- consent revocation, subscription cancellation and dunning cessation;
- chargeback chronology/provider acceptance and independent fraud appeals;
- settlement/reconciliation shape, material close blocking and removal of the legacy single-actor close;
- export filtering/object references, audit/event minimization and outbox chronology;
- invoice, checkout transport, same-origin return paths, WordPress capabilities and schema verification.

The exact round-by-round ledger is `docs/review-evidence-40-rounds-1.0.0-rc.3.md`.

## Automated QA

Seven suites run on each supported PHP version:

- Foundation: 12
- Product/evidence: 27
- Source candidate: 25
- Free-platform/donation policy: 26
- Dual-plan completion: 58
- Second adversarial integrity: 10
- Forty-round regression: 40

Total: **198 tests per PHP version**, or **594 domain-test executions** across PHP 8.1, 8.2 and 8.3. CI additionally performs complete syntax checking, manifest validation, prohibited credential-material scanning, governing source assertions and deterministic package parity.

The code-review completion head `0ac5f2fa739ed205bb5f25451e02f0bf8e78ba53` passed GitHub Actions run `357`. Documentation commits are required to pass the same matrix before the PR record is finalized.

## Status boundary

- Source design, coding and forty review/fix cycles: complete for both written plans.
- Provider/legal/PCI/staging/browser/accessibility/operations/Founder Live approval: external and unresolved.
- Live collection: disabled and fail closed.
- PR state: Draft until cross-file and external acceptance.
- `main`, Hostinger staging and Live remain unchanged by this source review.
