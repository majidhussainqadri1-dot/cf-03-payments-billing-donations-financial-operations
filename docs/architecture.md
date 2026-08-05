# CF-03 Architecture — 1.2.0-rc.1

## Status boundary

This release is the repository-source implementation of the three governing plans and Founder decision `SSH-FIN-DONATION-2026-08-04-01`. It is not a Live payment processor. Runtime financial mutations default to fail closed until all external gates and an approved provider are configured.

## Constitutional layer

1. The platform is Founder-owned by Dr. Allamah Majid Hussain Sabri Muhaddith Murshid.
2. It is not a Trust, charitable trust, welfare trust or trust fund.
3. Fixed registration, membership, education, AI, listing, verification, publishing and core-platform fees are prohibited under the active decision.
4. Clinic and Marketplace platform commission is 0%.
5. Donations are voluntary and create no access, ranking, verification, publishing, governance, ownership or other privilege.
6. Public aggregate transparency and separate Founder-payment disclosure are mandatory.

## Canonical ownership

CF-03 owns financial policy, donation intents, provider facts, recurring consent, receipts, refunds, donation and expense ledgers, reconciliation, transparency snapshots, exports and financial audit evidence.

It does not own identity/entitlement (File 00), global-shell mounting (File 20), visual/RTL/accessibility presentation (File 25), assurance governance (File 24), notification delivery (File 19), ranking (File 26), case orchestration (CF-02), secure media/file delivery (CF-04), or Clinic/Marketplace direct-deal funds.

## Runtime layers

1. WordPress bootstrap, granular capabilities, additive schema installation and Site Health.
2. Founder ownership, non-Trust and no-fixed-fee policy.
3. Product/price historical governance and hard non-donation checkout rejection.
4. Monthly Donation Appeal policy with calendar-month, 30-day, page and session limits.
5. Donation intent, explicit recurring consent, hosted checkout, provider facts, receipts and refunds.
6. Immutable double-entry ledger, settlement import and reconciliation.
7. Finance-period review, close, reopen and controlled adjustments.
8. Approved expense taxonomy, aggregate transparency and donor acknowledgment consent.
9. Secure exports, audience-bound download grants and privacy export/erasure boundaries.
10. Fraud review, chargebacks, retention/legal holds and incident containment.
11. Outbox, audit hash chain, backup manifest, restore reconciliation and operational integrity.
12. Versioned cross-file and REST contracts.

## Persistence architecture

`Schema::VERSION = 2.0.0` remains the historical 28-table base. `TransparencySchema` adds expenses, transparency snapshots and donor acknowledgments. `RuntimeSchemaExtension::VERSION = 3.2.0` applies the current integrity additions, including recurring-consent optimistic versioning, unique immutable ledger source references, reconciliation resolution evidence, export specifications, settlement actor evidence and serialized audit-chain constraints.

`CompleteSchema::VERSION = 3.2.0` composes all 31 canonical tables. Activation runs additive `dbDelta` migrations and verifies every required table and column before publishing the schema version.

## Public and private diagnostics

Public policy/transparency responses contain only policy facts and a redacted runtime state. They do not expose provider identity, activation-gate maps, missing-gate names, incident identifiers, reasons, operators or internal evidence.

Authorized finance-health endpoints retain full runtime/provider/incident diagnostics. Public operational conflicts are generic and do not reveal internal activation or incident details.

## Donation and webhook request boundary

- Public donation JSON bodies are bounded before parsing/application processing.
- Webhook bodies are limited to one megabyte and headers are count-, name- and value-bounded.
- Provider adapters must verify signatures, replay windows and normalized payloads.
- Browser return paths never establish settlement.
- Public guest references are first-party, HttpOnly, SameSite and limited to 30 days.

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

`/transparency/` serves only a verified published aggregate snapshot. When none exists it returns `not_published` with `snapshot: null`; it never invents figures. Public data excludes donor identity, bank/card details, payment credentials, private invoices, provider secrets and security-sensitive vendor evidence.

Public donor acknowledgment requires explicit, revocable consent and grants no privilege.

## Activation governance

Live donation collection requires matching module-version evidence, Founder approval, legal entity and receiving account, legal/tax/accounting and PCI review, an approved hosted/tokenized provider, independent security acceptance, Hostinger staging acceptance, backup/restore and rollback evidence, operations readiness and explicit Founder Live activation.

## Packaging

The build script creates deterministic `1.2.0-rc.1` WordPress packages with one canonical top-level folder and SHA-256 evidence. Repository tests, GitHub workflow files, build scripts and temporary probe artifacts are excluded from the installable package.
