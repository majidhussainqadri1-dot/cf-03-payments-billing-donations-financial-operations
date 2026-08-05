# CF-03 Database Migration Design — 1.2.0-rc.2

## Current status

CF-03 now contains an implemented additive 31-table schema. Historical `Schema::VERSION = 2.0.0` remains the 28-table base; `TransparencySchema` adds three owner tables; `RuntimeSchemaExtension::VERSION = 3.3.0` defines the current complete shape.

Schema implementation does not activate financial collection. Runtime remains fail closed until all external activation gates pass.

## Owner tables

The canonical tables cover products, price versions, payment customer references, payment intents, provider events, ledger transactions/entries, recurring consents, subscriptions, AI usage authorization/facts, invoices, refunds, chargebacks, donations, settlement batches/lines, reconciliation exceptions, finance periods, adjustments, fraud reviews, export jobs, idempotency, outbox, audit, retention ledger, provider registry, migrations, expenses, transparency snapshots and donor acknowledgments.

## Upgrade path

`Plugin::maybeUpgrade()` runs on early `init` when source or schema identity differs. It:

1. acquires a bounded WordPress upgrade lock;
2. records an in-progress fail-closed status;
3. forces runtime mode to `preparing`;
4. disables webhook and download activation flags;
5. executes additive `dbDelta` statements;
6. verifies all canonical structures;
7. records source, schema and migration evidence;
8. releases the lock.

Failure retains preparing mode, disabled mutation/delivery flags and a failed upgrade status. No destructive rollback is attempted automatically.

## Structural verification

For every table, the installer verifies:

- safe physical table identifier;
- escaped `SHOW TABLES LIKE` lookup;
- exact required columns;
- primary key;
- named secondary indexes;
- index column order;
- unique versus non-unique semantics.

The installer fails closed if any table, column or required index is missing or malformed.

## 3.3.0 migration details

- recurring consents receive `record_version`;
- ledger source references become unique;
- reconciliation exceptions receive `resolution_ref`;
- export jobs receive bounded `specification_json`;
- settlement batches receive importer/poster identities and posting time;
- finance periods receive review time;
- audit entries serialize `previous_hash` uniqueness;
- adjustments receive explicit debit/credit accounts;
- transparency snapshots receive `snapshot_hash`;
- transparency period-only uniqueness is replaced by `(period_key,currency)`.

## Transparency migration

Before publishing schema completion, existing transparency rows are inspected. The migration:

- removes the legacy period-only unique index when present;
- validates JSON shape and source hash;
- requires the source hash inside the snapshot payload to equal the row source hash;
- canonicalizes JSON deterministically;
- backfills or verifies the independent snapshot SHA-256;
- verifies the new period/currency unique index.

Corrupt existing evidence blocks the upgrade rather than being silently rewritten.

## Data and concurrency law

- Money uses validated integer minor units; floats are rejected.
- Core money/state/reference fields remain typed columns.
- JSON is restricted to approved bounded metadata/snapshot fields.
- Approved prices and posted financial evidence are immutable.
- Ledger corrections use reversal/adjustment transactions.
- Provider events and outbox facts are idempotent and replay-safe.
- Canonical reads detect duplicate identities.
- Nested transaction failures mark the outer transaction rollback-only.
- Updates/deletes require non-empty schema-backed criteria.
- Exports and privacy reads are bounded and paginated.

## Rollback law

Rollback closes features and mutation flags but does not delete posted financial history. Additive schema may remain dormant. Any data-shape correction requires a separately approved, evidence-preserving migration. Ordinary uninstall does not drop financial or audit tables.

## External acceptance

Fresh-install and upgrade verification must still be performed on Hostinger staging with backup/restore proof, role testing, browser testing, rollback rehearsal and explicit Founder acceptance before any production activation.
