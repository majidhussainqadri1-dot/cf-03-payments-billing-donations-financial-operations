# CF-03 Integrated Final Plan 2026 v2.0

## Governing status

This repository plan integrates the Definitive Master Plan 2026 v3.0, CF-03 Complete Master Plan v1.0 and Founder-approved final decision `SSH-FIN-DONATION-2026-08-04-01`, dated 4 August 2026 at 10:02 PM PKT. It is further harmonized by the **Sabri Platform All-Chats Recovered Directive Register 2026 v2.0**, dated 5 August 2026. The final Founder decision supersedes conflicting interim fixed-fee, weekly/seven-day prompt and incomplete ownership/transparency language.

### Recovered-directive conflict lock

The All-Chats register correctly preserves earlier instructions for traceability, but `RCD-022` repeats the superseded seven-day Donation Appeal wording. Under the register's own precedence rule and this plan's dated Founder decision, `RCD-022` is historical/superseded for CF-03. The active timing rule remains one appeal per calendar month and at least 30 days after Remind Me Later, Not Now, Close or completed donation. `RCD-020`, `RCD-021` and `RCD-023` remain active and consistent.

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
| CF03-FR-043 | Provide rights-aware Download contracts for eligible invoices, receipts, secure finance exports and verified aggregate transparency snapshots. |
| CF03-FR-044 | Register the three governing plans and machine-lock the resolution of conflicting recovered directives by dated Founder precedence. |

## Canonical ownership

CF-03 owns financial truth and financial-asset eligibility. File 00 owns identity, membership and entitlement. File 20 owns the global shell and Global Download Manager, File 25 visual/green/Ionicons/RTL/accessibility presentation, File 24 assurance, File 19 notification delivery and CF-04 secure delivery after separate activation. File 26 must not use donation for ranking favoritism.

## Persistence and routes

The immutable historical base schema remains `2.0.0` with 28 tables. Complete schema `3.0.0` adds expenses, transparency snapshots and donor acknowledgment consent, for 31 canonical tables.

Public/application contracts include `/donate`, `/transparency/`, `/billing/donations`, `/billing/refunds/{id}`, `/admin/finance`, owner-scoped invoice/receipt download routes, authorized secure-export download and verified public transparency-snapshot download. No public endpoint may fabricate financial values; until a verified snapshot exists, transparency returns `not_published` and `snapshot: null`. Live file serving remains disabled.

## Definition of Done

Repository-source completion requires exact three-plan traceability, explicit recovered-conflict resolution, monthly prompt rules, expense/founder categories, donor privacy, aggregate transparency, eligible financial-download contracts, complete schema verification, PHP 8.1–8.3 regression, deterministic packaging and fresh review/fix/retest passes. Operational completion additionally requires provider, legal/tax/accounting, PCI, independent security, Hostinger staging, File 20/File 25 integration, browser/RTL/accessibility/performance, secure delivery, backup/restore/rollback and explicit Founder Live approval.
