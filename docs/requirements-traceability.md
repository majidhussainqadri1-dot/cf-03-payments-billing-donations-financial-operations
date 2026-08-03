# Requirements Traceability — Foundation 0.2.0

This matrix records implemented code-level foundation evidence only. It does not mark the complete CF-03 plan as coded, staged, activated, or operational.

| Requirement | Foundation implementation | Evidence | Status |
|---|---|---|---|
| CF03-FR-001 — Financial product registry | `FinancialProduct`, `ProductKind`, `BillingType` enforce stable IDs, owners, entitlement mapping, product separation, policies, availability and approval | product tests | Partial; durable registry/admin workflow pending |
| CF03-FR-002 — Price versioning | `PriceVersion` and `PriceCatalog` enforce effective dates, region/currency, tax mode, policy parity, approval, overlap rejection, historical snapshots | price tests | Partial; persistence, cohort migration and renewal notices pending |
| CF03-FR-003 — Pre-purchase disclosure | Checkout contract carries product, price version, amount, currency and immutable snapshot hash | checkout tests | Partial; accessible UI/provider/terms disclosure pending |
| CF03-FR-004 — Zero-commission law | `CommissionPolicy` rejects non-zero Clinic/Marketplace basis points | commission tests | Implemented foundation invariant |
| CF03-FR-005 — Donation non-privilege | Donation is voluntary, has no entitlement mapping/default amount/default recurrence, and rejects privilege signals | donation tests | Implemented foundation invariant |
| CF03-FR-006 — Hosted checkout | Provider-neutral hosted-checkout interface, safe HTTPS URL, explicit host allowlist | hosted reference tests | Partial; provider implementation and PCI evidence pending |
| CF03-FR-007 — Payment intent lifecycle | State enum/transition validator; server-resolved checkout command; idempotency key and price snapshot | transition/checkout/idempotency tests | Partial; durable intent persistence pending |
| CF03-FR-008 — Signed webhook verification | `ProviderEvidence` requires signature, key version, timestamp, replay window, raw-body hash and event uniqueness | provider evidence tests | Partial; real signature adapter and durable replay store pending |
| CF03-FR-009 — Authorization/capture status | Verified event mapping; unknown event types become `QUARANTINED` | provider mapping tests | Partial; provider-specific mapping registry pending |
| CF03-FR-010 — Payment confirmation | Browser-style direct success rejected; provider/intent/amount parity required; File 00 receives facts only | transition/provider/File 00 tests | Partial; durable ledger/outbox required before completion |
| CF03-FR-011 — Provider abstraction/exit | `HostedPaymentProvider` port and provider-neutral evidence/reference objects | contract compilation/tests | Partial; capability/exit adapters and drills pending |
| CF03-FR-012 — Immutable balanced ledger | Immutable value objects; balance enforced independently by currency; no mutation API | ledger tests | Partial; storage/reversal/outbox pending |
| CF03-FR-017 — Currency and rounding | Integer minor units and canonical decimal parsing; floating-point money excluded | money/idempotency tests | Implemented foundation invariant |
| CF03-FR-018 / 021 — Subscription facts and grace coordination | File 00 fact contract contains payment/refund/cancellation facts but no access command | File 00 contract tests | Partial; subscription runtime and out-of-order reconciliation pending |
| CF03-FR-030 — Financial data minimization | Provider request omits canonical user reference; audit envelopes reject sensitive keys; no card fields | privacy tests | Partial; automated repository/DB/log/export scanners pending |
| CF03-FR-033 — Provider/key incident | Runtime and operational routes absent; unknown provider state quarantined; host allowlist and evidence gate | security tests | Partial; kill switches/key rotation/runbook drill pending |
| Activation gates | Versioned, expiring, distinct evidence blocks plus hash-bound record and runtime constant | activation tests/schema | Implemented governance foundation |

## Still not implemented

Durable product/price tables, checkout REST route, provider adapter, signed webhook receiver, idempotency storage, payment-intent storage, ledger persistence, invoices/receipts, settlements, reconciliation, subscriptions, File 00 outbox delivery, AI usage charging, refunds, chargebacks, fraud review, finance exports, period close, database migration execution, provider sandbox acceptance, Hostinger staging, restore/rollback drill, independent security testing, legal/tax/accounting sign-off, and operational staffing remain pending.
