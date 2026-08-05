# CF-03 Architecture — 1.2.0-rc.3

## Status boundary

This release is the repository-source implementation candidate for the three governing plans and Founder decision `SSH-FIN-DONATION-2026-08-04-01`. It is not an activated payment processor. Live collection and financial delivery remain disabled and fail closed.

## Constitutional layer

1. Founder-owned; not a Trust, charitable trust, welfare trust or trust fund.
2. Fixed registration, membership, education, AI, listing, verification, publishing and core-platform fees are prohibited under the active decision.
3. Clinic and Marketplace platform commission is 0%.
4. Donations are voluntary, one-time or monthly, default-off and non-privileged.
5. File 00 owns entitlement; CF-03 owns financial truth and emits facts only.
6. Public transparency is aggregate, verified and privacy-minimized.

## Canonical ownership

CF-03 owns financial policy, donation intents, provider facts, recurring consent, invoices/receipts, refunds, disputes, ledger, settlement, reconciliation, expenses, transparency snapshots, exports, retention and financial audit evidence.

It does not own identity/entitlement (File 00), global shell/download manager (File 20), visual/RTL/accessibility presentation (File 25), assurance governance (File 24), notification delivery (File 19), ranking/recommendations (File 26), support case orchestration (CF-02), secure media delivery (CF-04), or Clinic/Marketplace direct-deal funds.

## Runtime layers

1. WordPress bootstrap, capability registration, privacy callbacks, scheduler and Site Health.
2. Fail-closed automatic source/schema upgrade with a bounded upgrade lock.
3. Historical `Schema::VERSION = 2.0.0` base.
4. Founder ownership, non-Trust, no-fixed-fee, voluntary-donation and 0% commission policy.
5. Donation prompt policy, canonical user state and presentation-neutral contracts.
6. Hosted donation checkout with provider-safe subject minimization and durable idempotency checkpoint.
7. Signed provider webhooks, state mapping, ledger posting, receipts and outbox facts.
8. Refund, recurring-donation, settlement, reconciliation, period-close, adjustment and incident operations.
9. Expense taxonomy, canonical aggregate transparency and donor acknowledgment consent.
10. Secure exports/download grants, retention, audit chain, backup and restore evidence.

## Persistence architecture

`Schema::VERSION = 2.0.0` remains the historical 28-table base. `TransparencySchema` adds three tables:

- `sabri_cf03_expenses`;
- `sabri_cf03_transparency_snapshots`;
- `sabri_cf03_donor_acknowledgments`.

`RuntimeSchemaExtension::VERSION = 3.3.0` applies deterministic additive integrity changes, and `CompleteSchema::VERSION = 3.3.0` composes all 31 canonical tables.

Activation/upgrade verifies:

- safe WordPress table prefix;
- all 31 table names;
- all required columns;
- primary and named secondary indexes;
- index column order;
- uniqueness requirements;
- transparency legacy-index removal;
- existing transparency source evidence;
- transparency snapshot-hash backfill.

## Transaction and idempotency model

The durable WordPress repository enforces bounded canonical collections, rejects floats and unknown fields, detects duplicate canonical identifiers and marks nested failures rollback-only. Updates and deletes require non-empty schema-backed predicates.

Donation checkout uses a local idempotency claim. After the hosted provider creates a session, the claim enters `provider_created` before local intent, consent and donation records are committed. A retry resumes the checkpointed provider session and verifies provider/session/request parity. Public responses do not expose provider internals.

## Collection activation model

`RuntimeConfiguration::missingDonationCollectionGates()` extends ordinary financial gates with webhook enablement and endpoint acceptance. Live public readiness additionally requires:

- exact installed schema version;
- registered donation adapter;
- matching healthy payment adapter;
- incident checkout path enabled;
- incident webhook path enabled;
- explicit Founder Live approval when runtime mode is Live.

Any malformed runtime option, stale schema, corrupt incident state, failed upgrade, unknown prompt context or unpersisted guest identity fails closed.

## Transparency integrity

A published transparency snapshot is keyed by period and currency and contains the canonical source hash inside the payload. The row also stores an independent `snapshot_hash` over canonical JSON. Publication reuse, public read and download generation verify both hashes. A mismatch is an integrity failure, not a display warning.

## Privacy architecture

- Provider-facing donation drafts use `donor:withheld`.
- Public policy output redacts provider, gate and incident diagnostics.
- Public checkout responses use a strict allowlist.
- Webhook forwarding strips authorization, cookies, nonce and related sensitive headers.
- Guest identity requires a persisted 30-day first-party HttpOnly SameSite cookie.
- Logged-in prompt state uses six canonical server-side fields.
- Unknown page contexts are suppressed.
- Privacy export is paginated and reports safe failure instead of silently omitting records.
- Mandatory financial/audit records are retained; optional prompt state and public acknowledgment are erased or revoked.

## Packaging and QA

The deterministic package contains one canonical plugin root, excludes tests, repository automation and temporary/editor artifacts, and is built twice for SHA-256 parity. Fourteen suites execute 404 tests per PHP version and 1,212 test executions across PHP 8.1–8.3.

## External boundary

No approved Live provider adapter, legal/tax/accounting acceptance, PCI acceptance, independent security acceptance, Hostinger staging acceptance, browser/RTL/accessibility/performance acceptance, backup/restore drill or Founder Live activation is represented by this source candidate.
