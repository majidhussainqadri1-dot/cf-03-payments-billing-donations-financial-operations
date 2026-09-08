# CF-03 1.2.0-rc.2 — Definition of Done

## Repository-source gates

The exact release commit is source-complete only when all are true:

- three governing plans and Founder decision are traceable;
- `RCD-022` is explicitly superseded;
- Founder-owned/non-Trust/no-fixed-fee/0%-commission/donation-non-privilege rules are enforced;
- donation prompt is monthly, 30-day suppressed, context-safe and stored in six canonical user fields;
- donation checkout requires financial and webhook readiness;
- configured provider is registered and healthy in both required provider registries;
- provider-facing drafts withhold canonical donor identity;
- idempotency header/body values match and provider-created state is durably checkpointed;
- public checkout/policy outputs redact provider, gate and incident internals;
- corrupt runtime/incident/prompt/schema state fails closed;
- durable repository detects duplicate identities, bounds mutations and propagates rollback-only state;
- complete schema `3.3.0` verifies all 31 tables, required columns, indexes and uniqueness;
- transparency records verify source and snapshot SHA-256 plus period/currency uniqueness;
- privacy export is paginated and erasure reports retained/failed items honestly;
- donation UI is disabled while collection readiness is false;
- browser amount parsing produces exact positive USD minor units with at most two decimals;
- fourteen suites pass on PHP 8.1, 8.2 and 8.3;
- 404 tests pass per PHP version and 1,212 aggregate executions pass;
- manifests, source assertions and prohibited-material scan pass;
- deterministic package parity passes on the exact head;
- third forty-round evidence contains exactly forty review/fix rows.

## External operational gates

Source completion does not establish Live collection. Operational completion requires legal entity/receiving account, legal/tax/accounting acceptance, PCI responsibility acceptance, approved provider and sandbox evidence, independent security acceptance, Hostinger staging acceptance, real-browser/mobile/RTL/accessibility/performance acceptance, cross-file integration, backup/restore/provider-exit/rollback drills, operations staffing and explicit Founder Live approval.

Until those gates pass, provider mutations and collection remain fail closed; the PR remains Draft.
