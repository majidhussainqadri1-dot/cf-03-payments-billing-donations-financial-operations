# CF-03 Third Forty-Round Review and Defect-Correction Evidence

**Source candidate:** `1.2.0-rc.2`  
**Complete schema:** `3.3.0`  
**Governing plans:** `SSH-PMP-2026-v3.0`; Sabri Platform All-Chats Recovered Directive Register `v2.1`; CF-03 Integrated Final Plan `v2.0`  
**Founder decision:** `SSH-FIN-DONATION-2026-08-04-01`  
**Scope:** repository source, tests, manifests, deterministic package and Draft PR only

## Review law

Each round reviewed the corrected result of the preceding round. Every defect listed below was corrected before the next round began. “No new defect” records a fresh adversarial recheck after prior corrections; it does not represent a skipped review.

| Round | Review surface | Defect or result | Correction completed before next round |
|---:|---|---|---|
| 01 | Collection activation | Donation checkout could open while webhook ingestion was disabled | Added donation-collection gates requiring webhook enablement and accepted endpoint evidence |
| 02 | Provider readiness | Public Live status did not prove that the configured provider existed in both registries and was healthy | Bound Live status and donation intent creation to registered donation adapter plus healthy payment adapter parity |
| 03 | Installed schema identity | Runtime configuration could load against a stale installed schema | Added exact `CompleteSchema::VERSION` verification and fail-closed preparing fallback |
| 04 | Runtime option typing | Truthy or malformed WordPress option values could be normalized permissively | Added strict boolean and gate-name parsing; malformed options now fail closed |
| 05 | Incident persistence | Corrupt incident option data could merge into a normal state | Added strict incident schema validation and an explicit all-paths-disabled corrupt state |
| 06 | Incident declaration | A new declaration could overwrite an active contained incident | Blocked redeclaration until the prior incident has independently recovered |
| 07 | Incident chronology | Recovery and subsequent declaration times were insufficiently constrained | Enforced declaration/recovery ordering and later-declaration chronology |
| 08 | Incident path dependency | Checkout could be recovered without the trusted webhook path | Required webhook recovery whenever checkout is re-enabled |
| 09 | Prompt context taxonomy | Unknown future page contexts defaulted to non-sensitive | Added explicit safe-context allowlist; unknown contexts are now sensitive and suppressed |
| 10 | Prompt storage contract | Canonical and legacy prompt fields could be written together | Limited writes to six canonical fields and retained legacy aliases only for migration reads |
| 11 | Prompt data integrity | Malformed persisted dates/status/frequency could be silently interpreted | Added strict enum and exact `DATE_ATOM` parsing with fail-closed rejection |
| 12 | Prompt chronology | Stale actions could rewrite newer prompt state | Added latest-event chronology validation before every state transition |
| 13 | WordPress prompt scope | A store instance could be used with a different user subject | Bound each store to exact `user:<id>` scope and rejected cross-subject access |
| 14 | Trusted donation facts | Valid provider evidence could be applied to another user’s prompt state | Bound trusted facts to a subject reference and enforced subject parity in the prompt service |
| 15 | Provider privacy | Provider adapters received the canonical platform donor reference | Added a provider-safe draft clone using a withheld subject while retaining the canonical local record |
| 16 | External/local atomicity | Provider session creation had no durable intermediate checkpoint | Added `provider_created` idempotency state before canonical local financial persistence |
| 17 | Checkout recovery | A checkpointed provider session could not resume after local persistence interruption | Added provider-session resume, parity checks and dependent-record verification |
| 18 | Public checkout response | Provider code and provider-session reference could escape in the REST response | Added a strict public response allowlist excluding provider internals |
| 19 | REST idempotency | Header and body idempotency values could disagree | Added exact header/body parity validation for donation, recurring management and refund execution |
| 20 | Guest financial identity | A generated guest reference could be used even when the cookie was not persisted | Guest financial operations now fail closed when headers are sent or secure cookie storage fails |
| 21 | Webhook privacy | Authorization, cookie and WordPress nonce headers could reach provider adapters | Added sensitive-header denylist before bounded provider header forwarding |
| 22 | Repository identity reads | Canonical `get` used a single-row assumption without duplicate detection | Reads now request two rows and fail closed on duplicate canonical identity |
| 23 | Nested transactions | A caught nested failure could allow an outer transaction to commit | Added rollback-only propagation so the outermost transaction must roll back |
| 24 | Bounded mutations | Pseudo `id` criteria could be discarded during normalization and broaden a mutation | Explicitly rejected pseudo `id`, empty normalized predicates and empty normalized changes |
| 25 | Schema verification | Activation verified tables and columns but not required indexes or uniqueness | Added primary/secondary index name, column-order and uniqueness verification |
| 26 | Table lookup safety | `SHOW TABLES LIKE` did not escape wildcard characters in prefixes | Added prefix validation and escaped table-name patterns |
| 27 | Upgrade lifecycle | Updated plugin files did not automatically run additive schema upgrades | Added a fail-closed, lock-protected `maybeUpgrade` path on early `init` |
| 28 | Upgrade failure mode | Upgrade failure could leave mutation flags unchanged | Upgrade now forces preparing mode, disables webhook/download flags and records failed status |
| 29 | Transparency uniqueness | Period-only uniqueness blocked lawful multi-currency snapshots | Replaced it with unique `(period_key,currency)` and removed the legacy index during migration |
| 30 | Transparency integrity | Published snapshot JSON had source hash but no independent payload checksum | Added `snapshot_hash`, canonical hashing, storage parity and migration backfill verification |
| 31 | Transparency delivery | Public download generation trusted stored snapshot JSON without checksum verification | Added source-hash and snapshot-hash verification before artifact creation |
| 32 | Expense idempotency | Reusing an expense ID returned an existing record without financial parity checks | Added amount, currency, category, purpose, approval, receipt and occurrence-time parity validation |
| 33 | Privacy export paging | WordPress privacy export always finished after one bounded page | Added per-group pagination and accurate `done` signaling |
| 34 | Privacy export failure | Repository failure could silently produce an empty privacy export | Added a privacy-safe explicit status item and retained no sensitive diagnostic detail |
| 35 | Donor erasure | Acknowledgment revocation failure was silently ignored | Added explicit manual-completion message while retaining mandatory financial records |
| 36 | Donation UI readiness | The shortcode presented an enabled provider continuation while collection was fail closed | Disabled controls and displayed bilingual preparation notice unless public Live readiness is true |
| 37 | Browser amount parsing | JavaScript floating-point rounding accepted exponent notation and excessive decimals | Added lexical USD validation, two-decimal maximum and integer-minor-unit construction |
| 38 | Browser retry integrity | An uncertain request failure cleared the idempotency key | Preserved the same key for safe retry while retaining double-submit locking |
| 39 | Release identity and QA | Source/schema/package/test evidence still described the preceding candidate | Bumped to `1.2.0-rc.2` / `3.3.0`, added this forty-test suite and updated all current evidence |
| 40 | Cross-system regression gate | The new hardening was not machine-locked across source, docs, manifests and package | Added forty executable checks, CI assertions, manifest parity and deterministic package verification |

## Automated regression lock

`tests/run-review-40-third.php` contains exactly forty executable checks for this review. Together with the twelve earlier suites and the second forty-round suite, the final target is:

- PHP 8.1: **404 tests**, zero failures;
- PHP 8.2: **404 tests**, zero failures;
- PHP 8.3: **404 tests**, zero failures;
- aggregate: **1,212 successful test executions**;
- complete PHP syntax scan;
- JSON manifest validation;
- prohibited credential-material scan;
- governing-plan, Founder-decision, version and schema assertions;
- deterministic package built twice with identical SHA-256;
- package root and packaged-source parity checks.

## Status boundary

This evidence can establish source, documentation, manifest, package and automated-QA completion only for the exact green commit. It does not establish legal/tax/accounting acceptance, PCI acceptance, an approved provider, independent security acceptance, Hostinger staging acceptance, real-browser/RTL/accessibility/performance acceptance, backup/restore drills or Founder Live activation. The PR remains Draft and real collection remains fail closed.
