# CF-03 Integrated Final Plan 2026 v2.0

## Governing status

This repository plan integrates the Definitive Master Plan 2026 v3.0, CF-03 Complete Master Plan v1.0 and Founder-approved final decision `SSH-FIN-DONATION-2026-08-04-01`, dated 4 August 2026 at 10:02 PM PKT. The final decision supersedes conflicting interim fixed-fee, weekly-prompt and incomplete ownership/transparency language.

## Final constitutional rules

- The platform is founder-owned by Dr. Allamah Majid Hussain Sabri Muhaddith Murshid.
- It is not a Trust, charitable trust, welfare trust or trust fund and shall not be represented or registered as one.
- Registration, membership, education, AI, profile, verification, doctor listing, publishing and all core platform services have no fixed platform fee.
- Clinic and Marketplace platform commission is 0%.
- Donations are voluntary, one-time or monthly, with no preselected amount or recurrence.
- Donation never affects access, verification, ranking, visibility, publishing, moderation, support, clinic, marketplace, education, AI priority or clinical decisions.
- Approved uses are limited to institutional operations/sustainability, technical development, administration/payment costs, and homeopathy education, research, translation and advancement.
- Founder compensation, expense reimbursement, advance repayment and owner withdrawal are separately classified and publicly aggregated.
- Public transparency is aggregate and category-based; donor identities, payment credentials, bank details, detailed vendor records and security-sensitive evidence remain private.
- Payment collection, when externally approved, must use hosted or tokenized providers with signed, replay-protected, idempotent and audited webhooks.

## Additional functional requirements

| Requirement | Final requirement |
|---|---|
| CF03-FR-035 | Enforce Founder ownership and non-Trust public/legal classification. |
| CF03-FR-036 | Enforce no fixed fee and 0% Clinic/Marketplace commission. |
| CF03-FR-037 | Enforce one Donation Appeal per calendar month and at least 30 days after Remind Later, Not Now, Close or completed donation. |
| CF03-FR-038 | Maintain exact logged-in prompt state and privacy-safe first-party guest keys. |
| CF03-FR-039 | Record every expense with approved category, purpose, payee reference, approval reference, receipt state, founder indicator and public category. |
| CF03-FR-040 | Publish verified aggregate `/transparency/` snapshots with receipts, expenses, founder categories, balance and last update. |
| CF03-FR-041 | Protect donor identity; anonymous is available and public acknowledgment requires explicit revocable consent without privilege. |
| CF03-FR-042 | Provide recurring-donation view, amount-change where supported, next date, cancellation, receipts, failure notice and support contracts. |

## Canonical ownership

CF-03 owns financial truth. File 00 owns identity, membership and entitlement. File 20 owns the global shell, File 25 visual/RTL/accessibility presentation, File 24 assurance and File 19 notification delivery. File 26 must not use donation for ranking favoritism.

## Persistence and routes

The immutable historical base schema remains `2.0.0` with 28 tables. Complete schema `3.0.0` adds expenses, transparency snapshots and donor acknowledgment consent, for 31 canonical tables.

Public/application contracts include `/donate`, `/transparency/`, `/billing/donations`, `/billing/refunds/{id}`, `/admin/finance` and corresponding versioned REST endpoints. No public endpoint may fabricate financial values; until a verified snapshot exists, transparency returns `not_published` and `snapshot: null`.

## Definition of Done

Repository-source completion requires exact policy traceability, monthly prompt rules, expense/founder categories, donor privacy, aggregate transparency, complete schema verification, PHP 8.1–8.3 regression, deterministic packaging and two fresh review/fix/retest passes. Operational completion additionally requires provider, legal/tax/accounting, PCI, independent security, Hostinger staging, browser/RTL/accessibility/performance, backup/restore/rollback and explicit Founder Live approval.
