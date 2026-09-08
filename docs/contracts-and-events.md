# CF-03 Contracts and Events — Current Constitutional Summary

## Runtime maturity

These contracts are implemented over a durable WordPress repository, REST adapters, schedulers, outbox records and provider-neutral ports. Repository-source presence does not establish Live provider or activation evidence.

## Donation provider contract

`DonationPaymentProvider` supports provider identity, currencies, health, hosted one-time donation checkout create/resume, webhook verification, trusted donation evidence, refund and settlement retrieval. Runtime requires configured-provider parity and health before financial mutation.

A provider receives a minimized provider-safe donation draft; canonical actor identity remains local. Public donation is one-time only. Recurring/monthly mandate or automatic-repeat fields are rejected/fail closed.

## Idempotency and hosted checkout

- Header/body idempotency values must match where both are supplied.
- Actor/scope/request identity remains stable across retries.
- Provider session creation is followed by a durable `provider_created` checkpoint before canonical completion.
- Replay/resume verifies request and provider/session parity.
- Changed input under the same key is rejected.
- Public output excludes provider internals.
- Browser return is never settlement evidence.

## Webhook evidence

Trusted provider evidence requires configured-provider parity, registered/healthy adapter parity, incident/webhook-path availability, bounded raw input, sensitive-header stripping, signature verification, replay-window validation, provider-event identity, intent/amount/currency parity and normalized chronology/state handling. Unknown or retired-product evidence is quarantined rather than allowed to mutate current financial truth.

## File 00 financial facts

CF-03 emits past-tense financial facts only. Donation facts are explicitly non-access events. Subscription/recurring-donation facts are not current active-product events; any legacy evidence is historical/migration-only. Forbidden semantics include access grants/revocations, role/capability mutation, ranking signals, support priority, education/AI privilege or precomputed entitlement state.

## Ledger, receipts and refunds

Trusted settlement posts balanced same-currency entries and immutable receipt evidence. Source references are unique/idempotent. Refunds preserve cumulative refundable balance, requester/reviewer/executor separation and trusted provider closure; uncertain outcomes enter reconciliation rather than being guessed successful or failed.

## Transparency contract

Published aggregate transparency binds period/currency, source-evidence hash, canonical payload hash, publication state/time and privacy-safe projection. Integrity mismatch blocks publication/read/download rather than degrading to a warning.

## Download contract

Eligible financial assets are actor/audience scoped, expiring and checksum-bound. CF-03 owns eligibility and click-time authorization; File 20 owns global download-manager placement, File 25 owns visual/accessibility presentation, File 24 owns assurance, and secure delivery remains separately gated.

## Audit and incident events

Audit envelopes reject toxic credential keys and preserve tamper-evident chronology. Incident declaration, containment and recovery are audited; recovery requires valid evidence and appropriate separation of duties. Financial kill-switch/fail-closed states may stop new mutation while preserving history/read-only evidence.

## Future Expansion Pack 40

`CF03-FUTURE40-2026-09-08` adds exactly 40 coded future contracts. All are `activated=false` by default. FX-38 and FX-39 require distinct Change-Control and Sharia/legal/accounting/operational approval before activation. Future events must remain financial/governance facts and must never become entitlement commands.
