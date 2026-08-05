# Changelog

## 1.2.0-rc.3 — Fourth Forty-Round Transactional and Retry-Safety Review

- Performed a fresh fourth sequence of forty review → correction → fresh-review rounds on `1.2.0-rc.2`.
- Made donation retry fingerprints independent of volatile request timestamps while preserving actor, provider, intent, amount, currency, consent and idempotency binding.
- Rejected expired hosted-checkout replay and revalidated idempotency actor/scope plus dependent donation/consent parity.
- Made duplicate provider-event IDs require identical signed evidence and added event/intent chronology checks.
- Required canonical donation and recurring-consent parity before settlement; accounting periods now follow provider occurrence time.
- Rejected zero refunds, reserved cumulative requested/approved/pending/uncertain/succeeded/closed balances and made refund identifiers exact-term idempotent.
- Added durable refund and recurring-management checkpoints before provider calls; uncertain provider outcomes remain explicitly reconcilable.
- Removed provider confirmation references from public recurring/refund results.
- Made WordPress database query failures fail closed instead of appearing as empty results.
- Aligned memory-repository semantics with WordPress, including nested rollback-only behavior and no implicit version increment for bounded updates.
- Protected ledger transactions, ledger entries and audit records from generic update/delete operations and validated canonical record identifiers.
- Rejected audit event-ID reuse with changed immutable evidence.
- Bound finance-override downloads to the actual authenticated finance actor.
- Added a global bounded request guard for every CF-03 REST mutation while retaining the larger webhook-specific ceiling.
- Added `tests/run-review-40-fourth.php` and `docs/review-evidence-40-rounds-fourth-1.2.0-rc.3.md`.
- Updated the matrix to 444 tests per PHP version and 1,332 executions across PHP 8.1–8.3.
- Live provider, legal, PCI, staging, independent-security and Founder activation gates remain unresolved and fail closed.

## 1.2.0-rc.2 — Third Forty-Round Runtime, Privacy and Integrity Review

- Required trusted webhook readiness, provider-registry parity, exact schema validation and fail-closed incident/runtime parsing.
- Added provider-safe identity minimization, durable checkout recovery, public redaction, repository hardening, snapshot integrity and the third forty-round suite.
- Reached 404 tests per PHP version and 1,212 executions across PHP 8.1–8.3.

## 1.2.0-rc.1 — Complete Runtime Source and Second Forty-Round Review

- Added durable WordPress repository and end-to-end financial runtime services.
- Extended the schema to `3.2.0` while retaining 31 canonical tables.
- Reached 364 tests per PHP version and 1,092 executions across PHP 8.1–8.3.

## 1.1.0-rc.3 — Actual Three-Plan Audit and Recovered-Directive Conflict Lock

- Re-audited against the three governing plans and locked `RCD-022` as superseded.

## 1.1.0-rc.2 — Three-Plan Harmonization and Universal Financial Download Contract

- Added eligible financial-download contracts and preserved cross-file ownership boundaries.

## 1.1.0-rc.1 — Final Founder Donation and Financial Transparency Policy

- Codified Founder ownership, non-Trust status, no fixed core-service fee, voluntary donation and 0% commission.

## 1.0.0-rc.3 — First Forty-Round Dual-Plan Source Candidate

- Completed source traceability, forty regression tests and deterministic packaging.

## 0.2.0 — Product, Price, Evidence and Contract Foundation

- Added immutable product/price snapshots, idempotency and provider-evidence contracts.

## 0.1.0 — Foundation

- Added exact money, 0% commission, donation non-privilege, intent transitions and balanced-ledger value objects.
