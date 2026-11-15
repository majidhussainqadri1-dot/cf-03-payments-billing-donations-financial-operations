# Changelog

## 1.4.0-rc.3 — V12.3 Source Consolidation and Financial Safety Integration (Draft, NOT LIVE)

- Retains the cross-branch PR #9/#14 integration and 21 current PHP governing/adversarial suites on PHP 8.1–8.3; no source correction is claimed deployed.
- Closes the previously unimplemented **native pre-provider local request-rate source boundary** with a Founder-governed policy, database-unique slot claims, masked identity HMAC, idempotent replay, 429 Retry-After and bounded privacy cleanup. A policy must be deliberately approved; no operational quota is invented.
- Replaces non-durable WordPress `do_action` listener success with strict File 01 v2.0.1+ durable event-store acceptance, versioned `CF03.*.v1` event names, opaque aggregate IDs, restricted aggregate-only payloads, exact event ID deduplication and separate File 19 downstream acceptance.
- Adds a **separate isolated MariaDB 10.11 CI job** exercising actual InnoDB row-lock contention between two database connections, MyISAM failure and financial rollback. Production database remains unknown.
- Supersedes the earlier `1.4.0-rc.2` source package identity; the schema remains `4.0.0` / 27 canonical tables. Future40 remains coded but disabled. The only allowed current donation type is voluntary one-time, with no paid AI/core or donor privileges and 0% Clinic/Marketplace commission.
- External blockers: live exact deployed plugin/DB/migrations/WAF, provider/key/PCI/accounting/legal approvals, approved rate policy, File01 and File19 consumer staging, human independent security, browser/mobile RTL and accessibility, restore/rollback, Founder sign-off and parity-checked live retest. Keep Draft.

## V12.3 source consolidation — 2026-11-15 (Isolated Candidate / NOT DEPLOYED)

- Preserved PR #14's canonical donation-catalogue authority-marker and signed webhook evidence preflight safeguards, including their adversarial regressions.
- Integrated PR #9's transaction-aware InnoDB refund locking/500-row fail-closed guard, oldest-due financial outbox ordering, approved pre-donation provider disclosure, conditional CF-05 published aggregate DTO and six independent omitted test suites.
- Composer and GitHub Actions now require the same 19 governed tests on PHP 8.1/8.2/8.3, alongside reproducible packaging, source parity and credential/material scans. Exact branch-HEAD outcomes are checked separately and never inferred from older jobs.
- Plan mapping: V12.3 CF03-FR-001..034, CF03-CEN-01..10, CF03-NJ-01..06; Future40 stays deactivated. No payment collection, staging, database migration or production deployment was performed.
- Outstanding: real MySQL concurrency/engine verification, provider/key/legal/PCI review, per-actor pre-provider rate/velocity/WAF policy evidence, durable consumer acknowledgements, independent security, cross-file integration, real-browser RTL/accessibility, restore/rollback, founder approval, live deployed artifact/schema/migration/version parity and Live re-tests.

- Second source review: fixed an outbox false-delivered result on an unpersisted or stolen-lease acknowledgment; update and recovery now require CAS of the worker's exact lease. Added adversarial lost-ACK and renewed-lease tests. The existing 22-suite matrix includes the new test.
- Corrected two donation UI issues: duplicate ARIA IDs across repeated shortcodes and a text input mistakenly styled as number. Real-browser and assistive-technology acceptance remains pending.

- Added `manifests/cf03-v123-requirements.json` and executable V12.3 requirement matrix proving the full 34+10+6+40=90 entry inventory with honest independent staging/Live status. Composer/CI source suite count is now **21**, plus the separate MariaDB service job.

- Added the Hostinger read-only reality-freeze operator script and source-only regression checks, with a guarded handoff specifying independently captured deployed hashes, DB versions/migrations and strict prohibition on financial activation before approval. Active Composer/CI source suites: 22, plus separate real-MariaDB CI job.

## 1.4.0-rc.2 — Corrective Plan-to-Code Review (Source Candidate, Not Live)

**Fifth independent audit continuation:** CF03-FR-024 refund-balance safety now serializes reservation per canonical payment intent with in-transaction `FOR UPDATE` only on read-only-verified InnoDB, fails closed if >=500 refund records are found, and adds dedicated 40 independent seeded financial review scenarios (360 assertions). Newly supplied CF05-FR-018 adds an **inactive, aggregate-only, verified published snapshot** DTO with no raw ledger/donor/receipt data or CF-05 auto-activation. Totals as of this corrective candidate: **10 confirmed source defects patched on this Draft branch**, **1 QA orchestration defect patched**, **1 conditional cross-file contract supplied**; deployment and real MySQL concurrency remain unverified. See `docs/review-40-refund-cf05-cross-file-2026-10-21.md`.


- Seven verified source/version defects corrected on the isolated review branch: approved one-time catalogue preflight; crashed outbox lease recovery; merchant-scoped provider idempotency; no-subscriber event-delivery guard; Sabri Green `#087A4E`; transactional one-time catalogue provisioning after schema verification; versioned upgrade trigger from `1.4.0-rc.1` to `1.4.0-rc.2`.
- Added 40 separately named governing compliance checks and five focused regression groups (87 assertions in total), enforced in PHP 8.1, 8.2 and 8.3 CI.
- Preserved 27 active tables / schema `4.0.0`, free core/AI/education, zero Marketplace/Clinic commission, seven-day appeal silence, voluntary one-time donation, and inactive Future40. The Future40 amendment's original approved software target `1.4.0-rc.1` remains historical provenance, not today's source package identity.
- Updated deterministic package target to `cf-03-payments-billing-donations-financial-operations-1.4.0-rc.2.zip`.
- Follow-up independent 40-lens DOCX-grounded cross-file review fixed due-time fair outbox dispatch (expired leases, pending/retry starvation) and introduced approved provider/fee/tax/refund/support/data-use disclosure gating before donation checkout. Tests cover head-of-line starvation and fail-closed disclosure; `composer test` now includes all twelve active CI suites with a parity guard. Source corrections total **9**, plus **1** QA orchestration correction; not Live-deployed.
- **No Live assertion:** the PR remains Draft. External provider and native consumer integration, security/legal/PCI, staging, DB migration, backup/restore, Founder sign-off and exact deployed parity are pending.

