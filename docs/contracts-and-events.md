# Contracts and Events — Foundation 0.2.0

## Contract maturity

These are compile-time/domain contracts. No transport endpoint, database adapter, provider implementation, or outbox worker is active in this release.

## Hosted payment provider port

`HostedPaymentProvider` exposes only:

- provider code;
- currency capability query;
- creation of a provider-hosted checkout from a server-resolved `CheckoutCommand`;
- retrieval of normalized `ProviderEvidence`.

The command contains the canonical product/price snapshot, integer amount, currency, idempotency key, expiry and same-origin return path. It omits the platform canonical user reference from the provider request. Returned checkout URLs must use HTTPS and an adapter-specific host allowlist.

## Idempotency store port

`IdempotencyStore` supports find, atomic claim and save. The storage adapter must enforce a unique `(scope, key)` constraint. The request fingerprint binds scope, key, actor and canonical payload. Replays with changed input are conflicts; completed/failed records are immutable.

## File 00 financial facts

Contract version: `1.0`.

Allowed past-tense facts:

- `PaymentSettled`
- `PaymentFailed`
- `PaymentCancelled`
- `RefundSettled`
- `SubscriptionCancelled`

Required fields include event ID, user reference, product ID, price-version ID, financial reference, exact amount/currency, aggregate sequence, occurrence time and correlation ID.

Forbidden semantics include `grant_access`, `revoke_access`, role changes, capability changes or a precomputed entitlement state. File 00 consumes facts and applies product-specific access/grace policy.

## Audit sink

`AuditSink` appends immutable `AuditEnvelope` records. Metadata rejects card/security-secret key families such as PAN, CVV, PIN, OTP, password, secret, API/webhook keys, raw body and bank credentials. Hash fields such as `raw_body_sha256` are allowed. The future sink must be append-only, access-controlled and included in backup/restore evidence.

## Provider evidence

A provider event is trusted only after:

- signature verification with a recorded key version;
- timestamp within a configured replay window;
- atomic provider-event ID uniqueness claim;
- raw-body SHA-256 capture;
- provider, payment-intent and amount/currency parity.

Only trusted evidence can enter event-state mapping. Unknown event types become `QUARANTINED` and cannot grant entitlement or post settled ledger effects.
