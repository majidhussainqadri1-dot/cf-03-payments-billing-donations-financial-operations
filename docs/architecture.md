# CF-03 Architecture — Current Constitutional Summary

## Status boundary

This repository is the source implementation candidate for the current CF-03 governing baseline. It is not, by source presence alone, an activated payment processor. Live collection and financial delivery remain fail closed until separately evidenced.

## Constitutional layer

1. Founder-owned; not a Trust, charitable trust, welfare trust or trust fund.
2. Fixed registration, membership, education, AI, listing, verification, publishing and core-platform fees are prohibited under current law.
3. Clinic and Marketplace platform commission is 0%.
4. Public donations are voluntary **one-time only**, default-off, non-privileged and never recurring/automatic.
5. File 00 owns entitlement; CF-03 owns financial truth and emits past-tense financial facts only.
6. Public transparency is aggregate, verified and privacy-minimized.
7. Future Expansion Pack 40 capabilities are coded fail-closed contracts; code presence does not activate them.

## Canonical ownership

CF-03 owns financial policy, one-time donation intents, trusted provider facts, receipts, refunds, disputes, ledger, settlement, reconciliation, expenses, transparency snapshots, exports, retention and financial audit evidence. Retired recurring-consent, subscription and paid-AI constructs may exist only as bounded historical/migration evidence and must not become current truth.

It does not own identity/entitlement (File 00), global shell/download manager (File 20), visual/RTL/accessibility presentation (File 25), assurance governance (File 24), notification delivery (File 19), ranking/recommendations (File 26), support case orchestration (CF-02), secure media delivery (CF-04), or Clinic/Marketplace direct-deal funds.

## Runtime layers

1. WordPress bootstrap, capability registration, privacy callbacks, scheduler and Site Health.
2. Fail-closed automatic source/schema upgrade with bounded locking.
3. Historical `Schema::VERSION = 2.0.0` base plus transparency schema and current runtime extension.
4. Founder ownership, non-Trust, no-fixed-fee, voluntary one-time donation and 0% commission policy.
5. Seven-day minimum donation-prompt suppression plus page/session/context safeguards.
6. Hosted/tokenized one-time checkout with provider-safe subject minimization and durable idempotency checkpoint.
7. Signed provider webhooks, state mapping, ledger posting, receipts and outbox facts.
8. Refund, settlement, reconciliation, period-close, adjustment and incident operations.
9. Expense taxonomy, aggregate transparency and donor acknowledgment consent.
10. Secure exports/download grants, retention, audit chain, backup and restore evidence.
11. Future40 registry and fail-closed future governance/application contracts.

## Persistence architecture

`Schema::VERSION = 2.0.0` is historical provenance. The **active canonical schema is `4.0.0` with 27 canonical tables**. `RuntimeSchemaExtension::RETIRED_TABLES` removes `recurring_consents`, `subscriptions`, `usage_authorizations` and `usage_facts` from active canonical truth. Historical physical tables on an upgraded installation may remain only for bounded retention/audit/migration evidence; active code does not create or revive them.

Activation/upgrade verification includes safe table prefixes, required tables/columns/indexes/uniqueness, migration evidence, transparency source integrity and fail-closed handling of malformed or stale state.

## Transaction and idempotency model

The durable WordPress repository enforces bounded canonical collections, rejects floats and unknown fields, detects duplicate canonical identifiers and marks nested failures rollback-only. Updates and deletes require non-empty schema-backed predicates.

One-time donation checkout uses a local actor/scope-bound idempotency claim. After a hosted provider creates a session, the durable checkpoint precedes local financial completion. Retry/resume must verify provider/session/request parity; public responses do not expose provider internals.

## Collection activation model

Live public readiness requires, at minimum, exact installed schema/source parity, an approved hosted/tokenized provider, healthy trusted webhook path, legal/tax/accounting and PCI acceptance, independent security acceptance, staging acceptance, rollback/restore evidence, cross-file contract acceptance and explicit Founder Live approval. Any malformed runtime option, stale schema, corrupt incident state, unknown prompt context or unpersisted guest identity fails closed.

## Transparency and privacy

Published transparency is aggregate-only and hash-bound. Donor identities, private receipts, provider references, credentials, card/bank information and incident evidence are excluded from public projections. Provider-facing subjects remain minimized; sensitive webhook headers are stripped; privacy export is bounded/paginated; mandatory financial/audit records follow retention law.

## Future Expansion Pack 40

`CF03-FUTURE40-2026-09-08` registers exactly 40 coded future capabilities. FX-01..FX-37 and FX-40 are fail-closed future contracts. FX-38 (Sharia Financial Classification Layer) and FX-39 (Waqf and Grant Sustainability Module) additionally require separate Founder/Sharia/legal/accounting/operational Change-Control. None are Live merely because source code exists.

## Packaging and QA

Current acceptance includes PHP syntax scanning, current governing-plan functional/adversarial suites, Future40 acceptance, manifest validation, credential-material scanning, constitutional source assertions and deterministic package/source parity across supported PHP versions.

## External boundary

Repository/CI success is not staging or Live evidence. No deployed DB/schema, provider acceptance, legal/tax/accounting/PCI approval, penetration-test acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, backup/restore drill or Founder Live activation is inferred without separate evidence.
