# CF-03 Foundation Architecture — 0.1.0

## Status boundary

This release is a **conditional foundation**, not a payment processor. It provides domain invariants and a fail-closed WordPress shell while all runtime collection remains disabled.

## Canonical ownership

CF-03 is intended to own approved financial products and price versions, payment intent facts, provider references, immutable ledger transactions, invoices and receipts, subscriptions, refunds, donations, disputes, settlements, reconciliation, and finance audit evidence.

It does **not** own:

- membership, role, or feature entitlement truth — File 00;
- Clinic or Marketplace direct-deal funds — 0% platform commission;
- escrow, wallet, remittance, lending, investment, cryptocurrency custody, payouts, or split payments;
- public shell or visual-system ownership — Files 20 and 25;
- support authority to mutate ledger or provider state — CF-02 bridge only.

## Layers

1. **WordPress bootstrap:** plugin loading, safe activation metadata, administrative health visibility.
2. **Activation governance:** compile-time flag plus complete Founder, legal/tax/accounting, PCI, security, provider-mode, staging, and rollback evidence.
3. **Domain invariants:** exact money, 0% commission, donation non-privilege, state transitions, balanced immutable ledger groups.
4. **Future application services:** products, checkout orchestration, webhooks, subscriptions, invoices, refunds, disputes, reconciliation.
5. **Future infrastructure adapters:** hosted/tokenized provider, File 00 events, File 19 notifications, File 24 assurance, accounting/bank adapters.

## Fail-closed rule

A WordPress constant alone is insufficient. The activation gate also requires a complete stored approval record. Version `0.1.0` does not register checkout, webhook, refund, or provider endpoints even when the gate evaluates as approved.

## Money law

All calculations use integer minor units and an explicit currency code. Floating-point money is prohibited. Ledger entries contain positive amounts with debit/credit direction, and every transaction group must balance independently by currency.

## Packaging

The build script creates a deterministic WordPress ZIP with a single canonical top-level folder and SHA-256 record. Tests and CI metadata are intentionally excluded from the installable package.
