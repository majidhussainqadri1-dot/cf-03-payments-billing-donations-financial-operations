# Requirements Traceability — Foundation 0.1.0

This matrix records only implemented foundation evidence. It does not mark the complete CF-03 plan as coded.

| Requirement | Foundation implementation | Evidence | Status |
|---|---|---|---|
| CF03-FR-004 — Zero-commission law | `CommissionPolicy` rejects non-zero Clinic/Marketplace basis points | `tests/run.php` | Implemented foundation invariant |
| CF03-FR-005 — Donation non-privilege | `DonationPolicy` has no default amount/recurrence and rejects privilege signals | `tests/run.php` | Implemented foundation invariant |
| CF03-FR-007 — Payment intent lifecycle | Explicit state enum and allowed-transition validator | `PaymentIntentState`, `PaymentIntentTransition` | Partial; persistence/idempotency/provider evidence not yet implemented |
| CF03-FR-009 — Authorization/capture status | Unknown facts can be represented by quarantine and cannot jump from created to settled | transition tests | Partial |
| CF03-FR-010 — Trusted payment confirmation | Direct `created → settled` transition is rejected | transition test | Partial; webhook/provider evidence not yet implemented |
| CF03-FR-012 — Immutable balanced ledger | Immutable value objects; balance enforced by currency; no mutation API | ledger tests | Partial; persistence/reversal/outbox not yet implemented |
| CF03-FR-017 — Currency and rounding | Exact integer minor units and canonical decimal parsing; no floats | money tests | Implemented foundation invariant |
| CF03-FR-033 — Provider/key incident | Runtime gate is fail-closed and operational endpoints are absent | `ActivationGate`, Site Health status | Partial |
| Activation gates | Founder, legal/tax/accounting, PCI, security, hosted/tokenized provider mode, staging, rollback | activation tests | Implemented governance gate |

## Not yet implemented

Product/price persistence, checkout sessions, provider adapters, signed webhooks, durable idempotency, ledger storage, invoice rendering, settlement import, reconciliation, subscriptions, File 00 entitlement events, AI usage charging, refund execution, chargebacks, fraud review, finance exports, period close, migrations, provider sandbox acceptance, staging, restore, rollback, security testing, and operational staffing remain pending future phases.
