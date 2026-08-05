# CF-03 Database Migration and Rollback Design — Schema 3.2.0

## Current status

CF-03 now has the **implemented additive 31-table schema** for WordPress. `WordPressSchemaInstaller` uses `dbDelta`, then verifies each canonical table and every required column before publishing the installed schema version. Runtime collection remains fail closed independently of schema presence.

## Schema lineage

- `Schema::VERSION = 2.0.0`: historical 28-table financial base;
- `TransparencySchema`: expenses, transparency snapshots and donor acknowledgments;
- `RuntimeSchemaExtension::VERSION = 3.2.0`: runtime integrity columns and indexes;
- `CompleteSchema::VERSION = 3.2.0`: composed 31-table schema.

The runtime extension adds or strengthens:

- optimistic `record_version` for recurring consent;
- unique immutable ledger `source_ref`;
- explicit reconciliation `resolution_ref`;
- persisted bounded export specifications;
- settlement importer/poster evidence;
- serialized audit-chain uniqueness.

## Canonical data law

- Money uses validated integer minor units; floats are rejected at persistence boundaries.
- Currency uses three-letter uppercase codes.
- Provider identities are mappings and never replace platform actor identity.
- Core state, money and ownership fields remain typed columns.
- JSON is limited to bounded, versioned snapshots, event payloads and evidence envelopes.
- Posted ledger transactions and entries are append-only; corrections use referenced reversals or adjustments.
- Public transparency is derived from canonical ledger/expense evidence, not manually invented totals.

## Installation sequence

1. Confirm WordPress database and upgrade APIs.
2. Set runtime status to schema-installing and keep mutations closed.
3. Execute additive table/column/index definitions through `dbDelta`.
4. Verify every canonical table with `SHOW TABLES LIKE`.
5. Verify every required column with `SHOW COLUMNS` and top-level SQL parsing.
6. Record schema version, base version, migration IDs and completion time.
7. Preserve provider/runtime feature gates in their prior fail-closed state.
8. Run integrity, backup/restore and staging acceptance before any activation.

## Upgrade rules

- Migration identifiers and checksums are idempotent; checksum drift is a blocking defect.
- Historical product, price, provider event, ledger, receipt, refund, expense and audit evidence is not rewritten to match later policy wording.
- New columns are additive and receive safe defaults or explicit backfill evidence.
- A schema upgrade does not itself enable checkout, webhooks, refunds or downloads.
- Upgrade must be tested from both a fresh database and the immediately previous supported schema.

## Concurrency and integrity

- Unique database constraints enforce provider-event and idempotency deduplication.
- Compare-and-swap uses `record_version` for mutable workflows.
- Ledger source references are unique.
- Settlement import/post and finance-period review/close preserve separate actor evidence.
- Audit chain append is serialized and rejects forks.
- Outbox processing uses bounded leases, attempts and dead-letter state.

## Rollback law

Rollback closes new mutations first. It never deletes posted financial history, audit events, provider facts, receipts, refunds, settlement evidence or transparency source evidence. Additive schema may remain dormant until a separately approved data-preserving decommission migration exists.

A production rollback requires:

1. incident declaration and path-specific kill switches;
2. verified backup and restore point;
3. provider-side collection suspension;
4. reconciliation of all uncertain events;
5. migration checksum and row-count evidence;
6. restore rehearsal and integrity-manifest parity;
7. Founder-approved disposition of dormant schema.

## Uninstall boundary

Ordinary WordPress uninstall does not purge financial or audit data. Any future erasure/decommission workflow must honor retention schedules, legal holds, accounting obligations, provider evidence and explicit Founder approval.
