# CF-03 — Payments, Billing, Donations and Financial Operations

Conditional financial owner for the **Sabri Social Homeopathy Platform**.

> **Source status:** `1.0.0-rc.2` implements Founder Decision `SSH-FIN-2026-08-04-01`. The platform is currently completely free. Membership, education, AI and platform-service charges are dormant and non-collectible. Only voluntary-donation infrastructure is in scope, and live collection remains disabled pending provider, legal, security, staging and Founder acceptance.

## Governing financial policy

- Registration, general membership, public knowledge, basic education and core platform services are free.
- The previous PKR 400 education membership, AI add-on/usage charges and all other platform fees are retained only as dormant historical product/price definitions; checkout is hard-blocked.
- Clinic and Marketplace platform commission remains **0%**.
- Donation is voluntary, has no preselected amount or recurrence and never affects access, visibility, verification, ranking, moderation, support, clinic, marketplace or any core service.
- Suggested donation amounts are **USD 10, USD 14, USD 50**, plus a positive custom USD amount.
- Monthly donation requires an explicit unchecked-by-default consent.
- A donation appeal may be shown at most once in seven days; completed one-time donations suppress it for at least thirty days; active monthly donors do not receive the general appeal.
- Hosted/tokenized payment providers only; no PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials or provider secrets are accepted or stored.
- File 00 owns entitlement and grace decisions; CF-03 emits past-tense financial facts only.
- Browser redirects never prove payment success.

## Weekly donation appeal contract

The CF-03 source owns donation amounts, donation intent, recurring mandate, provider evidence, receipt/refund and ledger facts. It exposes a presentation-neutral bilingual contract for Files 20 and 25. File 20 decides safe mount context and timing; File 25 owns the accessible responsive modal; File 24 receives assurance evidence without replacing CF-03 native enforcement.

The source enforces:

- logged-in server-side state fields required by the Founder decision;
- privacy-safe guest first-party keys `sabri_donation_prompt_seen` and `sabri_donation_prompt_next_at`;
- no prompt on login, registration, password recovery, guardian consent, clinical consultation, emergency warning, support appeal or payment-error surfaces;
- at least 30 seconds of page engagement or a meaningful interaction;
- one prompt per page view and no same-session repetition after checkout failure;
- seven-day snooze for Remind Me Later, Not Now and Close;
- thirty-day suppression after a completed one-time donation;
- indefinite general-prompt suppression while monthly donation remains active.

## Implemented source domains

Free-platform financial policy; dormant product/price preservation; weekly donation prompt state and policy; bilingual appeal contract; consent-safe donation intent; hosted donation provider port; exact money; strict idempotency and provider evidence; immutable balanced ledger; receipts/refunds; reconciliation; finance close; secure export; incident controls; backup parity; additive persistence schema; outbox/audit contracts.

## External gates

Live donation collection requires a selected hosted/tokenized provider, receiving legal entity/account, qualified legal/tax/accounting review, PCI responsibility validation, provider sandbox, independent security testing, Hostinger staging, browser/RTL/accessibility/performance evidence, restore/provider-exit/rollback drills, operational staffing and Founder release approval.

## Development

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
php tests/run-free-donation-policy.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```
