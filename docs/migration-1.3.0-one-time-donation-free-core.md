# CF-03 1.3.0 Migration — One-Time Donation / Free Core

## Purpose

This migration reconciles the former source candidate with the newly governing central and CF-03 plans. It is intentionally **fail closed**: migration must never create a charge, recurring mandate, entitlement, AI quota sale or donation privilege.

## Source-state changes

1. Active donation product becomes only `donation.one_time`.
2. Legacy `donation.monthly` catalog records are marked retired when encountered.
3. New checkout refuses all monthly/recurring/subscription inputs.
4. Historical monthly prompt metadata is normalized to non-recurring one-time state and the old meta key is deleted after successful persistence.
5. Paid AI billing and subscription services become compatibility tombstones that throw rather than charge.
6. Active canonical schema becomes `4.0.0` and excludes:
   - `recurring_consents`;
   - `subscriptions`;
   - `usage_authorizations`;
   - `usage_facts`.

## Existing database law

The schema installer is additive/verification-oriented and does **not** silently DROP legacy financial tables during plugin activation/upgrade. An existing production/staging database may therefore still physically contain old tables after installing source `1.3.0-rc.1`.

Those tables are **legacy evidence stores**, not active canonical truth. This prevents destructive migration before retention, accounting, refund/dispute and provider reconciliation have been independently verified.

## Required legacy-data reconciliation before physical retirement

For each old recurring/subscription/paid-AI record set:

- count records and capture schema/checksum evidence;
- identify any provider-side continuing mandate/subscription independently of local state;
- stop/reconcile provider-side continuing mandates through an approved provider procedure if any exist;
- preserve receipts, refunds, disputes, settlements and legally/accountingly required evidence;
- verify no File 00 entitlement/access depends on a retired financial record;
- export/retain only minimum justified evidence under approved retention policy;
- run provider-authoritative reconciliation;
- obtain approved migration/retention sign-off;
- only then perform a separately reviewed physical table retirement if required.

## Rollback rule

Rollback from source `1.3.0-rc.1` must not re-enable recurring donations, paid AI, subscription renewal/grace/dunning, PKR fixed core fees or donor privileges. Any software rollback that would revive a superseded financial business rule is prohibited; recovery must preserve the current constitutional policy while restoring technical service.

## Staging acceptance

Before any Live migration, staging must demonstrate:

- fresh install creates active schema `4.0.0` only;
- upgrade leaves legacy tables non-authoritative and active routes cannot mutate them;
- legacy monthly/subscription/paid-AI attempts fail closed or quarantine;
- one-time donation happy path is idempotent;
- provider duplicate/out-of-order events do not double-post;
- refund/dispute/settlement evidence reconciles;
- backup/restore reproduces ledger and outbox without reviving a recurring mandate;
- rollback restores technical operability without restoring superseded business rules.
