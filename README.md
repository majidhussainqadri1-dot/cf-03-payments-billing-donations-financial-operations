# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical conditional financial owner for the **Sabri Social Homeopathy Platform**.

> **Current source candidate:** `1.4.0-rc.3` (corrective successor to approved Future40 target `1.4.0-rc.1`; not Live-deployed)  
> **Active canonical schema:** `4.0.0` — 27 active canonical tables  
> **Future Expansion Pack:** `CF03-FUTURE40-2026-09-08` — 40 coded future capabilities, all fail closed by default  
> **Runtime:** fail closed. Live collection and financial file delivery are not claimed.

## V12.3 source consolidation — 15 November 2026

This isolated source branch starts from the October PR #14 signed-event/catalogue-authority candidate and **restores independent PR #9 refund/row-lock, InnoDB preflight, oldest-due outbox, approved provider-specific donation disclosure, conditional CF-05 aggregate adapter and six missing financial regression suites**. The source-plan comparison uses the **Sabri Website Master Plan V12.3 (13 November 2026)**, especially CF03-FR-001..034, CF03-CEN-01..10, CF03-NJ-01..06 and the fail-closed FX-01..40 catalogue.

The current governing test inventory consists of **21 suites in both Composer and GitHub Actions**; passing these is not independent provider, real-MySQL, browser, staging or Live acceptance. The previously separate GitHub review branches must not be merged over this branch without an exact diff review.

**Do not activate or deploy from this source branch without its own exact-HEAD CI, independent finance/security/legal approval, approved provider sandbox, real-MySQL two-worker refund and outbox tests, valid deployment/restore evidence, cross-file contracts, explicit Founder change control, and a separate Live Reality Freeze.**

See `docs/cf03-v12.3-release-evidence-2026-11-15.md` for the evidence ledger and blockers.

The second source review also added a compare-and-swap delivery-state confirmation to `OutboxDispatcher`: a lost/renewed lease cannot count as delivered, retry, or dead-letter success for a stale worker. It added `tests/run-outbox-ack-lease-cas.php`, multi-shortcode unique ARIA form references, and a matching visible border for the actual custom donation text field. **These source protections do not establish downstream File 19 durable acknowledgements or real browser/staging acceptance.**

### V12.3 RC3 — additional defended source boundaries

- **Pre-provider rate/velocity admission:** `WordPressCheckoutRateGate` requires an explicitly approved, versioned Founder rate policy and uses database-unique, non-autoloaded WordPress option slots. Actor and request identities are only stored as site-keyed HMAC values; repeated request keys do not consume new slots. Exceeded quotas return HTTP 429 with Retry-After, and expired slots are removed in bounded cron batches. **No quantity/time values have been auto-approved.** Site WAF/CDN and provider abuse controls still require independent evidence.
- **File 01 durable event acceptance:** CF-03 outbox no longer equates an arbitrary WordPress listener with successful financial-fact delivery. The transport requires File 01 software 2.0.1 / contract 2.0.0 or higher and accepts only a strict `SPF_Event_Bus::publish(...)` true receipt, with restricted/opaque, no-donor/no-amount envelopes. Existing bare CF-03 event names are published under explicit `CF03.*.v1` File01 names while native accounting event truth stays unchanged. File 19 end-user notification projection and durable downstream consumption remain separate independent contracts.
- **Real DB isolation test:** an ephemeral MariaDB 10.11 service runs two concurrent SQL sessions with actual `InnoDB SELECT ... FOR UPDATE`, verifies MyISAM denial and rollback. This validates the staging-like isolated test environment, never the deployed Hostinger DB.
- **Release identity:** distinct source candidate `1.4.0-rc.3`, still schema `4.0.0` / 27 canonical definitions. No paid tier, recurring mandate, provider charge, Future40 activation or Live deployment is implied.

The separate machine-readable registry `manifests/cf03-v123-requirements.json` maps **all 90 V12.3 requirement IDs** (34 functional, 10 constitutional, 6 user journeys and 40 future), retaining an explicit independent staging/Live evidence status per item. `tests/run-v123-90-requirement-gates.php` enforces unique IDs, existing owner code, matching Future40 names, disabled future features and the ban on claiming Live acceptance from source CI. It is one of **21** mandatory PHP suites; a separate isolated MariaDB job validates actual row-lock behavior.


## Current governing plans

The current source is governed by:

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **CF-03 — Payments, Billing, Donations and Financial Operations — Conditional Complete Master Plan 2026 v1.1 — Future40 Amended 2026-09-09**;
3. **CF-03 Future Expansion Pack 40 Amendment** — `CF03-FUTURE40-2026-09-08`, which adds future code contracts without changing current financial law.

4. **Sabri Website Master Plan V12.3** (13 November 2026) — governing cross-platform acceptance, UI, finance and release evidence; later explicit approved requirements prevail over conflicting historical prose.

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

Current acceptance requires the complete 21-suite Composer/CI matrix and all current-governing-plan suites, the Future Expansion Pack 40 suite, PHP syntax scan, JSON validation, credential-material scan and deterministic package parity on the same exact candidate HEAD.

## External acceptance boundary

This repository can be **source-complete and automated-QA green** without being Staging-Accepted, Live-Deployed or Operational. Exact deployed code, Live DB/schema, provider acceptance, legal/tax/accounting/PCI acceptance, independent security acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, backup/restore drill and Founder Live activation remain separate evidence.
