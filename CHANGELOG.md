# Changelog

## 0.2.0 — Product, Price, Evidence and Contract Foundation

- Replaced boolean-only activation approval with a versioned evidence record bound to a configured SHA-256.
- Added expiry, evidence-identity, provider-mode, module-version, and duplicate-evidence validation.
- Added financial product and billing-type invariants for education membership, AI add-on/usage, courses/programs, donations, and approved future products.
- Added immutable price versions, policy-version parity, effective-date resolution, historical snapshot hashing, and approved-overlap rejection.
- Added server-resolved checkout commands, safe same-origin return paths, and provider-host allowlisting.
- Added deterministic idempotency records that reject key reuse with changed requests and prohibit floating-point money payloads.
- Added trusted provider-evidence checks for signature, replay window, event uniqueness, provider, intent, amount, and unknown-state quarantine.
- Added File 00 financial-fact contract objects that cannot directly grant or revoke entitlement.
- Added privacy-minimized audit envelopes and sensitive-key rejection.
- Added provider, idempotency, audit, and File 00 contract interfaces.
- Added database migration design, contract manifests, activation-evidence schema, and expanded traceability.
- Expanded adversarial/domain tests from 12 to 39 with zero local failures after two review-and-fix rounds.

Runtime payment collection remains disabled; no provider adapter, webhook route, durable financial database, subscription, refund, invoice, reconciliation, or production activation is included.

## 0.1.0 — Foundation

- Added fail-closed WordPress bootstrap and activation-evidence gate.
- Added integer-minor-unit money value object.
- Added constitutional 0% Clinic and Marketplace commission policy.
- Added donation non-privilege policy.
- Added payment-intent transition validator with quarantine state.
- Added immutable balanced-ledger value objects.
- Added automated syntax, domain-invariant, and deterministic-package checks.
- Added architecture, security, and requirements-traceability documentation.
