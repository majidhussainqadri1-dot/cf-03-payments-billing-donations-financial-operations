# CF-03 Database Migration Design — Current Constitutional Summary

## Current status

CF-03 retains `Schema::VERSION = 2.0.0` as historical base provenance. The **active canonical schema is `4.0.0` with 27 canonical tables**. Runtime remains fail closed until external activation gates pass; repository-source presence alone does not activate collection.

## Active schema law

`RuntimeSchemaExtension::RETIRED_TABLES` removes the former recurring/subscription/paid-AI usage collections from active canonical truth:

- `recurring_consents`;
- `subscriptions`;
- `usage_authorizations`;
- `usage_facts`.

Upgraded installations may retain historical physical copies only for bounded audit/reconciliation/migration retention. Current code must not create, repopulate or interpret them as active business truth.

The active tables cover the current one-time-donation financial lifecycle: products/prices, customer/provider references, one-time payment intents and provider events, immutable ledger, invoices/receipts, refunds, disputes/chargebacks, donations, settlements/reconciliation, finance periods/adjustments, fraud/manual review, exports, idempotency, outbox/audit/retention/provider/migration evidence, expenses, transparency and donor acknowledgments.

## Upgrade path

The WordPress upgrade path remains fail closed. It acquires a bounded lock, records in-progress state, disables mutation/delivery activation, applies approved schema changes, verifies exact canonical structures and records source/schema/migration evidence before allowing readiness to progress. Failure must retain a non-collecting state; no destructive automatic rollback of financial history is allowed.

## Structural verification

Installer verification covers safe physical table identifiers, table presence, required columns, primary/secondary indexes, index ordering, uniqueness semantics and current migration invariants. Missing or malformed canonical structure blocks readiness.

## Schema 4.0 migration law

The 4.0 migration retires recurring-consent, paid-subscription and paid-AI-usage stores from the active canonical schema while preserving historical evidence non-destructively when required. Active product seeding is restricted to `donation.one_time`; legacy recurring/monthly products are retired rather than silently revived.

## Transparency migration

Transparency evidence remains aggregate, period/currency scoped and hash-bound. Existing rows are validated before being treated as publishable truth; corrupt evidence blocks upgrade/publication rather than being silently rewritten.

## Data and concurrency law

- Money uses validated integer minor units; floats are rejected.
- Core money/state/reference fields remain typed columns.
- JSON is restricted to approved bounded metadata/snapshot fields.
- Posted financial evidence is immutable; corrections use reversal/adjustment transactions.
- Provider events/outbox facts are idempotent and replay-safe.
- Canonical reads detect duplicate identities.
- Nested transaction failures mark the outer transaction rollback-only.
- Updates/deletes require non-empty schema-backed criteria.
- Exports/privacy reads are bounded and paginated.
- Browser returns never establish settlement.

## Future Expansion Pack 40

The Future40 amendment does **not** silently expand the active schema. Its capabilities are coded fail-closed contracts. Any future persistence expansion requires its own migration/versioning evidence and must preserve current constitutional rules. FX-38 and FX-39 additionally require separate Founder/Sharia/legal/accounting/operational Change-Control before activation.

## Rollback law

Rollback closes features and mutation flags but does not delete posted financial history. Dormant additive structures may remain when evidence-preserving. Any data-shape correction requires separately approved migration discipline.

## External acceptance

Fresh-install/upgrade verification, backup/restore proof, role testing, browser/RTL/accessibility testing, rollback rehearsal, provider/legal/tax/accounting/PCI/security acceptance and explicit Founder Live activation remain external evidence. GitHub green status must not be treated as deployed DB truth.
