# CF-03 Foundation Architecture — 0.2.0

## Status boundary

This release is a **conditional financial foundation**, not a payment processor. It provides domain invariants, contracts, evidence validation, and a fail-closed WordPress shell while runtime collection remains disabled.

## Canonical ownership

CF-03 is intended to own approved financial products and price versions, payment-intent facts, provider references, immutable ledger transactions, invoices and receipts, subscriptions, refunds, donations, disputes, settlements, reconciliation, finance exports, and finance audit evidence.

It does **not** own:

- membership, role, or feature entitlement truth — File 00;
- Clinic or Marketplace direct-deal funds — platform commission remains 0%;
- escrow, wallet, remittance, lending, investment, cryptocurrency custody, payouts, or split payments;
- public shell or visual-system ownership — Files 20 and 25;
- support authority to mutate ledger or provider state — CF-02 may request and observe through contracts only.

## Layers

1. **WordPress bootstrap:** plugin loading, non-destructive activation metadata, Site Health visibility.
2. **Activation governance:** runtime constant plus canonical evidence-record hash; versioned approvals; expiry and duplicate-evidence checks.
3. **Catalog domain:** approved products, billing types, entitlement mappings, policy versions, immutable price snapshots, effective dates, region/currency resolution.
4. **Command contracts:** server-resolved checkout command, safe return path, provider-host allowlist, idempotency record.
5. **Provider evidence:** signature/replay/event uniqueness, provider/intent/amount parity, and unknown-state quarantine.
6. **Financial invariants:** exact money, 0% commission, donation non-privilege, state transitions, balanced immutable ledger groups.
7. **Integration contracts:** File 00 financial facts, idempotency store, audit sink, and hosted-provider port.
8. **Future persistence/application services:** payment intents, webhooks, subscriptions, invoices, refunds, disputes, reconciliation, settlement, outbox, exports, period close.

## Activation governance

A boolean WordPress option is never sufficient. Approval requires:

- `SABRI_CF03_RUNTIME_ACTIVATION === true`;
- `SABRI_CF03_ACTIVATION_EVIDENCE_HASH` containing a 64-character lowercase SHA-256;
- a versioned activation record whose canonical hash matches that constant;
- valid, non-expired, separately identified evidence blocks for all required gates;
- hosted or tokenized provider validation.

The code still registers no checkout, webhook, refund, provider, or financial-write route in `0.2.0`.

## Product and price law

- Every non-donation product has a stable product ID, owner, entitlement mapping, billing type, refund-policy version, cancellation-policy version, availability, and approval reference.
- Donation is the only voluntary product and cannot map to entitlement.
- Education membership must be recurring.
- AI usage must be metered and remains separate from base membership.
- Checkout resolves exactly one approved effective price snapshot by region and currency.
- Approved overlapping versions for the same region/currency are rejected.
- Historical approved versions remain retrievable for stable invoices and audit.

## Idempotency and evidence law

- Mutation idempotency keys are scoped, actor-bound, request-fingerprinted, and immutable.
- Floating-point values are rejected from idempotency payloads.
- Reusing a key with changed input is a conflict, never a second command.
- Provider events cannot be mapped into a trusted financial state until signature, replay, and event-uniqueness checks pass.
- Unknown trusted event types map to quarantine, not success.

## File 00 boundary

CF-03 emits past-tense financial facts such as `PaymentSettled`, `PaymentFailed`, `PaymentCancelled`, `RefundSettled`, and `SubscriptionCancelled`. These payloads contain no `grant_access`, role, capability, or entitlement-state command. File 00 applies access and grace policy.

## Packaging

The build script creates a deterministic WordPress ZIP with a single canonical top-level folder and SHA-256 record. Tests, CI metadata, build scripts, and repository-only files are excluded from the installable package.
