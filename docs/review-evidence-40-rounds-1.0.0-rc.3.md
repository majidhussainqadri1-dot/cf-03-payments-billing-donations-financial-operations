# CF-03 Forty-Round Review and Defect-Correction Evidence

## Scope and governing boundary

This record covers forty separate review → defect correction cycles on CF-03 `1.0.0-rc.3`, against:

1. Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0;
2. CF-03 Payments, Billing, Donations and Financial Operations Complete Master Plan v1.0;
3. Founder Decision `SSH-FIN-2026-08-04-01`.

The result is repository-source hardening. It does not claim provider, legal, tax, PCI, Hostinger staging, browser, accessibility, operational or Live acceptance. Paid products remain dormant and Live collection remains fail closed.

## Forty review/fix cycles

| Round | Review focus | Defect correction |
|---:|---|---|
| 01 | Architecture and release identity | Corrected stale release terminology and aligned architecture with `1.0.0-rc.3`, schema `2.0.0` and current free-platform scope. |
| 02 | Activation evidence schema | Rejected unknown top-level, approval and provider evidence fields. |
| 03 | Evidence freshness | Required bounded expiry for volatile approvals and provider validation evidence. |
| 04 | Activation separation of duties | Enforced distinct actors across critical Founder, security, staging, rollback and PCI approvals. |
| 05 | Webhook event-ID poisoning | Moved atomic event reservation after signature, replay and payload validation. |
| 06 | Provider event chronology | Added immutable provider-event occurrence time to trusted evidence. |
| 07 | Webhook normalization boundary | Required exact normalized fields, uppercase currency and valid `occurred_at`; rejected extra fields. |
| 08 | Expired payment intent mutation | Blocked provider-reference attachment after intent expiry. |
| 09 | Settlement validity window | Required provider settlement occurrence within the payment-intent creation/expiry window. |
| 10 | Refund state bypass | Removed direct generic `SETTLED → REFUNDED` intent transition; refund truth must follow the refund workflow. |
| 11 | Refund executor identity | Rejected empty or malformed executor references. |
| 12 | Refund reviewer integrity | Prevented reviewer replacement during denial after eligibility review began. |
| 13 | Cumulative partial refunds | Added `RefundBalance` with optimistic versioning, idempotent refund IDs and original-amount ceiling. |
| 14 | Trusted donation fact binding | Retained provider, intent, amount, event ID and occurrence-time binding in trusted donation facts. |
| 15 | Donation aggregate binding | Bound each donation record immutably to one provider and payment intent before donor confirmation. |
| 16 | Recurring consent revocation | Added immutable revocation time, active-at checks and renewal rejection after revocation. |
| 17 | Cancelled subscription resurrection | Prohibited resume after cancellation; a new consent and subscription are required. |
| 18 | Subscription clock determinism | Replaced hidden system-clock use with explicit decision time for pause validation. |
| 19 | Subscription period bounds | Required period end after activation and within a bounded two-year window. |
| 20 | Dunning after consent withdrawal | Prohibited retries after consent revocation or subscription cancellation. |
| 21 | Chargeback chronology | Added opening time and bounded response-deadline validation. |
| 22 | Chargeback outcome authority | Required provider acceptance before recording won/lost outcome. |
| 23 | Fraud-score inflation | Rejected duplicate signal codes and duplicate evidence references. |
| 24 | Fraud hold and appeal independence | Bounded holds to seven days and required a fresh appeal reviewer. |
| 25 | Reconciliation exception integrity | Enforced exact shape, safe identifiers, uppercase currency, nonnegative values and uniqueness. |
| 26 | Settlement-line integrity | Enforced strict line shape, identifiers, types, currency, positive payment lines and overflow-safe totals. |
| 27 | Legacy finance-close bypass | Removed the legacy single-actor close method. |
| 28 | Material-exception bypass | Removed accepted-risk string bypass; material exceptions must be resolved before close. |
| 29 | Export filter safety | Validated allowlisted filter types, dates, identifiers and non-inverted ranges. |
| 30 | Export object traversal | Restricted artifacts to approved opaque/vault references and rejected traversal/control characters. |
| 31 | Legacy export hardening | Bounded rows/fields/values, rejected protected fields and neutralized spreadsheet formulas. |
| 32 | Audit metadata minimization | Expanded credential-field denial and bounded depth, item count, keys and string sizes. |
| 33 | Financial event payload safety | Bounded payload field count, types and sizes; rejected sensitive credential fields. |
| 34 | Outbox lease chronology | Added attempt time, bounded attempts, chronological failure/retry and delivery validation. |
| 35 | Backup manifest integrity | Required bounded nonempty manifests, canonical component names, exact shape and SHA-256 hashes. |
| 36 | Invoice integrity | Validated status, item shape, legal name, item count and overflow-safe totals. |
| 37 | Hosted checkout transport | Required allowlisted DNS hostname, HTTPS, port 443, no credentials/fragments and bounded expiry. |
| 38 | Return-path redirect safety | Rejected encoded slashes/backslashes, controls, fragments, traversal and non-canonical same-origin paths. |
| 39 | WordPress REST and metadata authorization | Required canonical USD integer minor units, strict recurring boolean, dedicated `sabri_manage_finance` capability and owner-scoped prompt metadata. |
| 40 | Migration and activation publication | Detected checksum drift, required durable migration evidence, verified every table after `dbDelta`, and published schema version only after complete verification. |

## Automated regression evidence

The review corrections are protected by seven suites:

| Suite | Tests per PHP version |
|---|---:|
| Foundation | 12 |
| Product/evidence | 27 |
| Source candidate | 25 |
| Free-platform/donation policy | 26 |
| Dual-plan completion | 58 |
| Second adversarial integrity | 10 |
| Forty-round regression | 40 |
| **Total** | **198** |

The matrix runs on PHP 8.1, 8.2 and 8.3: **594 domain-test executions**. It additionally performs complete PHP syntax checks, JSON manifest validation, prohibited credential-material scanning, governing source assertions and deterministic package parity.

The code-review completion head `0ac5f2fa739ed205bb5f25451e02f0bf8e78ba53` passed GitHub Actions run `357`, including all three PHP jobs and the deterministic package job. Later documentation-only commits must also pass the same matrix before the PR evidence is considered final.

## Final safety boundary

- PR remains Draft.
- `main`, Hostinger staging and Live are not changed by this review.
- No real provider adapter, Live webhook or collection route is enabled.
- File 00 remains entitlement authority.
- Clinic and Marketplace commission remains 0%.
- Optional donation grants no access, visibility, ranking, verification or service privilege.
