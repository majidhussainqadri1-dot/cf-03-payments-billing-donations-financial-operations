# Changelog

## 1.2.0-rc.2 — Third Forty-Round Runtime, Privacy and Integrity Review

- Performed a fresh third sequence of forty review → defect-correction rounds on the corrected `1.2.0-rc.1` candidate.
- Required trusted webhook readiness before donation checkout and public Live readiness.
- Bound configured provider identity to both donation-registry presence and payment-registry health.
- Added exact installed-schema validation and strict WordPress runtime-option parsing.
- Made corrupt incident persistence fail closed; blocked incident overwrite and checkout recovery without webhook recovery.
- Made unknown Donation Appeal contexts sensitive by default.
- Restricted prompt writes to six canonical fields and bound trusted donation facts to the prompt subject.
- Withheld canonical donor identity from provider adapters.
- Added a durable `provider_created` checkout checkpoint and provider-session recovery path.
- Redacted provider/session internals from public checkout responses and enforced idempotency header/body parity.
- Rejected guest financial operations when the first-party guest identity could not be persisted.
- Stripped sensitive headers before webhook forwarding.
- Added duplicate canonical-identity detection, nested rollback-only transaction semantics and stricter bounded mutations.
- Added additive plugin-upgrade execution and complete table/column/index/uniqueness verification.
- Bumped complete schema to `3.3.0`; added transparency snapshot SHA-256 and period/currency uniqueness.
- Added expense replay parity, paginated privacy export and explicit privacy failure/retention messages.
- Disabled the public donation form while collection is unavailable and added exact two-decimal browser money parsing.
- Added `tests/run-review-40-third.php` and `docs/review-evidence-40-rounds-third-1.2.0-rc.2.md`.
- Updated the matrix to 404 tests per PHP version and 1,212 executions across PHP 8.1–8.3.
- Live provider, legal, PCI, staging, independent-security and Founder activation gates remain unresolved and fail closed.

## 1.2.0-rc.1 — Complete Runtime Source and Second Forty-Round Review

- Added durable WordPress repository, checkout/webhook/refund/recurring/settlement/reconciliation/expense/transparency/export/incident runtime services.
- Extended the schema to `3.2.0` while retaining 31 canonical tables.
- Added a second forty-round review suite and corrected public privacy, request bounds, package hygiene, bilingual UI and accessibility defects.
- Reached 364 tests per PHP version and 1,092 executions across PHP 8.1–8.3.

## 1.1.0-rc.3 — Actual Three-Plan Audit and Recovered-Directive Conflict Lock

- Re-audited against `SSH-PMP-2026-v3.0`, All-Chats Recovered Directive Register v2.1 and CF-03 Integrated Final Plan v2.0.
- Locked `RCD-022` as superseded by `SSH-FIN-DONATION-2026-08-04-01`.
- Preserved `CHAT-DL-001`, `CHAT-QA-001`, donor privacy and canonical ownership.

## 1.1.0-rc.2 — Three-Plan Harmonization and Universal Financial Download Contract

- Added eligible invoice, receipt, finance-export and transparency-snapshot download contracts.
- Preserved File 20, File 25, File 24 and CF-04 ownership boundaries.

## 1.1.0-rc.1 — Final Founder Donation and Financial Transparency Policy

- Codified Founder ownership, non-Trust status, no fixed core-service fee, voluntary donation and 0% commission.
- Added monthly appeal, 30-day suppression, approved expenses, Founder categories, public aggregates and revocable donor acknowledgment.

## 1.0.0-rc.3 — First Forty-Round Dual-Plan Source Candidate

- Completed `CF03-FR-001` through `CF03-FR-034` source traceability.
- Added forty regression tests and deterministic packaging.

## 1.0.0-rc.2 — Superseded Weekly Donation Amendment

- Historical weekly appeal implementation; superseded by the final monthly Founder decision.

## 1.0.0-rc.1 — Complete Conditional Source Candidate

- Added product, provider, ledger, invoice, refund, reconciliation, outbox and audit source foundations.

## 0.2.0 — Product, Price, Evidence and Contract Foundation

- Added immutable product/price snapshots, idempotency and provider-evidence contracts.

## 0.1.0 — Foundation

- Added exact money, 0% commission, donation non-privilege, intent transitions and balanced-ledger value objects.
