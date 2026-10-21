# CF-03 Fifth Review — 40 Independent Seeded Financial Safety Scenarios and CF-05 Cross-File Contract

**Date:** 2026-10-21 (Asia/Karachi)  
**Documents actually reviewed:** supplied CF-03 v1.1 Future40 Word master plan; supplied three-central-plans consolidated Word master plan; CF-02 Support; CF-01 Clinical; File 16 AI; File 17 communications; File 18 marketplace; **newly attached CF-05 Analytics, Metrics and Institutional Intelligence v1.1 Future40 amended 2026-09-16**. Other cross-files without exact live API contracts remain unverified.

**GitHub:** base main SHA `24a8bbff7fd1940191ac947e4a23455d0567a0a3`; corrective PR #9 branch `audit/cf03-product-catalog-guard-2026-10-21`, source candidate `1.4.0-rc.2`. Exact current PR SHA/CI must be checked at release review.

## Scope clarification

- `docs/audit-40-cross-file-plan-2026-10-21.md` provides **40 distinct requirement-to-source review lenses** (34 CF03 FR + six central/cross-file).
- `tests/run-governing-compliance-40.php` provides **40 named executable source/unit requirements**.
- The NEW `tests/run-40-finance-review-scenarios.php` now actually runs **40 independent seeded scenarios (360 runtime assertions)** against varied actors, payment amounts, refunds, replays, remaining balances, and fair queue deadlines.
- These are 40 deliberately different simulation scenarios, **not 40 independent complete manual source audits, multi-user real MySQL concurrency tests, staging acceptance, or Live financial transactions**. The distinction is non-negotiable.

## New confirmed implementation defect — CF03-CODE-010

**Requirement:** CF03-FR-024, combined with FR-020 financial recovery and central atomicity/financial safety. User cannot request beyond refundable balance; duplicates must merge/reject safely.

**Proven root causes (same monetary-reservation boundary):**
1. `RefundWorkflowService::request` read up to 500 refund records using `find('refunds', ['intent_id' => ...], 500)` and ignored any older records beyond the cap. If at least 500 rows existed, it could calculate an unjustified higher refundable balance instead of refusing an incomplete scan.
2. Multiple independently identified refund requests could read the same unreserved payment balance concurrently; the method lacked a source-level transaction plus per-intent serializing row lock.
3. A SQL `FOR UPDATE` lock alone cannot be trusted when the installed table uses MyISAM or an unverified engine; the actual live schema engine is not known.

**Correction on Draft branch:**
- `QueryableFinancialRepository::getForUpdate` interface and both concrete implementations, callable ONLY inside a transaction.
- WordPress uses a row lock `SELECT ... FOR UPDATE` after a read-only `SHOW TABLE STATUS LIKE ...` engine preflight; `InnoDB` is mandatory. No engine auto-conversion or live migration.
- RefundService runs the whole idempotency / existing refund sum / balance-check / new refund insertion inside `transaction()`, and locks the canonical intent **before any refund reads**.
- If 500 rows are returned, the method refuses further reservation for manual reconciliation rather than claiming they are the totality. This intentionally trades availability for money safety and may require an approved capacity design later.

**Evidence:**
`tests/run-refund-reservation-atomic.php` verifies denial of over-refund, exact remainder, duplicate replay, non-owner denial, 500-record refusal and no phantom rows; source SQL lock order asserted.
`tests/run-refund-database-engine.php` uses a WordPress DB mock to reject MyISAM/unknown engines and accept only InnoDB with transaction/rollback behavior; **real InnoDB race testing remains a staging gate**.
`tests/run-40-finance-review-scenarios.php` executes forty separate seeds with nine checks each, totaling 360 assertions.

## New CF-05 contract completeness, not activation or financial ownership transfer

**Source law:** CF05-FR-018 in newly supplied plan (page 14) says financial analytics must come from CF-03 ledger-derived approved aggregates; no raw ledger fields or individual verdict. CF-05 also prohibits direct domain ownership or personal financial corpus (pages 5–6 and dependency matrix page 22).

**Source gap:** CF-03 had a File 00 financial fact integration with user reference but no separate bounded CF-05 aggregate-only DTO. It was therefore inappropriate to claim live CF-05 finance-consumer readiness.

**Conservative addition (conditional only):** `src/Integration/CF05FinancialAggregate.php` is a pure mapping from a **published, hash-matched** CF-03 transparency snapshot to five explicit aggregate metrics. Checks source owner CF-03, period/currency/snapshot ID, published state, canonical SHA-256 snapshot hash, and derived totals. Ignores additional PII, card data, donor refs, payment refs, expense detail and raw ledger rows. It creates **no** event publisher, REST endpoint, warehouse job, migration, CF-05 activation or new database truth.

**Evidence:** `tests/run-cf05-aggregate-boundary.php` rejects unpublished/mismatched/invalid snapshots and checks sensitive field exclusion and non-decision authority.

**Staging gate:** Exact CF-05 owner/consumer version negotiation, authorization, approved metric registry, data minimization/retention/deletion, signed ingestion, dual-sided compatibility and inactive-feature approval must be completed before using this DTO outside source tests.

## Existing correction ledger and accurate counts

Prior sessions established **9** corrected implementation-code defects on this Draft PR and **1** local-versus-CI test orchestration defect. This review adds **CF03-CODE-010**, making **10 confirmed source defects corrected in the Draft candidate**, one separate QA orchestration correction and one conditional CF-05 contract addition. **No change is deployed on Live.** The historical reports containing 7 or 9 as the final count are superseded for this correction candidate.

## Immutable open blockers and who can clear them

| Blocker | Evidence required / owner | Status |
|---|---|---|
| #6 GitHub About description advertises superseded PKR 400/paid-AI model | GitHub repository admin explicitly edit description, compare against free-core/no subscriptions law, verify afterward | OPEN; connector has no repository-metadata writer |
| #10 provider-front velocity controls | Host/CDN/provider anti-abuse policy and atomic checkout preflight/abuse tests, Founder security threshold approval | UNVERIFIED; no speculative racy throttle patch |
| #11 Live access and deployment parity | Authorized WordPress/host connection, exact installed plugin checksum, DB schema + engine, wp_options, migrations, logs | BLOCKED; previous Cloudflare 530 then WPVibe service D1 overload; not a proven CF-03 bug |
| Real provider / PCI / legal / tax | Approved provider account, signatures, compliance ownership, sandbox and banking reconciliation | OPEN |
| Cross-file File 00/05/08/16/17/18/19/20/24/25/26, CF-02 and CF-05 | Exact versioned consumer contracts, durable acknowledgements, permission/RTL/browser/replay/security tests | OPEN |
| Release | Dry-run migration/lock engine proof, backup-restore, rollback rehearsal, independent dual fresh reviews, Founder sign-off, Live re-test/monitoring | OPEN |

**Actual state:** Repository source candidate and package CI may pass. Deployed Version, Live DB Version, Migration State, provider safety and Live Verification are **unverified**. Never describe the defect as fixed on production before exact deployment and monitored Live re-test.
