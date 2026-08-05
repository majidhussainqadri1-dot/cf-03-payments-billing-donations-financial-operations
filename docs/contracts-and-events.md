# CF-03 Contracts and Events — 1.2.0-rc.1

## Contract maturity

CF-03 now contains domain contracts, a durable WordPress repository, REST adapters, schedulers, outbox processing and provider-neutral ports. It does not contain an approved Live provider adapter or Live credentials. Real collection remains fail closed.

## Hosted and recurring provider ports

`HostedPaymentProvider`, `DonationPaymentProvider`, `PaymentProvider` and `RecurringDonationProvider` expose only provider-scoped operations:

- capability and currency discovery;
- creation/resumption of hosted checkout;
- trusted provider evidence retrieval;
- signed webhook normalization;
- refunds;
- recurring amount changes and cancellation;
- settlement retrieval.

Platform forms never collect PAN, CVV, PIN, OTP, bank passwords or raw payment credentials. Hosted checkout URLs require HTTPS and adapter-specific host allowlists.

## Idempotency

Checkout and mutation requests bind an idempotency key to actor, scope and canonical request hash. Reuse with different input is rejected. Completed claims replay the canonical result; pending or failed claims do not create a second financial operation.

## Provider evidence

A provider event is trusted only after signature verification, key-version evidence, replay-window validation, bounded normalized payload validation, provider-event uniqueness and provider/intent/amount/currency parity. Raw webhook bodies are represented by SHA-256 evidence, not persisted as ordinary financial records.

Unknown provider event types are quarantined. Browser return URLs never establish settlement.

## Canonical financial events

Events use past-tense names, stable event IDs, aggregate version, schema version, trace ID and privacy-minimized payload hashes. Implemented families include:

- `DonationSettled`, `DonationRefunded`;
- `PaymentFailed`, `PaymentCancelled`, `PaymentDisputed`;
- refund lifecycle facts;
- recurring-donation cancellation facts;
- settlement/reconciliation and adjustment evidence;
- transparency publication evidence;
- incident declaration/recovery evidence.

## File 00 boundary

File 00 receives financial facts only. CF-03 never sends commands such as `grant_access`, `revoke_access`, role changes or a precomputed entitlement decision. File 00 remains the sole entitlement authority.

## Outbox delivery

Canonical events are inserted into the durable outbox within the same transaction as the financial state change. The scheduler leases bounded batches, retries with limits and records dead-letter state. Delivery through `WordPressOutboxTransport` uses the `sabri_cf03_financial_fact` integration hook; consumers must be idempotent.

## Audit contract

`FinancialAuditService` appends a serialized SHA-256 hash chain. `AuditEnvelope` rejects sensitive metadata key families such as PAN, CVV, PIN, OTP, passwords, secrets, API/webhook keys, raw bodies and bank credentials. Audit history is included in backup/restore integrity evidence and is not deleted by ordinary uninstall.

## Cross-file ownership

- File 00: identity, membership and entitlement decisions;
- File 19: notification delivery after consent;
- File 20: global shell and download manager;
- File 24: assurance and evidence consumption;
- File 25: responsive/RTL/accessibility presentation;
- File 26: search/classification without donation favoritism;
- CF-02: case orchestration without ledger mutation;
- CF-04: secure file delivery after activation.
