# CF-03 — Governing Plan / Cross-File Code Review, 40 Executable Checks & Corrective Evidence
**Review date:** 21 October 2026  
**Exact initial main HEAD:** `24a8bbff7fd1940191ac947e4a23455d0567a0a3`  
**Working branch:** `audit/cf03-product-catalog-guard-2026-10-21`  
**Source documents:** CF-03 v1.1 Future40 amended 09 September 2026; three consolidated central governing plans; related File 11/16/17/18 and CF-01/CF-02 plans.
**Status:** isolated GitHub source candidate; *not* staging-approved, merged, deployed, Live-verified or operational.

## Terminology and evidence boundary

- The existing `docs/review-evidence-40-source-trace-checks-2026-10-21.md` contains **40 separate source/ownership checks** (34 core FRs + six cross-file/release groups). Source matches alone do **not** prove functional completeness.
- This continuation adds `tests/run-governing-compliance-40.php` with **40 named executable source + unit invariant checks** against the current central/CF constitution. PHP 8.1/8.2/8.3 CI executes these; again, forty named test cases are **not** forty independently repeated, full-system review/fix/retest cycles.
- Test additions also cover key catalogue state, product provisioning order, expired outbox leases, merchant-scoped idempotency, registered outbox consumers and Sabri Green. These are distinct from the existing Future40 functional/adversarial suites.
- Zero automatic financial collection activation: runtime preparing/fail-closed, future flags remain OFF. Passing CI never establishes Live truth.

## Confirmed source defects and corrective commits (6/6 patched on audit branch)

| ID | Root cause & requirement | Correction | Source state |
|---|---|---|---|
| CF03-CODE-001 | Checkout used hard-coded `donation.one_time` but failed to verify its current approved, effective catalogue snapshot before provider call (FR-001/007). | New read-only `ApprovedDonationCatalogGate` placed before financial/provider side effects; regression tests include missing/retired/superseded/unauthorized snapshots. | Patched; CI green |
| CF03-CODE-002 | Outbox dispatcher selected pending/retry only; crashed messages in processing with an expired lease stayed stuck (reliability/notifications/File19). | CAS-based bounded expired-lease recovery, retry/dead-letter handling, stable event IDs and adversarial scenarios. | Patched; CI green |
| CF03-CODE-003 | Provider-facing idempotency clone used unscoped client-supplied key; different donors choosing the same key could collide with merchant-scoped gateway idempotency. | Deterministic, opaque `cf03prov.*` hash scoped by donor + intent + client key without exposing donor identity. | Patched; CI green |
| CF03-CODE-004 | WordPress `do_action` silently succeeds with no financial consumer and was interpreted as delivered. | `has_action` consumer-readiness prerequisite; absent consumers cause retryable error, not false delivery. Note: registered listener alone still does not prove downstream durable acknowledgement. | Patched; CI green |
| CF03-CODE-005 | CSS primary green `#187a3d` diverged from central governing Sabri Green `#087A4E` (File25/visual constitution). | Corrected CSS token and safeguarded contrast/focus/mobile/reduced-motion anchors. | Patched; CI green |
| CF03-CODE-006 | One-time donation catalogue seeder was not wired into WordPress activate/upgrade; with the new guard this could permanently block otherwise approved checkout. | Seed only the preapproved one-time product and retire historical recurring product within a DB transaction after canonical schema verification and before version-success options; **no** provider/checkout flag activated. Explicitly retired/conflicting active products fail closed. | Patched; CI green |

**Count:** six confirmed implementation defects, six isolated-branch source corrections, zero Live corrections/verification. This is not a proof that no other defect exists.

## CI acceptance evidence

`tests/run-approved-donation-catalog-gate.php` — 12 checks.  
`tests/run-expired-outbox-leases.php` — 11 checks.  
`tests/run-provider-idempotency-scoping.php` — 11 checks.  
`tests/run-outbox-consumer-boundary.php` — 7 checks.  
`tests/run-governing-brand-token.php` — 6 checks.  
`tests/run-governing-compliance-40.php` — 40 separate named current-governing source/unit checks.

