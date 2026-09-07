# Changelog

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
