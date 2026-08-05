# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical financial owner for the **Sabri Social Homeopathy Platform**.

> **Source status:** `1.2.0-rc.3`; historical base schema `2.0.0`; complete additive runtime schema `3.3.0` with 31 canonical tables. Live collection and financial file delivery remain disabled and fail closed pending external acceptance.

## Governing plans

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **Sabri Platform All-Chats Recovered Directive Register 2026 v2.1**;
3. **CF-03 Integrated Final Plan 2026 v2.0**, governed by Founder decision `SSH-FIN-DONATION-2026-08-04-01`.

`RCD-022` seven-day appeal wording remains historical and superseded. The active law permits at most one general Donation Appeal per calendar month and requires at least 30 days after Remind Me Later, Not Now, Close or a completed donation.

## Constitutional financial law

- The platform is Founder-owned by **Dr. Allamah Majid Hussain Sabri Muhaddith Murshid** and is not a Trust, charitable trust, welfare trust or trust fund.
- Registration, membership, education, AI, profile, verification, listing, publishing and all core platform services have no fixed platform fee under the active decision.
- Clinic and Marketplace platform commission is **0%**.
- Donations are voluntary, one-time or monthly, default-off and never affect access, entitlement, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI priority or clinical decisions.
- Suggested amounts are USD 10, USD 14, USD 50 and positive custom USD; no amount or recurrence is preselected.
- File 00 remains the sole entitlement authority. CF-03 emits past-tense financial facts only.
- Hosted/tokenized providers only. PAN, CVV, CVC, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or stored.
- Browser returns never establish settlement; only trusted provider evidence can change financial truth.

## Implemented source scope

- transactional WordPress and memory repositories with bounded queries, exact optimistic concurrency, nested rollback-only semantics, duplicate-identity detection and immutable ledger/audit protection;
- additive schema installation and exact table/column/index/uniqueness verification;
- fail-closed automatic plugin upgrade path;
- voluntary one-time/monthly donation checkout with explicit consent and provider-safe identity minimization;
- stable retry fingerprints, actor/scope-bound idempotency, durable provider-created recovery checkpoints and expired-session rejection;
- signed webhook, replay, duplicate-evidence parity, chronology, provider, intent, amount and currency validation;
- settlement preconditions requiring canonical donation and monthly-consent parity;
- immutable balanced ledger, receipts, cumulative refund reservation, durable refund execution checkpoints and recurring-donation management checkpoints;
- approved expense taxonomy and separately disclosed Founder-related categories;
- canonical-hash-verified aggregate financial transparency snapshots;
- revocable donor acknowledgment consent;
- bounded privacy export, prompt-state erasure and retained-record disclosure;
- incident containment, path kill switches and independently approved recovery;
- secure download contracts, actual-actor audit scope, retention, backup/restore and outbox evidence;
- platform-wide financial mutation request-size guard, public REST redaction, webhook header minimization and guest-identity fail-closed controls.

## Donation collection readiness

Donation checkout cannot open unless all financial gates, trusted webhook readiness, the selected provider, independent security, staging, rollback, cross-file contracts, operations and applicable Founder approval are complete. Public Live status additionally requires runtime state `live`, no missing collection gate, enabled incident paths, a registered healthy provider and installed schema `3.3.0`.

The donation shortcode is disabled and states that no funds are being collected while this readiness proof is absent.

## Transparency, privacy and downloads

`/transparency/` publishes only a verified aggregate snapshot bound to source and payload SHA-256 values, period/currency uniqueness, publication state and timestamp. Donor identity, private invoices, provider references, bank/card information, credentials and incident evidence are excluded from public output.

Directive `CHAT-DL-001` covers invoices, receipts, approved finance exports and verified aggregate transparency snapshots. CF-03 owns eligibility and click-time authorization; File 20 owns the Global Download Manager; File 25 owns green-led Ionicons/RTL/accessibility presentation; File 24 owns assurance; CF-04 owns secure delivery after activation. No Live delivery route is enabled.

## QA

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
php tests/run-free-donation-policy.php
php tests/run-founder-donation-transparency.php
php tests/run-three-plan-harmonization.php
php tests/run-plan-completion.php
php tests/run-adversarial-2.php
php tests/run-review-40.php
php tests/run-runtime-completion.php
php tests/run-source-completion.php
php tests/run-adversarial-source.php
php tests/run-review-40-second.php
php tests/run-review-40-third.php
php tests/run-review-40-fourth.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

The fifteen suites execute **444 tests per PHP version** and **1,332 test executions** across PHP 8.1, 8.2 and 8.3. CI also validates manifests, prohibited credential material, governing sources, schema/index assertions, public privacy, package hygiene and deterministic package parity.

Review evidence:

- `docs/review-evidence-40-rounds-1.0.0-rc.3.md`;
- `docs/review-evidence-40-rounds-1.2.0-rc.1.md`;
- `docs/review-evidence-40-rounds-third-1.2.0-rc.2.md`;
- `docs/review-evidence-40-rounds-fourth-1.2.0-rc.3.md`.

## External acceptance boundary

This repository is a source candidate, not an operating payment service. No approved Live provider adapter, legal/tax/accounting acceptance, PCI acceptance, independent penetration-test acceptance, Hostinger staging acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, backup/restore drill or Founder Live activation is claimed. The Draft PR, `main`, staging and Live remain separate until explicit acceptance.
