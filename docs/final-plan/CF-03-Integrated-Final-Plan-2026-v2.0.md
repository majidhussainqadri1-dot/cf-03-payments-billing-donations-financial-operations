# CF-03 Integrated Final Plan 2026 v2.0

## Governing status

This plan integrates:

1. Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0 — `SSH-PMP-2026-v3.0`;
2. Sabri Platform All-Chats Recovered Directive Register 2026 v2.1;
3. CF-03 Complete Master Plan v1.0 and this Integrated Final Plan v2.0;
4. Founder decision `SSH-FIN-DONATION-2026-08-04-01`, effective 4 August 2026 at 10:02 PM PKT.

The Founder decision supersedes conflicting interim fixed-fee, weekly/seven-day prompt and incomplete ownership/transparency wording.

### Recovered-directive conflict lock

`RCD-022` preserves the older seven-day Donation Appeal rule for history but is superseded for CF-03. The active rule is at most one appeal per calendar month and at least 30 days after Remind Me Later, Not Now, Close or a completed donation. `RCD-020`, `RCD-021` and `RCD-023` remain active and consistent.

## Constitutional rules

- Founder-owned by Dr. Allamah Majid Hussain Sabri Muhaddith Murshid.
- Not a Trust, charitable trust, welfare trust or trust fund.
- No fixed registration, membership, education, AI, profile, verification, listing, publishing or core-platform fee under the active decision.
- Clinic and Marketplace commission 0%.
- Donation voluntary, one-time or monthly, with no preselected amount/recurrence.
- Donation never affects access, entitlement, verification, ranking, visibility, publishing, moderation, support, clinic, marketplace, education, AI priority or clinical decisions.
- Approved uses limited to institutional survival/operations, technical development, administration/payment costs and homeopathy education, research, translation, books and advancement.
- Founder compensation, reimbursement, advance repayment and owner withdrawal separately classified and publicly aggregated.
- Public transparency aggregate and privacy-minimized.
- Hosted/tokenized providers only after external approval; browser return never proves settlement.

## Functional requirements

| Requirement | Final requirement |
|---|---|
| CF03-FR-035 | Enforce Founder ownership and non-Trust public/legal classification. |
| CF03-FR-036 | Enforce no fixed fee and 0% Clinic/Marketplace commission. |
| CF03-FR-037 | Enforce one Donation Appeal per calendar month and at least 30 days after Remind Later, Not Now, Close or completed donation. |
| CF03-FR-038 | Maintain six canonical logged-in prompt fields, privacy-safe guest keys and fail-closed unknown contexts. |
| CF03-FR-039 | Record every expense with approved category, purpose, payee, approval, receipt, Founder indicator and public category. |
| CF03-FR-040 | Publish verified aggregate `/transparency/` snapshots with source and snapshot SHA-256 evidence. |
| CF03-FR-041 | Protect donor identity; public acknowledgment requires explicit revocable consent without privilege. |
| CF03-FR-042 | Provide recurring-donation view, supported amount change, next date, cancellation, receipts and support. |
| CF03-FR-043 | Provide rights-aware downloads for eligible invoices, receipts, exports and verified transparency snapshots. |
| CF03-FR-044 | Register the three governing plans and machine-lock recovered-directive resolution. |
| CF03-FR-045 | Require complete financial plus webhook readiness before checkout or Live collection status. |
| CF03-FR-046 | Bind provider identity to registered donation/payment adapters and verified health. |
| CF03-FR-047 | Withhold canonical donor identity from provider-facing donation drafts. |
| CF03-FR-048 | Persist provider-created checkout checkpoints and recover safely after local interruption. |
| CF03-FR-049 | Detect duplicate canonical identifiers and propagate nested transaction rollback-only state. |
| CF03-FR-050 | Verify every required table, column, index, index order and uniqueness rule during install/upgrade. |
| CF03-FR-051 | Verify transparency source and snapshot hashes before publication/read/download. |
| CF03-FR-052 | Provide paginated privacy export and explicit retained-record/acknowledgment-erasure outcomes. |

## Canonical ownership

CF-03 owns financial truth and financial-asset eligibility. File 00 owns identity, membership and entitlement. File 20 owns the global shell and Global Download Manager. File 25 owns green-led Ionicons/RTL/accessibility presentation. File 24 owns assurance. File 19 owns notification delivery. CF-04 owns secure delivery after activation. File 26 must not use donation for ranking favoritism.

## Runtime and persistence

Historical base schema `2.0.0` contains 28 tables. Three transparency/expense/acknowledgment tables preserve a total of 31 canonical tables. `RuntimeSchemaExtension::VERSION` and `CompleteSchema::VERSION` are `3.3.0`.

The runtime adds:

- exact installed-schema gating;
- fail-closed automatic upgrade;
- strict WordPress option typing;
- incident-state integrity and checkout/webhook dependency;
- provider-safe donor subject;
- provider-created idempotency checkpoint;
- public response redaction;
- sensitive webhook-header stripping;
- duplicate identity detection;
- nested rollback-only transaction semantics;
- table/column/index/uniqueness verification;
- canonical transparency payload hashing;
- period/currency snapshot uniqueness.

## Routes and public behavior

Public/application contracts include `/donate`, `/transparency/`, `/billing/donations`, `/billing/refunds/{id}`, `/admin/finance`, owner-scoped invoice/receipt download routes, authorized export download and verified public transparency download.

No public endpoint fabricates financial values. Until a verified snapshot exists, transparency returns `not_published` and `snapshot: null`. Donation controls remain disabled and clearly state that no funds are collected while Live readiness is false. Public responses do not expose provider-session references, activation gates or incident evidence.

## Definition of Done

Repository-source completion requires exact plan traceability, conflict resolution, policy enforcement, complete schema/index verification, public privacy, transaction/idempotency integrity, PHP 8.1–8.3 tests, deterministic packaging and repeated fresh review/fix/retest evidence.

Operational completion additionally requires approved provider, legal/tax/accounting, PCI, independent security, Hostinger staging, File 20/File 25 integration, browser/mobile/RTL/accessibility/performance, secure delivery, backup/restore/rollback and explicit Founder Live approval.

## Current source amendment

Source candidate `1.2.0-rc.2` and schema `3.3.0` incorporate a third forty-round review/fix sequence recorded in `docs/review-evidence-40-rounds-third-1.2.0-rc.2.md`. This amendment changes implementation hardening only; it does not alter the Founder financial policy or activate collection.
