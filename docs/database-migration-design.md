# Database Migration Design — Foundation 0.2.0

## Status

This is a **design contract only**. Version `0.2.0` creates no financial tables and runs no schema migration. Table creation is blocked until activation change-control, data-flow review, security/privacy acceptance, migration dry run, backup/restore proof, rollback rehearsal, and Founder approval are complete.

## Planned owner tables

Prefix examples below use `wp_sabri_cf03_`; the runtime must derive the actual WordPress prefix safely.

| Table | Canonical purpose | Critical uniqueness/invariants |
|---|---|---|
| `products` | stable financial product and policy registry | unique `product_id`; donation has no entitlement mapping; approval/version fields |
| `price_versions` | immutable approved/draft price snapshots | unique `(product_id, version_id)`; exact integer minor units; region/currency/effective range |
| `payment_intents` | local command and provider state | unique `intent_id`; unique scoped idempotency claim; optimistic `record_version`; trusted amount snapshot |
| `provider_events` | verified/quarantined provider facts | unique `(provider_code, provider_event_id)`; raw-body SHA-256; signature key version; no raw card data |
| `idempotency_records` | durable command deduplication | unique `(scope, idempotency_key)`; immutable actor/request fingerprint; terminal result reference |
| `ledger_transactions` | immutable transaction groups | unique `transaction_id`; effective/recorded time; source, actor, reason and reversal reference |
| `ledger_entries` | debit/credit entries | positive integer minor units; currency; source reference; no update/delete after posting |
| `outbox` | replay-safe facts for File 00/File 19/File 24 | unique event ID; aggregate sequence; payload schema/version; delivery attempts |
| `audit_events` | tamper-evident finance audit envelopes | unique event ID; actor/action/object/purpose/outcome/correlation; privacy-minimized metadata |
| `migration_registry` | idempotent schema/data migration evidence | unique migration ID; checksum; started/completed/rolled-back state |

Invoices, subscriptions, refunds, disputes, settlements, reconciliation exceptions, finance close and exports will receive separate owner tables only when their application phases begin; no generic JSON mega-table will replace explicit ownership.

## Data types and time law

- Money: signed database integer large enough for validated non-negative minor units; application rejects overflow and negative values.
- Currency: fixed three-letter uppercase code with approved metadata registry.
- Hashes: fixed-length binary or lowercase hexadecimal with strict length.
- Timestamps: UTC with microsecond precision where supported; application emits explicit timezone offsets at boundaries.
- IDs: opaque canonical IDs; provider IDs are mappings, never platform user identity.
- JSON: limited to versioned, bounded metadata/event envelopes; core money/state columns remain typed and indexed.

## Mutability law

- Products/configuration may be superseded by a new version, not silently rewritten for historical transactions.
- Approved price versions are immutable.
- Posted ledger transactions/entries are append-only; corrections are reversal/adjustment transactions.
- Provider events and outbox facts are append-only with dedupe/replay status.
- Closed periods cannot be edited; later corrections post to an open period with references.

## Index and concurrency baseline

- Unique constraints, not application checks alone, enforce idempotency and provider-event deduplication.
- Payment-intent writes require expected `record_version`.
- Outbox claims use bounded leases/attempt counters and replay-safe consumers.
- Queries are owner/user/status/date scoped and cursor paginated; no unbounded finance export on request threads.
- Ledger balance verification runs inside the same database transaction as posting.

## Migration sequence

1. Preflight environment/version/capability/storage checks.
2. Verified backup and restore proof.
3. Dry-run inventory and collision report.
4. Create additive schema with feature flags closed.
5. Seed policy/product records through approved commands.
6. Dual-read/shadow verification where legacy financial references exist.
7. Enable bounded internal writes in sandbox/staging only.
8. Reconciliation and rollback rehearsal.
9. Founder acceptance before any production activation.

## Rollback law

Schema rollback never deletes posted financial history. Feature flags close new writes; adapters stop checkout/refund commands; uncertain provider facts remain quarantined; additive schema may remain dormant until a separately approved data-preserving rollback or decommission migration is executed.
