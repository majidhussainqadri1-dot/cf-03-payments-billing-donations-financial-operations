# CF-03 — Payments, Billing, Donations and Financial Operations

Conditional financial operations module for the **Sabri Social Homeopathy Platform**.

> **Current implementation status:** Foundation `0.2.0`. Runtime payment collection remains disabled. This repository does not claim provider, legal, tax, accounting, PCI DSS, staging, production, or operational acceptance.

## Governing rules

- PKR 400 monthly structured education membership is a distinct recurring product.
- Sabri Classical Homeopathy AI is a separate add-on or metered-usage product; base membership does not silently include it.
- Donations are voluntary, have no default amount or recurrence, and never affect ranking, verification, visibility, moderation, support priority, badges, recommendations, or basic services.
- Clinic and Marketplace platform commission remains **0%**.
- Hosted or tokenized provider flows only.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials, or provider secrets may be stored, logged, exported, or accepted by support.
- File 00 remains the canonical entitlement authority; CF-03 emits financial facts only.

## Foundation 0.2.0 scope

This phase adds code-level contracts and invariants for:

- versioned, hash-bound activation evidence;
- approved product registration and immutable price snapshots;
- PKR 400 education membership separation from AI products;
- region/currency/effective-date price resolution and overlap rejection;
- server-resolved checkout commands and same-origin return paths;
- provider-neutral hosted-checkout contracts and checkout-host allowlisting;
- deterministic idempotency fingerprints and immutable idempotency records;
- signature/replay/uniqueness provider-evidence checks;
- unknown provider-state quarantine;
- File 00 financial-fact events without direct entitlement mutation;
- privacy-minimized audit envelopes and sensitive-key rejection;
- database migration design and versioned contract manifests.

No checkout REST route, provider implementation, webhook endpoint, durable database migration, subscription engine, refund executor, invoice renderer, settlement importer, reconciliation close, or live financial table is enabled in `0.2.0`.

## Activation remains fail-closed

Two independent configuration elements are required before the activation gate can ever report approval:

1. `SABRI_CF03_RUNTIME_ACTIVATION === true`;
2. `SABRI_CF03_ACTIVATION_EVIDENCE_HASH`, matching the canonical SHA-256 of the versioned activation record.

The record must contain distinct evidence references for Founder change-control, legal/tax/accounting review, PCI scope validation, independent security acceptance, staging acceptance, rollback rehearsal, and hosted/tokenized provider validation. Even a passing gate does not create runtime payment endpoints in this release.

## Development

```bash
php tests/run.php
php tests/run-0.2.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

Implementation proceeds through reviewed branches and draft pull requests. Source-code presence never implies financial activation approval.
