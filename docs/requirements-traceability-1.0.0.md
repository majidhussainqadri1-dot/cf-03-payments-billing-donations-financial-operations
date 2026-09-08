# CF-03 Requirements Traceability — Source Candidate 1.0.0-rc.2

This matrix records source-code coverage after Founder Decision `SSH-FIN-2026-08-04-01`. Provider/legal/staging/operational acceptance remains external.

| Requirement group | Source implementation and current decision |
|---|---|
| Founder free-platform decision | `PlatformFinancialPolicy` makes all non-donation products dormant/non-collectible, preserves historical definitions and enforces 0% platform fees |
| CF03-FR-001–003 | `FinancialProduct`, `PriceVersion`/`PriceCatalog`, `PrePurchaseDisclosure`; previous membership/AI entries remain historical, but `CheckoutCommand` rejects them under current policy |
| CF03-FR-004–005 | `CommissionPolicy`, `DonationPolicy`, `PlatformFinancialPolicy`; 0% Clinic/Marketplace commission and no donation privilege/defaults |
| Weekly appeal amounts | `DonationAppealCopy`, `PlatformFinancialPolicy`: USD 10, 14, 50 plus positive custom amount; no amount preselected |
| Monthly consent | `DonationIntentDraft`: monthly support requires explicit consent; one-time intent cannot carry monthly consent |
| Frequency cap | `DonationPromptState`, `DonationPromptPolicy`: seven-day shown/snooze cap; thirty-day completed-donation suppression; active-monthly suppression |
| Context safety | `DonationPromptContext`, `DonationPromptPolicy`: login, registration, recovery, guardian, clinical, emergency, support appeal and payment-error blocks; engagement/page/session caps |
| Prompt persistence | `WordPressDonationPromptStateStore`: five exact logged-in server-side fields; `donation-appeal-contract.json`: two guest first-party keys |
| CF03-FR-006–011 | `DonationPaymentProvider`, `DonationIntentDraft`, `HostedCheckoutReference`, `IdempotencyRecord`, `ProviderEvidence`, `ProviderEventStateMapper`; service preparing and no live provider |
| CF03-FR-012–016 | `LedgerTransaction`/`LedgerEntry`, `Invoice`, reconciliation result, finance period and immutable correction law |
| CF03-FR-017–023 | Exact money, preserved subscription lifecycle, failed-payment/grace facts, dormant separate AI kinds and donation separation |
| CF03-FR-024–028 | `RefundRequest` separation of duties, provider refund contract, dispute/settlement events and reconciliation exceptions |
| CF03-FR-029–034 | Role separation, minimized audit/export, close/lock, incident kill switches, backup manifest and restore parity |
| Data/migration | Additive owner-table schema and idempotency-aware migration runner; no destructive removal of dormant products or financial history |
| Cross-file contracts | File 00 fact/entitlement boundary; File 20 mount owner; File 25 visual owner; File 24 assurance; File 19 consent-aware notifications |
| Security/privacy | Hash-bound activation, hosted/tokenized mode, exact money, secret/card-field rejection, replay/idempotency, first-party minimal guest state and fail-closed runtime |
| Automated evidence | Foundation, product/evidence, source-candidate and free-donation-policy suites; PHP 8.1–8.3; manifests; credential scan; deterministic package |

No source-code statement converts this candidate into a legally, financially, staging or operationally accepted donation service. Re-enabling a fee requires a separate Founder Change-Control and complete acceptance evidence.
