# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical conditional financial owner for the **Sabri Social Homeopathy Platform**.

> **Current source candidate:** `1.4.0-rc.1`  
> **Active canonical schema:** `4.0.0` — 27 active canonical tables  
> **Future Expansion Pack:** `CF03-FUTURE40-2026-09-08` — 40 coded future capabilities, all fail closed by default  
> **Runtime:** fail closed. Live collection and financial file delivery are not claimed.

## Current governing plans

The current source is governed by:

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **CF-03 — Payments, Billing, Donations and Financial Operations — Conditional Complete Master Plan 2026 v1.0**;
3. **CF-03 Future Expansion Pack 40 Amendment** — `CF03-FUTURE40-2026-09-08`, which adds future code contracts without changing current financial law.

Earlier CF-03 v2.0, recovered-directive, 30-day/monthly-donation, paid-AI and subscription rules remain historical evidence only.

## Constitutional financial law

- One approved **free core tier**: registration, membership, approved structured education and **Sabri Classical Homeopathy AI** are not sold or donation-gated by CF-03.
- Clinic and Marketplace platform commission is **0%**.
- The active public donation model remains **voluntary one-time donation only**.
- No donation amount is preselected.
- A new appeal is blocked for at least **7 days** after display/dismissal and after a completed one-time donation, subject to existing context safeguards.
- No recurring donation checkbox, mandate, renewal, grace, dunning or automatic repeat charge is active.
- Donation never changes access, entitlement, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI, clinical decisions, quota or feature availability.
- File 00 remains the canonical access/entitlement authority.
- Hosted/tokenized providers only. PAN, CVV/CVC, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or persisted by CF-03.
- Browser returns never establish settlement. Only trusted provider evidence may change financial truth.
- Escrow, wallet, custody, marketplace payouts, lending, investment and similar regulated flows remain disabled without separate Founder-approved Change-Control.

## Implemented current source scope

The current source implements one-time donation intent/checkout, signed webhook settlement, immutable balanced ledger, receipts, refunds, disputes/chargebacks, settlements, reconciliation, financial transparency, privacy/export controls, fail-closed incident/runtime controls, deterministic packaging and active schema `4.0.0`.

The active schema intentionally excludes `recurring_consents`, `subscriptions`, `usage_authorizations` and `usage_facts` from current runtime truth. Compatibility tombstones fail closed for stale recurring/subscription/paid-AI callers.

## Future Expansion Pack — 40 coded facilities

Release `1.4.0-rc.1` adds exactly **40** future capabilities under `CF03-FUTURE40-2026-09-08`.

They are grouped as:

- **FX-01—FX-08 Donation Experience:** purpose funds, reminder preferences including Never Remind Me, privacy controls, receipt vault, receipt authenticity, transparency dashboard, use-of-funds reporting and multi-currency readiness.
- **FX-09—FX-20 Provider/Operations Intelligence:** provider selection, health, failover, webhook forensics, uncertainty resolution, reconciliation queue/confidence, close checklist, dual approval, refund preview/SLA and chargeback evidence.
- **FX-21—FX-30 Governance/Privacy:** financial privacy center, user/accountant exports, immutable audit evidence, configuration history, policy simulation, sandbox, deployment readiness, kill switches and restore verification.
- **FX-31—FX-40 Integration/Compliance/Sustainability:** privacy-preserving analytics, accessibility-first UX, CF-02 support bridge, notification preferences, internal finance events, jurisdiction registry, tax/legal disclosure, conditional Sharia classification, conditional waqf/grant sustainability and Founder Financial Command Center.

Authoritative code and manifest:

- `src/Application/FutureExpansionRegistry.php`
- `src/Domain/FutureFinancePolicy.php`
- `src/Application/FutureDonationExperienceService.php`
- `src/Application/FutureOperationsIntelligenceService.php`
- `src/Application/FutureGovernancePrivacyService.php`
- `src/Application/FutureIntegrationSustainabilityService.php`
- `manifests/cf03-future-expansion-40.json`
- `docs/final-plan/CF-03-Future-Expansion-Pack-40-2026-09-08.md`
- `tests/run-future-expansion-40.php`

### Activation boundary

**Code presence does not activate these future facilities.** The feature registry marks all 40 as `activated=false`.

`FX-38` (Sharia Financial Classification Layer) and `FX-39` (Waqf/Grant Sustainability Module) are specifically coded as **conditional change-control** capabilities. They remain disabled until their distinct Founder, Sharia/legal/accounting and operational approvals are evidenced.

Multi-currency, provider failover, jurisdiction launch, tax/legal wording and other operational features also remain subject to existing provider/security/PCI/legal/tax/accounting/staging/restore/cross-file/Founder gates before any Live use.

## Donation collection readiness

Source-code presence is not operational acceptance. Donation checkout remains unavailable unless all required runtime gates are complete: approved hosted/tokenized provider, trusted webhook readiness, legal/tax/accounting review, PCI responsibility validation, independent security acceptance, staging acceptance, rollback/restore evidence, cross-file contracts and explicit Founder activation.

## Transparency, privacy and downloads

`/transparency/` publishes only verified aggregate snapshots. Donor identity, private receipts, provider references, credentials, card/bank information and incident evidence remain excluded from public output.

## Current QA commands

```bash
php tests/run-new-governing-plans.php
php tests/run-new-governing-plans-adversarial.php
php tests/run-future-expansion-40.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

Current acceptance requires all current-governing-plan suites, the Future Expansion Pack 40 suite, PHP syntax scan, JSON validation, credential-material scan and deterministic package parity on the same exact candidate HEAD.

## External acceptance boundary

This repository can be **source-complete and automated-QA green** without being Staging-Accepted, Live-Deployed or Operational. Exact deployed code, Live DB/schema, provider acceptance, legal/tax/accounting/PCI acceptance, independent security acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, backup/restore drill and Founder Live activation remain separate evidence.