## 1.4.0-rc.1 — Future Expansion Pack 40

- Added governing amendment `CF03-FUTURE40-2026-09-08` with exactly **40** coded future financial-governance capabilities.
- Added executable feature registry, constitutional lock and four domain services covering Donation Experience, Provider/Operations Intelligence, Governance/Privacy, Integration/Compliance/Sustainability.
- Added Donation Purpose Funds, reminder preferences including Never Remind Me, donation privacy controls, receipt vault/authenticity, aggregate transparency/use-of-funds and multi-currency readiness contracts.
- Added provider selection/health/failover, webhook forensics, uncertain-transaction handling, reconciliation queue/confidence, period-close gates, dual approval, refund preview/SLA and chargeback evidence builder.
- Added financial privacy center, user/accountant exports, immutable audit packages, configuration history, policy simulation, sandbox, deployment-readiness, kill-switch and restore-verification contracts.
- Added privacy-preserving analytics, accessibility-first UX, CF-02 support bridge, notification preferences, internal event explorer, jurisdiction registry, tax/legal disclosure registry, conditional Sharia classification, conditional waqf/grant sustainability and Founder Financial Command Center.
- `FX-38` and `FX-39` are **coded but conditional/fail-closed**; they do not activate Zakat/Waqf/Grant financial flows without their specific Founder, Sharia/legal/accounting change-control evidence.
- Code presence does not activate any Future Expansion capability, does not change the current one-time donation route and does not infer Staging/Live state.
- Active schema remains `4.0.0` / 27 canonical tables because this release adds future contracts and deterministic services without activating new persistent financial truth.
- Added `manifests/cf03-future-expansion-40.json`, `manifests/cf03-release-1.4.0.json`, the written plan appendix and `tests/run-future-expansion-40.php`.
- Deterministic package target changed to `cf-03-payments-billing-donations-financial-operations-1.4.0-rc.1.zip`.

## 1.3.0-rc.1 — New Governing Plans: Free Core + One-Time Donation Reconciliation

- Rebased CF-03 source governance onto the newly supplied `SSH-PMP-2026-v3.0` central plan and **CF-03 Conditional Complete Master Plan 2026 v1.0**.
- Replaced the superseded 30-day/calendar-month donation-appeal rule with a minimum **7-day** interval.
- Made voluntary donations **one-time only**: no recurring checkbox, recurring mandate, automatic repeat charge, renewal, grace or dunning runtime.
- Required explicit one-time consent before hosted checkout; no amount is preselected.
- Retired paid AI metering/billing: approved Sabri Classical Homeopathy AI is a free core capability and cannot be finance- or donor-gated.
- Retired paid subscription runtime and removed subscriptions from user finance-history projections.
- Added compatibility tombstones so stale recurring/subscription/paid-AI callers fail closed instead of silently reviving superseded business rules.
- Introduced active schema `4.0.0`; removed `recurring_consents`, `subscriptions`, `usage_authorizations` and `usage_facts` from the active canonical schema while preserving bounded historical evidence where an upgraded installation already contains legacy physical tables.
- Updated donation checkout, webhook settlement/refund, receipts and outbox events to require `donation.one_time`, recurring=false and no-access-event semantics.
- Quarantine signed provider events tied to retired financial products for manual/provider reconciliation rather than mutating current financial truth.
- Limited the active product catalog to `donation.one_time`; legacy `donation.monthly` catalog records are retired during migration/seeding.
- Preserved 0% Clinic/Marketplace platform commission and strengthened donor/non-donor equality across access, ranking, support, education, AI, quota and feature availability.
- Rewrote public REST/UI/JavaScript contracts for explicit one-time donation only.
- Added new release/contract manifests and marked `1.2.0-rc.3` as historical/superseded evidence.
- Rebuilt deterministic packaging identity as `cf-03-payments-billing-donations-financial-operations-1.3.0-rc.1.zip`.
- Staging, provider, PCI, legal/tax/accounting, independent-security, browser/accessibility/performance, restore/rollback and Founder Live activation remain separate external gates.

## 1.2.0-rc.3 — Historical Fourth Forty-Round Transactional and Retry-Safety Review

> Historical evidence only. The 30-day/monthly/paid-capability assumptions in this release were superseded by the newly supplied governing plans and are not current acceptance law.

- Performed a fourth sequence of forty review/correction rounds on the then-current candidate.
- Hardened retry fingerprints, signed provider evidence, refund reservation, repository immutability and deterministic packaging.
- Reached 444 historical tests per PHP version and 1,332 historical executions across PHP 8.1–8.3.

## 1.2.0-rc.2 — Historical Third Runtime, Privacy and Integrity Review

- Required trusted webhook readiness, provider-registry parity, exact schema validation and fail-closed incident/runtime parsing.

## 1.2.0-rc.1 — Historical Complete Runtime Source Candidate

- Added durable WordPress repository and end-to-end financial runtime services under the then-active model.

## 1.1.0 and earlier — Historical foundation

- Product/price/evidence, idempotency, provider abstraction, money, immutable ledger, refund, transparency and governance foundations were established across earlier candidates.
