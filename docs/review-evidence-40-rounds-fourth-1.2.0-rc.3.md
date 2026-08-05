# CF-03 Fourth Forty-Round Review and Defect-Correction Evidence

**Source candidate:** `1.2.0-rc.3`  
**Complete schema:** `3.3.0`  
**Governing plans:** `SSH-PMP-2026-v3.0`; Sabri Platform All-Chats Recovered Directive Register `v2.1`; CF-03 Integrated Final Plan `v2.0`  
**Founder decision:** `SSH-FIN-DONATION-2026-08-04-01`  
**Scope:** repository source, tests, manifests, deterministic package and Draft PR only

## Review law

Each round reviewed the corrected result of the preceding round. Every defect was corrected before the next round. “No new defect” means a fresh negative-path recheck, not a skipped round.

| Round | Review surface | Defect or result | Correction completed before next round |
|---:|---|---|---|
| 01 | Checkout retry identity | Retry fingerprint included volatile `created_at` | Rebuilt the fingerprint from stable actor/provider/intent/amount/currency/consent/idempotency terms |
| 02 | Checkout replay expiry | Completed retry could return an expired hosted URL | Added click-time expiry rejection for resumed and completed sessions |
| 03 | Idempotency ownership | Existing claim scope/actor were not revalidated | Enforced exact `donation_checkout` scope and actor parity |
| 04 | Donation aggregate recovery | Recovery checked donation existence only | Added donor, amount, currency, recurrence, provider and state parity |
| 05 | Monthly-consent recovery | Recovery checked consent existence only | Added actor, product, amount, currency, interval and state parity |
| 06 | Provider-session chronology | Future-issued provider sessions were not bounded | Added issue-time plausibility checks |
| 07 | Completed provider identity | Replayed intent could belong to a changed runtime provider | Required exact configured-provider parity |
| 08 | Duplicate webhook identity | Duplicate lookup requested only one row | Requested two rows and rejected non-unique provider-event identity |
| 09 | Duplicate webhook payload | Same event ID with changed body could be acknowledged | Bound duplicate acknowledgment to raw-body SHA-256 parity |
| 10 | Duplicate webhook semantics | Same event ID with changed event type could be acknowledged | Required exact event-type and intent parity |
| 11 | Event chronology | Signed event could predate the canonical intent | Added occurrence-time versus intent-creation validation |
| 12 | Late settlement anomaly | Implausibly late settlement was not quarantined | Added bounded expiry-relative settlement chronology |
| 13 | Settlement aggregate | Ledger posting tolerated a missing donation aggregate | Settlement now requires exact canonical donation parity |
| 14 | Monthly settlement consent | Monthly settlement tolerated missing/inconsistent consent | Settlement now requires exact pending monthly consent parity |
| 15 | Accounting period | Settlement period used webhook receipt month | Period now derives from provider occurrence time |
| 16 | Refund accounting period | Refund period used webhook receipt month | Period now derives from provider occurrence time |
| 17 | Refund amount | Zero provider refund evidence was accepted | Required strictly positive refund evidence |
| 18 | Refund request amount | Zero internal refund request was accepted | Required strictly positive requested amount |
| 19 | Cumulative refund balance | Separate requests could reserve more than original payment | Reserved requested/approved/pending/uncertain/succeeded/closed amounts |
| 20 | Refund identifier reuse | Duplicate refund ID always failed without exact replay semantics | Added exact-term idempotent reuse and changed-term rejection |
| 21 | Refund provider race | Provider call occurred before durable execution ownership | Added compare-and-swap `provider_pending` checkpoint before external call |
| 22 | Refund uncertain outcome | Provider exception left no explicit uncertainty state | Added bounded transition to `uncertain` for reconciliation |
| 23 | Refund confirmation | Provider confirmation caused a second logical version advance | Confirmed the existing command checkpoint with bounded exact update |
| 24 | Refund response privacy | Provider execution references could enter service output | Kept provider references out of the safe projection |
| 25 | Recurring cancellation race | Provider cancellation occurred before durable claim | Added `cancellation_pending` checkpoint before provider call |
| 26 | Recurring amount race | Provider amount change occurred before durable claim | Added `amount_change_pending` checkpoint before provider call |
| 27 | Recurring uncertain outcome | Provider failure had no durable uncertainty marker | Added `uncertain` state with strict checkpoint criteria |
| 28 | Recurring provider relation | First matching donation row was accepted arbitrarily | Required exactly one canonical recurring donation relation |
| 29 | Recurring evidence parity | Donation/provider relation was under-validated | Added actor, recurrence, currency, state and provider-reference checks |
| 30 | Recurring response privacy | Provider confirmation reference was publicly returned | Removed provider confirmation references from safe output |
| 31 | WordPress query failure | Failed `find()` appeared as an empty result | Database failure now throws and fails closed |
| 32 | WordPress paging failure | Failed `page()` appeared as an empty result | Database failure now throws and fails closed |
| 33 | Repository identity | Record IDs were not bounded at the adapter boundary | Added canonical identifier validation on insert/get/CAS |
| 34 | Immutable evidence | Generic update/delete could target ledger or audit collections | Blocked generic mutation of ledger transactions, entries and audit evidence |
| 35 | Memory/WordPress parity | Memory bounded updates silently advanced versions | Removed implicit memory version increments |
| 36 | Nested transaction parity | Caught inner memory failure could allow outer commit | Added outer rollback-only semantics and snapshot restoration |
| 37 | Audit idempotency | Existing audit ID returned without payload parity | Revalidated immutable actor/action/purpose/outcome/trace/time/metadata evidence |
| 38 | Finance download scope | Override downloads used a shared generic audience identity | Bound grants and audit to the actual authenticated finance actor |
| 39 | REST mutation bounds | Several financial mutations lacked a universal body ceiling | Added `rest_pre_dispatch` guard: 64 KiB mutations, 1 MiB webhooks |
| 40 | Cross-system regression gate | Corrections lacked a fourth executable/evidence/package lock | Added forty tests, release evidence, manifest/CI/version/package assertions |

## Automated regression lock

`tests/run-review-40-fourth.php` contains exactly forty executable checks. Together with the fourteen prior suites, the target is:

- PHP 8.1: **444 tests**, zero failures;
- PHP 8.2: **444 tests**, zero failures;
- PHP 8.3: **444 tests**, zero failures;
- aggregate: **1,332 successful test executions**;
- complete PHP syntax scan;
- JSON manifest validation;
- prohibited credential-material scan;
- governing-plan, Founder-decision, version and schema assertions;
- deterministic package built twice with identical SHA-256;
- package root and packaged-source parity checks.

## Status boundary

This evidence can establish repository source, documentation, manifest, package and automated-QA completion only for the exact green commit. It does not establish legal/tax/accounting acceptance, PCI acceptance, an approved provider, independent security acceptance, Hostinger staging acceptance, real-browser/RTL/accessibility/performance acceptance, backup/restore drills or Founder Live activation. The PR remains Draft and real collection remains fail closed.
