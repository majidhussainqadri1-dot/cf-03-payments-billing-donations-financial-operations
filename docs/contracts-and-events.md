# CF-03 Contracts and Events — 1.2.0-rc.2

## Runtime maturity

These contracts are implemented over a durable WordPress repository, REST adapters, schedulers, outbox records and provider-neutral ports. The repository still contains no approved Live provider adapter or Live activation evidence.

## Donation provider contract

`DonationPaymentProvider` exposes provider identity, supported currencies, health, hosted donation checkout creation/resume, webhook verification, donation-evidence query, refund and settlement retrieval. The runtime requires the configured provider to be present in the donation registry and healthy in the payment registry.

A provider receives a `DonationIntentDraft::providerSafeClone()` whose donor subject is withheld. The canonical user/guest reference remains local to CF-03. The provider-facing request retains only the intent, exact minor-unit amount, currency, recurrence consent, state, idempotency key and timestamp necessary for secure hosted checkout.

## Idempotency and hosted checkout

- Header and body idempotency values must match.
- The local claim binds actor, request hash and 24-hour expiry.
- Provider session creation is followed by a durable `provider_created` checkpoint.
- Canonical intent, donation and optional recurring-consent records are committed atomically.
- A completed replay resumes the canonical provider session.
- Changed input under the same key is rejected.
- Public REST output excludes provider code and provider-session reference.

## Webhook evidence

A provider event is trusted only after:

- configured provider parity;
- registered/healthy adapter parity;
- incident webhook path availability;
- webhook activation evidence;
- bounded raw body and header collection;
- sensitive-header stripping;
- signature verification and key-version recording;
- replay-window validation;
- event-ID uniqueness;
- provider/intent/amount/currency parity;
- normalized state mapping.

Unknown trusted states are quarantined. Raw webhook bodies are not persisted; only SHA-256 evidence is stored.

## File 00 financial facts

Contract version: `1.0`.

Allowed past-tense facts include:

- `PaymentSettled`;
- `PaymentFailed`;
- `PaymentCancelled`;
- `RefundSettled`;
- `SubscriptionCancelled`;
- donation settlement and recurring-donation facts defined by the current CF-03 contract.

Forbidden semantics include access grants/revocations, role or capability mutation, ranking signals and precomputed entitlement state. File 00 consumes financial facts and independently applies entitlement policy.

## Ledger and receipts

Trusted settlement posts balanced debit/credit entries in the same transaction as intent state and outbox facts. Source references are unique. Receipt/invoice snapshots are immutable and SHA-256 bound. Browser returns are informational only.

## Refund and recurring contracts

Refunds preserve requester/reviewer/executor separation, cumulative refundable balance and trusted provider closure. Recurring donation management exposes view, supported amount change, next payment date, cancellation, receipts and support without dark patterns.

## Transparency contract

Each published aggregate transparency record binds:

- period and currency;
- source-evidence hash;
- canonical snapshot-payload hash;
- publication state/timestamp;
- privacy-safe public projection.

A mismatch blocks public read and secure download generation.

## Download contract

`CHAT-DL-001` allows eligible invoices, receipts, approved finance exports and verified transparency snapshots. Grants are audience-bound, expiring, checksum-bound and use opaque/vault references. CF-04 secure delivery and File 20 download-manager integration remain external activation dependencies.

## Audit and incident events

Financial audit envelopes reject toxic credential keys and form a serialized hash chain. Incident declaration, containment and recovery are audited; recovery requires an independent actor, evidence reference and valid chronology. Checkout cannot recover without trusted webhook recovery.
