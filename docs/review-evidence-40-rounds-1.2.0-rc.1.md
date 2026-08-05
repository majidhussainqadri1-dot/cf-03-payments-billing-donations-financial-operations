# CF-03 Second Forty-Round Review and Defect-Correction Evidence

**Source candidate:** `1.2.0-rc.1`  
**Schema:** `3.2.0`  
**Governing plans:** `SSH-PMP-2026-v3.0`; All-Chats Recovered Directive Register `v2.1`; CF-03 Integrated Final Plan `v2.0`  
**Founder decision:** `SSH-FIN-DONATION-2026-08-04-01`

## Review law

Each round was performed on the result of the preceding round. Every discovered repository defect was corrected before the next round began. A round marked **No new defect** means the reviewed surface passed after earlier corrections; it does not mean the review was skipped.

| Round | Review surface | Finding | Correction before next round |
|---:|---|---|---|
| 01 | Governing-plan identity | README still named All-Chats Register v2.0 | Corrected to v2.1 and restated all three governing plans |
| 02 | Release identity | README reported source 1.1.0-rc.3 | Corrected to plugin candidate 1.2.0-rc.1 |
| 03 | Schema identity | README/architecture reported schema 3.0.0 | Corrected current complete runtime schema to 3.2.0 while retaining historical lineage |
| 04 | Automated-QA evidence | README reported obsolete 253/759 counts | Updated target to 364 tests per PHP version and 1,092 executions after this suite |
| 05 | Security status | SECURITY claimed no webhook/payment route existed | Replaced with accurate distinction: source routes exist, approved Live adapter/credentials do not |
| 06 | Architecture status | Architecture described the 1.1.0-rc.1 layer | Rewritten for 1.2.0-rc.1 runtime, schema, services and external gates |
| 07 | Contracts/events status | Document called contracts compile-time only | Rewritten for durable repository, REST, scheduler, outbox and current event boundaries |
| 08 | Migration status | Migration document said no tables were created | Rewritten for implemented additive 31-table schema and verified 3.2.0 upgrade/rollback law |
| 09 | Repository hygiene | Temporary `docs/push-files-probe.tmp` remained tracked | Deleted the probe artifact |
| 10 | Package hygiene | Builder did not defensively exclude temporary/editor artifacts | Added explicit exclusions for `*.tmp`, `*.log`, `*.map` and `.DS_Store` |
| 11 | Public policy privacy | Public `/policy` exposed provider, complete gate map and missing-gate names | Replaced with redacted runtime state and public Live boolean |
| 12 | Incident privacy | Public `/policy` exposed incident ID, reason, operators and evidence | Removed incident diagnostics from public response; retained them only in authorized health output |
| 13 | Live-state accuracy | Public Live boolean ignored incident checkout control | Bound the public Live result to runtime gates and current checkout path availability |
| 14 | Normal incident semantics | Normal state stored path flags as false, conflicting with policy calculation | Normal state now explicitly records checkout/refund/webhook paths as available |
| 15 | Public error disclosure | Donation/webhook conflicts returned internal activation or incident reasons | Added generic public conflict responses while preserving detailed authorized errors |
| 16 | Donation request size | Public donation JSON had no REST-layer body bound | Added a 64 KiB request-body limit before financial processing |
| 17 | Webhook body size | REST adapter copied arbitrary webhook bodies before enforcing a bound | Added an early one-megabyte body limit |
| 18 | Webhook header count | Arbitrary header collections could be forwarded to provider adapters | Limited webhook requests to 64 headers |
| 19 | Webhook header values | Oversized/CRLF/NUL header values were not rejected at the REST boundary | Added name validation, 8 KiB value limits and CRLF/NUL rejection |
| 20 | Guest-reference minimization | Guest financial-reference cookie persisted for one year | Reduced lifetime to 30 days; retained first-party, HttpOnly and SameSite controls |
| 21 | Donation double submission | Repeated clicks could create requests with fresh idempotency keys | Added form submission lock and disabled-state handling |
| 22 | Browser idempotency | Front end generated a new key on every submit attempt | Reuses the hidden key for the active request and sends the same `Idempotency-Key` header/body value |
| 23 | Browser cryptography | Front end assumed `crypto.getRandomValues` was always available | Added secure-context capability check and fail-closed message |
| 24 | Suggested/custom ambiguity | Radio amount could remain selected while custom amount was entered | Added mutual exclusion in both directions |
| 25 | Donation copy parity | Shortcode ignored the approved bilingual copy contract | Shortcode now renders `DonationAppealCopy` Urdu or English content by locale |
| 26 | Accessible amount grouping | Suggested amounts were not grouped with `fieldset`/`legend` | Added semantic fieldset and legend |
| 27 | Accessibility announcements | Status region lacked atomic announcement behavior | Added `aria-live`, `role=status` and `aria-atomic` controls |
| 28 | UI fallback | `color-mix()` had no preceding compatibility fallback | Added a standard border fallback before the enhanced declaration |
| 29 | Disabled-state clarity | Busy submit button had no distinct visual state | Added disabled opacity/cursor styling |
| 30 | Focus/mobile/reduced motion | Amount group focus and very-small-screen layout were incomplete | Added focus-within, 380px layout and retained reduced-motion behavior |
| 31 | Finance export authorization | Rechecked export routes for authentication-only regression | No new defect: dedicated `sabri_manage_finance_exports` capability remains enforced |
| 32 | Refund separation of duties | Rechecked requester/reviewer/executor controls and incident guard | No new defect: distinct capabilities and workflow identities remain enforced |
| 33 | Webhook trust boundary | Rechecked signature, replay, event identity, amount/currency and raw-body handling | No new defect: trusted-provider evidence remains mandatory and raw body is not persisted |
| 34 | Ledger/refund integrity | Rechecked balanced settlement/refund postings and over-refund prevention | No new defect: dedicated records, cumulative cap and balanced reversals remain enforced |
| 35 | Recurring donation | Rechecked explicit consent, amount change and cancellation | No new defect: recurrence is default-off and provider-confirmed management remains bounded |
| 36 | Settlement/period close | Rechecked importer/poster/reviewer/approver separation and material exceptions | No new defect: persisted independent review and close gates remain enforced |
| 37 | Transparency/privacy | Rechecked ledger-derived totals, Founder categories and donor acknowledgment | No new defect: snapshots remain evidence-derived and acknowledgment remains revocable/non-privileged |
| 38 | Audit/backup/restore | Rechecked serialized audit chain, manifest parity and restore duplicate detection | No new defect: integrity controls remain fail closed |
| 39 | Uninstall/retention | Rechecked ordinary uninstall and legal-hold boundaries | No new defect: ordinary uninstall does not purge financial/audit history |
| 40 | Cross-file and package parity | Rechecked File 00/20/24/25/26, CF-02/CF-04 ownership and package exclusions | Added machine assertions for the second review evidence, package hygiene and current source identities |

## Automated regression lock

`tests/run-review-40-second.php` contains exactly forty executable regression checks corresponding to this review. It is included in Composer and GitHub Actions after the existing suites.

The final acceptance target for one exact commit is:

- PHP 8.1: 364 tests, zero failures;
- PHP 8.2: 364 tests, zero failures;
- PHP 8.3: 364 tests, zero failures;
- total: 1,092 successful test executions;
- full PHP syntax scan;
- manifest validation;
- prohibited credential-material scan;
- governing-plan/current-version assertions;
- deterministic package built twice with identical SHA-256;
- no temporary probe or editor artifact in the package.

## Status boundary

This review can establish source-code, documentation, package and automated-QA completion only after the exact final head is green. It does not establish provider, legal, PCI, Hostinger staging, real-browser accessibility, independent security or Live operational acceptance. The PR remains Draft and real collection remains fail closed.