These total **87 dedicated assertions**, in addition to existing tests. CI jobs: PHP 8.1, 8.2, 8.3, and reproducible package/source parity.

## Current financial constitution verified in source

- Core platform, File 05 education and File 16 Sabri AI remain free (no PKR 400 tier, paid AI, paid core).
- Donation strictly voluntary and independently one-time; no preselected amount, subscriptions, automatic renewals, or monthly mandates.
- Minimum seven-day dismissal silence. Donor and non-donor rank, features, File 00 identity/access, support, AI and education must be equal.
- File 08 and File 18 Marketplace/Clinic commission = 0%; no CF-03 escrow/wallet/custody claims.
- File 20 owns global navigation/shell, File 25 visual contract; CF-03 supplies domain UI only.
- CF-02 support can reference/report/request but cannot mutate ledger, settle or approve a refund through a bypass.
- File 19 notification derivative does not become ledger truth; File 24 assurance is not a substitute for native security.
- `Future40` includes FX-01..FX-40, all disabled. FX-38 Sharia classification and FX-39 Waqf/Grants require separate Founder/Sharia/legal/accounting change-control acceptance.

## Unresolved blockers / confidence limits

1. **Repository metadata mismatch** (GitHub issue #6): About/description still advertises superseded PKR 400 and paid AI/subscriptions. Connector cannot modify repository metadata; update About/description explicitly and verify it before closing.
2. **Checkout abuse controls** (issue #10): source has size/idempotency constraints but no demonstrated native atomic pre-provider rate/velocity limit. Origin WAF/CDN/provider controls and thresholds are unverified. Do not assert exploit or blindly add racy counters; verify protections, then implement/test if needed.
3. **Live evidence blocked** (issue #11): WPVibe site connection to sabrihomeopathy.com returned Cloudflare HTTP 530; this is only a connection symptom. No exact deployed plugin file checksum, active version, `wp_options`, database tables/columns/rows, migration ledger or runtime errors could be obtained. Determine Cloudflare/origin diagnosis before attempting any Live fix.
4. **Mandatory external acceptance**: approved real hosted/tokenized provider, legal/tax/accounting and PCI scope, independently evidenced financial security, File 00/05/08/16/18/19/20/24/25/26/CF-02 consumers, working signed webhooks, bank settlement, real browser/RTL/a11y, staging, provider outage and key-rotation tests, backup/restore, rollback, Founder sign-off and Live smoke test.
5. **Delivery acknowledgement limitation**: a registered WordPress action listener does not intrinsically confirm that File 19/other external consumers durably persisted the event. True cross-module ack/reconciliation still requires connector-level verification.
6. **Legacy historical tests and documents**: do not interpret former monthly/paid scenarios as current acceptance; only the active governing CI suites and supersession labels can substantiate current policy.

## Release truth report

| Field | Verified truth |
|---|---|
| Repository base main HEAD | `24a8bbff7fd1940191ac947e4a23455d0567a0a3` at original freeze; recheck main before merge |
| Corrective branch HEAD | Determine from GitHub at final acceptance, not copied historical CI |
| Repository candidate | 1.4.0-rc.1, schema 4.0.0, 27 active canonical tables per source manifest |
| Deployed version | UNVERIFIED |
| Live DB version | UNVERIFIED |
| Migration state | UNVERIFIED |
| Deployment parity | UNVERIFIED |
| Staging/Founder approval | UNVERIFIED |
| Live verification status | NOT VERIFIED |
| Proven source defects | 6 patched in isolated PR branch, NOT claimed fixed on Live |
| Confirmed governance metadata defect | 1 unresolved (#6) |

**Hard stop:** Exact deployed code is still unverified; diagnosis of any Live behavior using only the repository is provisional. Do not merge/deploy automatically on this evidence.
