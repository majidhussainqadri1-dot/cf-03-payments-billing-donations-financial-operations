# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical financial owner for the **Sabri Social Homeopathy Platform**.

> **Source candidate:** `1.2.0-rc.1`  
> **Complete runtime schema:** `3.2.0` — 31 canonical tables  
> **Operational state:** fail closed; no approved Live provider or Live financial collection

## Three governing plans

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **Sabri Platform All-Chats Recovered Directive Register 2026 v2.1**;
3. **CF-03 Integrated Final Plan 2026 v2.0**, governed by Founder decision `SSH-FIN-DONATION-2026-08-04-01`.

The recovered `RCD-022` seven-day Donation Appeal wording is retained as history but is superseded by the later Founder decision. The active rule is at most one appeal per calendar month and at least 30 days after dismissal, reminder deferral, close or a completed donation.

## Governing financial law

- The platform is Founder-owned by **Dr. Allamah Majid Hussain Sabri Muhaddith Murshid** and is not a Trust or charitable trust.
- Registration, membership, education, AI, profile, verification, listing, publishing and other core platform services have no fixed platform fee under the current decision.
- Clinic and Marketplace platform commission is **0%**.
- Donations are voluntary, one-time or monthly, default-off and never affect access, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI or clinical decisions.
- Suggested amounts are USD 10, USD 14, USD 50 and positive custom USD; no amount or recurrence is preselected.
- Founder compensation, expense reimbursement, advance repayment and owner withdrawal are separately recorded and publicly aggregated.
- Hosted/tokenized providers only; PAN, CVV, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or persisted.
- Browser returns never establish payment settlement.

## Implemented source scope

- WordPress transactional financial repository and verified additive migrations;
- voluntary one-time/monthly donation intents and explicit recurring consent;
- hosted-provider ports, provider registry and fail-closed null adapters;
- durable idempotency, signed webhooks, replay protection and event deduplication;
- exact minor-unit money, immutable double-entry ledger and receipt snapshots;
- refund request/review/execution separation, partial refunds and over-refund prevention;
- recurring amount changes and explicit cancellation;
- settlement import, reconciliation exceptions, posting and finance-period review/close/reopen;
- approved expense taxonomy and aggregate public transparency;
- revocable donor acknowledgment consent;
- secure bounded exports, formula neutralization, opaque artifact references and expiring grants;
- fraud review, appeals, chargebacks and ledger adjustments;
- retention schedules, legal holds and immutable-evidence protection;
- incident containment, path-specific kill switches and dual-controlled recovery;
- serialized audit hash chain, backup manifests, restore reconciliation and integrity checks;
- granular WordPress capabilities, administration REST contracts, privacy exporter/eraser and schedulers.

## Public privacy boundary

Public policy and transparency responses expose policy facts and a redacted runtime state only. Provider identity, activation-gate evidence, missing-gate names, incident identifiers, incident reason codes, operators and internal diagnostics remain restricted to authorized finance-health endpoints.

Public donation and webhook failures return safe generic operational conflicts rather than internal activation or incident details. Webhook bodies and headers are bounded before provider verification.

## Donation presentation

The built-in donation shortcode uses the Founder-approved Urdu/English copy contract, no preselected amount, no preselected recurrence, accessible `fieldset`/`legend` controls, live status announcements, secure-context checks and a double-submission lock. File 20 remains the global-shell/modal-mount owner and File 25 remains the final responsive visual/RTL/accessibility owner.

## Universal financial download contract

Directive `CHAT-DL-001` applies to eligible invoices, receipts, finance exports and verified aggregate transparency snapshots. CF-03 owns eligibility and click-time authorization; File 20 owns the Global Download Manager, File 25 presentation, File 24 assurance and CF-04 secure delivery after activation.

## Canonical ownership

CF-03 owns financial truth. File 00 owns identity, membership and entitlement. File 20 owns the global shell, File 25 presentation, File 24 assurance, File 19 notification delivery, File 26 search/ranking and CF-02 case orchestration. Donation is never a ranking or entitlement signal.

## QA

```bash
composer test
composer lint
bash scripts/build-package.sh
```

The current CI matrix targets **364 tests per PHP version** across PHP 8.1, 8.2 and 8.3, for **1,092 test executions**, plus full syntax checking, JSON manifest validation, prohibited-credential scanning, governing-plan assertions and deterministic package parity.

The second forty-round review evidence is recorded in:

`docs/review-evidence-40-rounds-1.2.0-rc.1.md`

## External acceptance boundary

This repository is a source-complete candidate, not a Live operational payment processor. Real collection remains fail closed until an approved provider adapter, legal/tax/accounting review, PCI scope confirmation, independent security review, Hostinger staging acceptance, browser/RTL/accessibility testing, backup/restore/rollback evidence and explicit Founder Live approval are complete.
