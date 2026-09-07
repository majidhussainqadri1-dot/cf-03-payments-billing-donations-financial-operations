# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical conditional financial owner for the **Sabri Social Homeopathy Platform**.

> **Current source candidate:** `1.3.0-rc.1`  
> **Active canonical schema:** `4.0.0` — 27 active canonical tables  
> **Historical base:** schema `2.0.0`  
> **Runtime:** fail closed. Live collection and financial file delivery are not claimed.

## Current governing plans

Only the following two newly supplied plans govern this source candidate:

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **CF-03 — Payments, Billing, Donations and Financial Operations — Conditional Complete Master Plan 2026 v1.0**.

Earlier CF-03 v2.0, recovered-directive, 30-day/monthly-donation, paid-AI and subscription rules are retained only as historical evidence where needed for migration/audit. They are not active product law.

## Constitutional financial law

- One approved **free core tier**: registration, membership, approved structured education and **Sabri Classical Homeopathy AI** are not sold or donation-gated by CF-03.
- Clinic and Marketplace platform commission is **0%**.
- The active donation model is **voluntary one-time donation only**.
- No donation amount is preselected. Suggested values may include USD 10, USD 14 and USD 50 plus positive custom USD.
- A new appeal is blocked for at least **7 days** after display/dismissal and after a completed one-time donation, subject also to session/page/context safeguards.
- No recurring donation checkbox, mandate, renewal, grace, dunning or automatic repeat charge is active.
- Donation never changes access, entitlement, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI, clinical decisions, quota or feature availability.
- File 00 remains the canonical access/entitlement authority. CF-03 emits past-tense financial facts only; donation events are explicitly non-access events.
- Hosted/tokenized providers only. PAN, CVV/CVC, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or persisted by CF-03.
- Browser returns never establish settlement. Only trusted provider evidence may change financial truth.
- Escrow, wallet, custody, marketplace payouts, lending, investment and similar regulated flows remain disabled without separate Founder-approved Change-Control.

## Implemented source scope

The `1.3.0-rc.1` candidate implements:

- strict one-time donation intent and checkout boundaries with explicit one-time consent;
- stable actor/scope-bound idempotency and durable provider-created checkpoints;
- expired hosted-session rejection and safe replay/resume rules;
- signed webhook evidence, replay protection, duplicate-evidence parity and chronology checks;
- quarantine of legacy recurring/subscription/paid-core financial intents rather than silently reviving them;
- immutable balanced ledger, donation receipts, refunds, disputes/chargebacks, settlements and reconciliation;
- refund cumulative-balance protection, provider uncertainty/reconciliation and separation of duties;
- approved expense taxonomy and aggregate financial transparency snapshots;
- revocable donor acknowledgment consent without donor privilege;
- bounded privacy export, retention, backup/restore evidence and outbox delivery contracts;
- request-size guards, public REST redaction, webhook-header minimization and fail-closed guest financial identity;
- WordPress schema installation with exact table/column/index verification;
- schema `4.0.0` removing `recurring_consents`, `subscriptions`, `usage_authorizations` and `usage_facts` from the **active canonical schema**. Existing historical physical tables may remain only for bounded audit/reconciliation retention; active code does not create or treat them as current truth;
- compatibility tombstones for legacy recurring-consent, subscription and paid-AI-billing calls so stale integrations fail closed.

## Donation collection readiness

Source-code presence is not operational acceptance. Donation checkout remains unavailable unless all required runtime gates are complete: approved hosted/tokenized provider, trusted webhook readiness, legal/tax/accounting review, PCI responsibility validation, independent security acceptance, staging acceptance, rollback/restore evidence, cross-file contracts and explicit Founder activation.

The public donation UI therefore remains disabled while readiness proof is absent. When enabled by future approved operational evidence, it exposes only one-time donation controls and requires explicit one-time consent.

## Transparency, privacy and downloads

`/transparency/` publishes only a verified aggregate snapshot bound to source/payload hashes and publication state. Donor identity, private receipts, provider references, credentials, card/bank information and incident evidence are excluded from public output.

CF-03 owns financial eligibility and click-time authorization; File 20 owns the global shell/download placement, File 25 owns visual/accessibility presentation, File 24 owns assurance, and secure delivery remains separately gated. No Live delivery route is claimed by this repository.

## Current QA commands

```bash
php tests/run-new-governing-plans.php
php tests/run-new-governing-plans-adversarial.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

Historical suites and review evidence remain in the repository for provenance, but old suites that encode superseded 30-day/monthly/paid-AI/subscription behavior are not current acceptance gates.

Current acceptance requires the two new-governing-plan suites, PHP syntax scan, JSON validation, credential-material scan and deterministic package parity to pass on the same exact candidate HEAD.

## External acceptance boundary

This repository can become **source-complete and automated-QA green** without being Staging-Accepted, Live-Deployed or Operational. No approved Live provider adapter, legal/tax/accounting acceptance, PCI acceptance, independent penetration-test acceptance, Hostinger-equivalent staging acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, backup/restore drill or Founder Live activation is claimed until separately evidenced.
