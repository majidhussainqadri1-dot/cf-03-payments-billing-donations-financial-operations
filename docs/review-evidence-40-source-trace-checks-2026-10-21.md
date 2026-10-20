# CF-03 — Forty Focused Source-Trace Checks and Root-Cause Ledger (2026-10-21)

**Scope:** source/repository review only. **Not** forty independently executed full-system cycles, not Live verification, and not proof of feature-complete integrations. The 40 checks each inspected a required code anchor and were compared with the current CF-03 v1.1 Future40 and governing platform decisions. Exact repository base SHA: `24a8bbff7fd1940191ac947e4a23455d0567a0a3`. Audit branch: `audit/cf03-product-catalog-guard-2026-10-21`.

**Governing sources:** `SSH-PMP-2026-v3.0` + `CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.1-Future40-Amended-2026-09-09` and applicable other file plans. Superseded paid AI, PKR 400, monthly/recurring donation and subscriptions are historical only.

## 40 source-trace checkpoints

The outcome means **a matching source symbol/implementation anchor was located**, not that all functional, negative, staging, financial, security or external tests passed.

| Check | Plan/cross-module boundary | Inspection evidence |
|---|---|---|
| 01 | CF03-FR-001 — active approved donation catalogue | Matching source anchor present |
| 02 | CF03-FR-002 — price/version policy | Matching source anchor present |
| 03 | CF03-FR-003 — pre-action disclosure | Matching source anchor present |
| 04 | CF03-FR-004 — zero commission | Matching source anchor present |
| 05 | CF03-FR-005 — donor neutrality | Matching source anchor present |
| 06 | CF03-FR-006 — hosted checkout | Matching source anchor present |
| 07 | CF03-FR-007 — intent and canonical catalogue guard | Matching source anchor present |
| 08 | CF03-FR-008 — verified webhook signature | Matching source anchor present |
| 09 | CF03-FR-009 — provider state mapping | Found after manual source reinspection (automated keyword mismatch) |
| 10 | CF03-FR-010 — provider-confirmed settlement | Matching source anchor present |
| 11 | CF03-FR-011 — provider abstraction | Matching source anchor present |
| 12 | CF03-FR-012 — balanced immutable journal | Matching source anchor present |
| 13 | CF03-FR-013 — receipt/document snapshots | Matching source anchor present |
| 14 | CF03-FR-014 — provider settlement | Matching source anchor present |
| 15 | CF03-FR-015 — daily reconciliation | Matching source anchor present |
| 16 | CF03-FR-016 — append-only adjustments | Matching source anchor present |
| 17 | CF03-FR-017 — integer minor units | Matching source anchor present |
| 18 | CF03-FR-018 — one-time donation lifecycle | Matching source anchor present |
| 19 | CF03-FR-019 — seven-day appeal silence | Matching source anchor present |
| 20 | CF03-FR-020 — idempotent recovery | Matching source anchor present |
| 21 | CF03-FR-021 — donor/non-donor parity | Matching source anchor present |
| 22 | CF03-FR-022 — one-time history, abandonment and refund states | Found after manual source reinspection (automated keyword mismatch) |
| 23 | CF03-FR-023 — free AI/education boundary | Matching source anchor present |
| 24 | CF03-FR-024 — refund request | Matching source anchor present |
| 25 | CF03-FR-025 — refund approval/execution | Matching source anchor present |
| 26 | CF03-FR-026 — disputes/chargebacks | Matching source anchor present |
| 27 | CF03-FR-027 — refund/receipt history | Matching source anchor present |
| 28 | CF03-FR-028 — fraud review | Matching source anchor present |
| 29 | CF03-FR-029 — separation of duties | Matching source anchor present |
| 30 | CF03-FR-030 — request/data minimization | Matching source anchor present |
| 31 | CF03-FR-031 — secure export | Matching source anchor present |
| 32 | CF03-FR-032 — finance period close | Matching source anchor present |
| 33 | CF03-FR-033 — provider/security incident | Matching source anchor present |
| 34 | CF03-FR-034 — recovery/restore reconciliation | Matching source anchor present |
| 35 | File 00/05/16 — free access and capability ownership | Matching source anchor present |
| 36 | File 08/18 — direct payments and commission=0 | Matching source anchor present |
| 37 | File 20/25 — shell, layout and visual contract | Matching source anchor present |
| 38 | File 24/CF-02 — assurance and support authority boundary | Matching source anchor present |
| 39 | Future40 — coded and inactive conditional catalogue | Matching source anchor present |
| 40 | CI — PHP 8.1/8.2/8.3 and deterministic package parity | Matching source anchor present |

