# CF-03 — Payments, Billing, Donations and Financial Operations

Conditional financial owner for the Sabri Social Homeopathy Platform.

> **Source status:** `1.0.0-rc.1` implements the approved code-level conditional scope. Runtime payment collection remains disabled. This is not provider, legal, tax, accounting, PCI DSS, Hostinger staging, production or operational acceptance.

## Constitutional rules

- PKR 400/month structured education membership is a distinct recurring product.
- Sabri Classical Homeopathy AI is a separate paid add-on or metered-usage product.
- Donations are voluntary, default-off and confer no ranking, verification, visibility, moderation, support or basic-service privilege.
- Clinic and Marketplace platform commission is hard 0%.
- Hosted/tokenized payment providers only; no PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials or provider secrets are accepted or stored.
- File 00 owns entitlement and grace decisions; CF-03 emits past-tense financial facts only.
- Browser redirects never prove payment success.

## Implemented source domains

Products and immutable prices; disclosures; hosted checkout contracts; idempotency; signed provider evidence; payment states; immutable balanced ledger; invoices/receipts; subscriptions and grace facts; refunds with separation of duties; disputes/settlements/reconciliation; finance close; minimized exports; incident kill switches; backup/restore parity; additive persistence schema; outbox/audit contracts.

## External gates

Qualified legal/tax/accounting review, selected provider and PCI responsibility validation, provider sandbox, independent security testing, Hostinger staging, browser/RTL/accessibility/performance evidence, restore/provider-exit/rollback drills, operational staffing and Founder release approval remain mandatory.

## Development

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```
