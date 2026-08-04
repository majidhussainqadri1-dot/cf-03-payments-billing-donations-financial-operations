# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical financial owner for the **Sabri Social Homeopathy Platform**.

> **Source status:** `1.0.0-rc.3`, schema `2.0.0`, implements the Definitive Master Plan v3.0, CF-03 Complete Master Plan v1.0 and Founder Decision `SSH-FIN-2026-08-04-01`. The planned source, domain, persistence, REST-contract, security and automated-QA scope is implemented. Live financial collection remains fail closed pending external acceptance.

## Governing financial law

- Registration, general membership, public knowledge, basic education and all core platform services are free.
- Earlier membership, education, AI and platform-service prices remain versioned historical definitions but are dormant and non-collectible.
- Clinic and Marketplace platform commission remains **0%**.
- Donation is voluntary, default-off and never affects access, ranking, verification, moderation, visibility, support, clinic, marketplace, education or clinical access.
- Donation choices are USD 10, USD 14, USD 50 and positive custom USD; no amount or recurrence is preselected.
- Paid-product activation has no runtime boolean bypass. A later fee requires a new Founder Change-Control release.
- Hosted/tokenized providers only; PAN, CVV, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or stored.
- Browser returns never prove payment success.

## Implemented dual-plan source scope

- Versioned product and price staging, approval, activation, dormancy and retirement with separation of duties and optimistic concurrency.
- Complete payment-intent aggregate, exact integer minor-unit money, idempotency, expiry, immutable provider references and trusted state transitions.
- Raw-body HMAC webhook verification, timestamp/replay controls, provider-event uniqueness and unknown-state quarantine.
- Trusted payment settlement requiring exact provider event type, intent/provider/amount parity, exact ledger parity, balanced posting and outbox event.
- Append-only, source-idempotent, double-entry ledger; controlled reversals and three-person adjustments.
- Explicit recurring consent, renewal parity, subscription lifecycle, bounded dunning, quiet hours and provider-outage distinction.
- Signed AI usage authorization, integer metering and user-approved hard caps; currently dormant under the free-platform decision.
- Refund eligibility, denial, approval, execution, uncertain state, provider reconciliation and closure with requester/reviewer/executor separation.
- Chargeback deadlines, evidence, provider outcomes, fees and ledger adjustment.
- Donation intent, trusted settlement, receipt, refund/chargeback facts, privacy projection and non-privilege law.
- Settlement batches, line integrity, reconciliation exceptions, materiality, dual-control finance close, lock and reopen.
- Bounded explainable fraud review with allowlisted signals, manual decision and appeal.
- Scoped asynchronous encrypted exports, formula neutralization, expiry/revocation, retention classes, legal holds and restore replay reconciliation.
- Versioned past-tense event envelopes, leased/retryable outbox and tamper-evident audit chain.
- Canonical route catalogue and WordPress REST contracts; every mutation remains fail closed while collection is inactive.
- Complete additive relational schema `2.0.0` with 28 canonical owner tables and non-destructive uninstall.

## Weekly donation appeal

The source enforces logged-in server-side state, privacy-safe guest keys, seven-day cap and snooze, thirty-day post-donation suppression, active-monthly-donor suppression, thirty-second/meaningful-interaction threshold, sensitive-context exclusion, one prompt per page view, and no same-session repetition after checkout failure. Files 20 and 25 remain shell/presentation owners.

## QA

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
php tests/run-free-donation-policy.php
php tests/run-plan-completion.php
php tests/run-adversarial-2.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

The complete matrix runs on PHP 8.1, 8.2 and 8.3, validates manifests, scans credential material and verifies deterministic package parity.

## External acceptance gates

Live collection still requires a selected hosted/tokenized provider, legal entity and receiving account, qualified legal/tax/accounting review, PCI responsibility validation, independent security testing, Hostinger staging, real-browser/RTL/accessibility/performance evidence, backup/restore/provider-exit/rollback drills, operational staffing and explicit Founder live-collection approval.
