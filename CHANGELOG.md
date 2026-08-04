# Changelog

## 1.0.0-rc.3 — Forty-Round Reviewed Dual-Plan Financial Source Candidate

- Completed repository source traceability for Definitive Master Plan v3.0 and CF-03 Complete Master Plan v1.0, covering `CF03-FR-001` through `CF03-FR-034`.
- Preserved Founder Decision `SSH-FIN-2026-08-04-01`: paid products remain dormant/non-collectible, core services free, donation optional, commission 0% and Live collection disabled.
- Added versioned product/price governance, complete payment intents, provider registry and fail-closed null provider.
- Hardened raw HMAC webhook verification with exact normalized payloads, `occurred_at`, replay checks and event-ID reservation only after trust validation.
- Required payment settlement to match normalized event type, provider, intent, amount, currency, intent-validity window and exact balanced ledger posting.
- Added append-only source-idempotent ledger, cumulative partial-refund ceilings and controlled three-person financial adjustments.
- Completed explicit recurring consent and revocation, subscription period/pause/cancellation controls, bounded dunning and signed AI usage caps.
- Completed refund uncertainty/reconciliation, chargeback opening/deadline/evidence/acceptance/outcome flow, donation binding, settlement imports and reconciliation.
- Removed the legacy single-actor finance close and prohibited accepted-risk bypass of material reconciliation exceptions.
- Hardened bounded fraud review, independent appeals, secure exports, retention/legal holds, restore replay reconciliation, event envelopes, outbox chronology and audit minimization.
- Hardened invoice totals/status, hosted-checkout transport, return-path redirect safety, REST donation inputs and dedicated finance authorization.
- Added checksum-drift detection, durable migration records, post-`dbDelta` verification of all 28 owner tables and fail-closed schema publication.
- Completed **40 separate review → defect-correction cycles**, recorded in `docs/review-evidence-40-rounds-1.0.0-rc.3.md`.
- Added 40 dedicated regression tests. The complete matrix now runs 198 tests per PHP version and 594 tests across PHP 8.1, 8.2 and 8.3.
- Preserved deterministic `1.0.0-rc.3` WordPress package construction and parity verification.

## 1.0.0-rc.2 — Free Platform and Weekly Donation Appeal Amendment

- Implemented Founder Decision `SSH-FIN-2026-08-04-01` as the governing financial policy.
- Suspended and made non-collectible every membership, education, AI and platform-service charge while preserving previous product/price definitions as dormant historical records.
- Added a hard checkout policy that permits only voluntary donation products under the current decision.
- Added exact suggested donation amounts USD 10, USD 14 and USD 50 plus positive custom USD amounts; no amount or monthly recurrence is preselected.
- Added logged-in server-side donation prompt state and privacy-safe guest storage contract.
- Added seven-day prompt cap, seven-day snooze actions, thirty-day post-donation suppression and active-monthly-donor suppression.
- Added sensitive-context, engagement, page-view and checkout-failure safeguards.
- Added bilingual Urdu/English appeal copy and a presentation-neutral File 20/File 25 integration contract.
- Added consent-safe donation intent and hosted donation provider interfaces; live collection remains unavailable while provider/external gates are incomplete.
- Restricted donation-completion prompt state to trusted, replay-safe provider evidence; user actions cannot forge donor completion/monthly status.

## 1.0.0-rc.1 — Complete Conditional Source Candidate

- Implemented source-level coverage for CF03-FR-001 through CF03-FR-034.
- Added pre-purchase disclosure, invoices, subscriptions, refunds, reconciliation, period close, secure export, incident controls and backup parity objects.
- Added provider-neutral payment/refund/settlement contract and durable repository contract.
- Added additive owner-table schema and idempotency-aware migration runner.
- Added event catalogue, outbox/audit persistence design and File 00 fact-only boundary.
- Kept all payment runtime routes and provider operations fail-closed pending external acceptance gates.

## 0.2.0 — Product, Price, Evidence and Contract Foundation

- Added versioned hash-bound activation evidence, products, immutable price snapshots, checkout/idempotency/provider-evidence contracts and File 00 financial facts.

## 0.1.0 — Foundation

- Added fail-closed bootstrap, exact money, 0% commission, donation non-privilege, intent transitions and balanced-ledger value objects.
