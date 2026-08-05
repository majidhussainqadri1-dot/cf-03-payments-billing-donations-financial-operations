# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Never place credentials, provider secrets, webhook signing keys, card data, personal identity evidence, financial records, activation evidence or incident playbooks in a public issue.

## Current status

CF-03 `1.2.0-rc.2` contains implemented source routes and provider-neutral contracts, but it does **not** contain an approved Live payment-provider adapter, Live credentials, an enabled collection configuration or a production activation record. Real collection remains fail closed.

Founder decision `SSH-FIN-DONATION-2026-08-04-01` keeps core platform services free, Clinic/Marketplace commission at 0% and financial support voluntary. Donation creates no access, entitlement, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI or clinical advantage.

## Non-negotiable controls

- Non-donation checkout is prohibited while the active free-platform decision remains in force.
- Donation checkout requires all financial gates, trusted webhook readiness, exact provider-registry parity, healthy provider status, incident-path availability and exact installed schema identity.
- Provider adapters receive a withheld provider-facing subject, not the canonical platform donor reference.
- Public checkout responses exclude provider code, provider-session reference, activation details and incident details.
- Hosted or tokenized providers only; no embedded raw card collection.
- PAN, CVV, CVC, PIN, OTP, magnetic-stripe data, bank passwords, raw credentials and provider secrets are never accepted, stored, logged or exported.
- Browser redirects never prove payment success.
- Webhooks require provider signature verification, replay-window validation, event uniqueness, provider/intent/amount/currency parity and trusted-state mapping.
- Authorization, cookie, WordPress nonce and proxy-authorization headers are stripped before webhook headers reach provider adapters.
- Public request bodies and webhook bodies/headers are bounded; CRLF and NUL header values are rejected.
- Idempotency header and body values must match exactly.
- Provider session creation is durably checkpointed before canonical local financial records are committed.
- Nested financial transaction failures make the outer transaction rollback-only.
- Canonical financial reads fail closed on duplicate identities.
- Posted ledger records are immutable; corrections use balanced reversals or controlled adjustments.
- Transparency JSON is accepted and delivered only after source-hash and snapshot-hash verification.
- File 00 remains the entitlement authority; CF-03 never grants or revokes platform access.
- Unknown prompt contexts, corrupt runtime options, corrupt incident state, stale schema and failed upgrades all fail closed.
- Guest financial operations require a successfully persisted first-party HttpOnly SameSite cookie.
- Ordinary uninstall never drops financial or audit history.

## External acceptance required before Live

- Founder-approved activation change control;
- legal entity and receiving-account confirmation;
- legal, tax and accounting acceptance;
- PCI responsibility confirmation;
- approved hosted/tokenized provider and sandbox evidence;
- independent security and penetration-test acceptance;
- Hostinger staging, migration and rollback acceptance;
- File 00/File 20/File 24/File 25 integration acceptance;
- browser, mobile, RTL, accessibility and performance acceptance;
- backup, restore, provider-exit and incident-recovery drills;
- explicit Founder Live activation.
