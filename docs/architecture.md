# CF-03 Architecture — 1.1.0-rc.1

## Status boundary

This release is the repository-source implementation of CF-03 Integrated Final Plan 2026 v2.0 and Founder decision `SSH-FIN-DONATION-2026-08-04-01`. It is not a live payment processor. Runtime collection is disabled and fail closed.

## Constitutional layer

1. The platform is Founder-owned by Dr. Allamah Majid Hussain Sabri Muhaddith Murshid.
2. It is not a Trust, charitable trust, welfare trust or trust fund.
3. Fixed registration, membership, education, AI, listing, verification, publishing and core-platform fees are prohibited under the active decision.
4. Clinic and Marketplace platform commission is 0%.
5. Donations are voluntary and create no access, ranking, verification, publishing, governance, ownership or other privilege.
6. Public aggregate transparency and separate Founder-payment disclosure are mandatory.

## Canonical ownership

CF-03 owns financial policy, donation intents, provider facts, recurring consent, receipts/refunds, donation and expense ledgers, reconciliation, transparency snapshots and financial audit evidence.

It does not own identity/entitlement (File 00), global-shell mounting (File 20), visual/RTL/accessibility presentation (File 25), assurance governance (File 24), notification delivery (File 19), ranking (File 26), or Clinic/Marketplace direct-deal funds.

## Governing layers

1. WordPress bootstrap, capabilities, additive schema installation and Site Health.
2. Founder ownership, non-Trust and no-fixed-fee policy.
3. Product/price historical governance and hard non-donation checkout rejection.
4. Monthly Donation Appeal policy with calendar-month, 30-day, page and session limits.
5. Donation intent, explicit recurring consent, provider facts, receipts, refunds and chargebacks.
6. Approved expense taxonomy with separate Founder-related categories.
7. Verified aggregate transparency snapshots and consent-based donor acknowledgment.
8. Hosted/tokenized provider evidence, signature/replay/idempotency controls and fail-closed settlement.
9. Append-only ledger, reconciliation, finance close, exports, retention, outbox and audit.
10. Versioned cross-file and REST contracts.

## Persistence architecture

`Schema::VERSION = 2.0.0` remains the immutable historical 28-table base. `TransparencySchema` adds:

- `sabri_cf03_expenses`;
- `sabri_cf03_transparency_snapshots`;
- `sabri_cf03_donor_acknowledgments`.

`CompleteSchema::VERSION = 3.0.0` composes all 31 tables. Activation verifies every table after `dbDelta` before publishing the schema version.

## Monthly Donation Appeal

- Maximum one prompt per calendar month.
- Remind Me Later, Not Now, Close and completed donation impose at least 30 days of suppression.
- Active monthly donors receive no general appeal.
- Sensitive authentication, clinical, emergency, support and payment-error contexts are excluded.
- No more than one prompt per page view or session.
- Suggested amounts are USD 10, USD 14, USD 50 and positive custom USD; amount and recurrence are unselected by default.

Logged-in fields: `last_donation_prompt_at`, `next_donation_prompt_at`, `donation_prompt_status`, `donation_prompt_snoozed_until`, `last_donation_completed_at`, `recurring_donation_status`.

Guest keys: `sabri_donation_prompt_seen_at`, `sabri_donation_prompt_next_at`.

## Transparency and privacy

`/transparency/` serves only a verified published aggregate snapshot. When none exists it returns `not_published` with `snapshot: null`; it never invents figures. Public data includes receipts, expenses, balance and separate Founder aggregates, but excludes donor identity, bank/card details, payment credentials, private invoices and security-sensitive vendor evidence.

Public donor acknowledgment requires explicit, revocable consent and grants no privilege.

## Activation governance

A live donation route still requires a matching module-version evidence record, Founder approval, legal entity/receiving account, legal/tax/accounting and PCI review, selected hosted/tokenized provider, independent security, Hostinger staging, rollback/restore evidence and explicit Live activation. Passing an evidence gate cannot create a provider route that is absent from this release.

## Packaging

The build script creates deterministic `1.1.0-rc.1` WordPress packages with one canonical top-level folder and SHA-256 evidence. Repository tests and CI-only files are excluded from the installable package.
