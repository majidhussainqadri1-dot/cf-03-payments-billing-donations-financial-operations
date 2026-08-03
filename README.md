# CF-03 — Payments, Billing, Donations and Financial Operations

Conditional financial operations module for the **Sabri Social Homeopathy Platform**.

> **Current implementation status:** Foundation `0.1.0`. Runtime payment collection remains disabled. This repository does not claim production, staging, provider, legal, tax, accounting, PCI DSS, or operational acceptance.

## Governing rules

- PKR 400 monthly structured education membership.
- Sabri Classical Homeopathy AI is a separate paid add-on or usage plan.
- Donations are voluntary and never affect ranking, verification, visibility, moderation, support priority, or basic services.
- Clinic and Marketplace platform commission remains **0%**.
- Hosted or tokenized provider flows only.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, or raw bank credentials may be stored, logged, exported, or accepted by support.
- File 00 remains the canonical entitlement authority.

## Foundation scope

This first coding phase establishes:

- a WordPress-safe plugin bootstrap;
- a fail-closed activation gate;
- integer-minor-unit money arithmetic;
- the constitutional 0% commission invariant;
- donation non-privilege validation;
- payment-intent transition validation;
- immutable, balanced ledger value objects;
- automated syntax and domain-invariant tests;
- initial architecture, security, and requirements-traceability documentation.

No checkout endpoint, provider adapter, webhook receiver, subscription engine, refund executor, finance export, or live payment table is enabled in `0.1.0`.

## Development

```bash
php tests/run.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

Implementation proceeds on reviewed branches and through pull requests. Source-code presence never implies activation approval.