## Defect ledger — evidence and status

**CF03-AUD-001 — Confirmed code gap; corrected on isolated branch, not deployed.**
The active donation request path previously called `DonationCheckoutService::create()`, then `createHostedDonationCheckout()`, without reading the canonical `products/donation.one_time` record. A product could be missing/retired/unapproved while provider-side checkout was requested if external activation gates were separately satisfied. This contradicts CF03-FR-001 and CF03-FR-007's active approved catalogue prerequisite. Root cause: catalogue existence/version state was implemented in `CatalogDisclosureService` but not enforced on the checkout command. Fix: `src/Application/ApprovedDonationCatalogGate.php` + check in `DonationCheckoutService::create` before idempotency/provider side effects. Regressions in `tests/run-approved-donation-catalog-gate.php` and new CI step cover missing, retired, superseded policy, entitlement-linked, missing approval, future effective and valid approved catalogues. **Staging/Live fix unverified.**

**CF03-AUD-002 — Confirmed repository-metadata governance defect; OPEN.**
GitHub repository About/description still advertises paid PKR 400 membership, paid AI and subscriptions contrary to current approved plans. Existing issue #6 is open. This connector does not provide a supported repository description update action. No claim of remediation is made.

**CF03-AUD-003 — Security acceptance gap requiring further targeted investigation; NOT YET A PROVEN EXPLOIT.**
`WordPressRestApi::donationIntent` and `WordPressRequestGuard` expose request-size/idempotency controls but no demonstrated native actor-frequency throttle before creating third-party payment-provider checkout sessions. Determine external WAF/CDN/provider throttling and expected privacy-preserving per-actor limit, then implement and adversarial-test an atomic control where required. Do not rush a race-prone transient throttle into financial flows.

**CF03-AUD-004 — Integration evidence outstanding, not a proven local code defect.**
The source declares File 00/05/16 free access and AI equality; File 08/18 zero commission and direct-deal ownership; File 20/25 shell/visual ownership; File 24 security; File 17 notification context where relevant; CF-02 support/refund case bridge; and File 26 ranking neutrality. Their *deployed* contract versions and end-to-end interoperation have not been inspected, so these remain external acceptance gates.

## Independent release gates — not cleared by source checks

1. Precise deployed runtime source/package checksum, plugin version and GitHub parity.
2. Live database schema, relevant tables, wp_options, migration status and operational logs.
3. Approved hosted/tokenized payment provider and sandbox, signed webhook tests and bank settlement reconciliation.
4. Legal/tax/accounting, PCI responsibilities and independent security acceptance.
5. Cross-file staging integration, full role/browser/RTL/a11y/load/abuse tests.
6. Backup/restore with authoritative provider reconciliation, and rollback drill.
7. Founder approval and Live smoke/retest with operational monitoring.

**Current evidence boundary:** repository/CI evidence is distinct from Staging-Accepted, Live-Deployed and Operational. Exact deployed code unverified; repository-based conclusions about live behavior are provisional.

## Follow-up

- Verify the isolated change against exact-head CI, including the 9 scenario assertions, and obtain fresh adversarial review.
- Do not merge/deploy until impact on existing installations and the product-approval provisioning workflow are accepted.
- Resolve issue #6 in repository metadata, not only documentation.
- Validate checkout rate limits and CF-02/File 00/File 18 integration using real owner contracts before asserting production completion.
