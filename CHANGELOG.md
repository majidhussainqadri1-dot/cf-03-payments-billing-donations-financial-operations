# Changelog

## 1.0.0-rc.1 — Complete Conditional Source Candidate

- Implemented source-level coverage for CF03-FR-001 through CF03-FR-034.
- Added pre-purchase disclosure, invoices, subscriptions, refunds, reconciliation, period close, secure export, incident controls and backup parity objects.
- Added provider-neutral payment/refund/settlement contract and durable repository contract.
- Added additive owner-table schema and idempotency-aware migration runner.
- Added event catalogue, outbox/audit persistence design and File 00 fact-only boundary.
- Added 25 new source-candidate tests alongside the existing 39 foundation tests.
- Kept all payment runtime routes and provider operations fail-closed pending external acceptance gates.

## 0.2.0 — Product, Price, Evidence and Contract Foundation

- Added versioned hash-bound activation evidence, products, immutable price snapshots, checkout/idempotency/provider-evidence contracts and File 00 financial facts.

## 0.1.0 — Foundation

- Added fail-closed bootstrap, exact money, 0% commission, donation non-privilege, intent transitions and balanced-ledger value objects.
