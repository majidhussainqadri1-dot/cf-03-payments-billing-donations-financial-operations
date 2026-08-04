# CF-03 Requirements Traceability — Source Candidate 1.0.0

This matrix records source-code coverage. Provider sandbox, qualified legal/tax/accounting acceptance, PCI responsibility validation, Hostinger staging, accessibility/browser evidence, restore drill and operational staffing remain external acceptance gates.

| Requirements | Source implementation |
|---|---|
| CF03-FR-001–005 | FinancialProduct, PriceVersion/PriceCatalog, PrePurchaseDisclosure, CommissionPolicy, DonationPolicy |
| CF03-FR-006–011 | CheckoutCommand, HostedCheckoutReference, IdempotencyRecord, ProviderEvidence, ProviderEventStateMapper, PaymentProvider contract |
| CF03-FR-012–016 | LedgerTransaction/Entry, Invoice, reconciliation result, finance period and immutable correction law |
| CF03-FR-017–023 | Subscription lifecycle, failed-payment/grace facts, separate AI product kinds, donation separation |
| CF03-FR-024–028 | RefundRequest separation of duties, provider refund contract, dispute/settlement event contracts, reconciliation exceptions |
| CF03-FR-029–034 | Role separation, minimized audit/export, close/lock, incident kill switches, backup manifest and restore parity |
| Data/migration | Additive owner-table schema and idempotency-aware migration runner |
| Cross-file contracts | File 00 fact publisher; File 24 audit sink; provider-neutral ports; no direct entitlement mutation |
| Security/privacy | Hash-bound activation, hosted/tokenized mode, exact money, secret/card-field rejection, replay/idempotency contracts, fail closed runtime |

No source-code statement converts a conditional module into a legally, financially or operationally accepted payment service.
