# CF-03 Final Policy Requirements Traceability — 1.1.0-rc.2

## Governing plans

- `SSH-PMP-2026-v3.0` — Definitive Master Plan 2026 v3.0.
- Consolidated Current-Chat Directive Register v1.0 — 5 August 2026.
- CF-03 Integrated Final Plan 2026 v2.0 — `SSH-FIN-DONATION-2026-08-04-01`.

| Requirement | Governing requirement | Source evidence | Verification evidence |
|---|---|---|---|
| CF03-FR-035 | Founder ownership and non-Trust classification | `FounderOwnershipPolicy`, `PlatformFinancialPolicy`, public-disclosure REST contract | Founder ownership/non-Trust tests |
| CF03-FR-036 | No fixed platform fee; Clinic/Marketplace commission 0% | `PlatformFinancialPolicy`, checkout failure contract | Policy and product tests |
| CF03-FR-037 | One appeal per calendar month; 30-day action/completion suppression; page/session caps | `DonationPromptState`, `DonationPromptPolicy`, `DonationPromptContext` | Final donation-policy tests |
| CF03-FR-038 | Exact server and guest-state contract with legacy migration aliases | `DonationAppealCopy`, registered user meta, appeal manifest | State round-trip and contract tests |
| CF03-FR-039 | Approved donation-expense categories and separate Founder indicators | `DonationExpenseCategory`, `DonationExpense`, `sabri_cf03_expenses` | Category, flag and privacy-projection tests |
| CF03-FR-040 | Verified aggregate public transparency without fabricated figures | `FinancialTransparencySnapshot`, `WordPressTransparencyRepository`, `/transparency/` | Arithmetic, currency, source-hash and no-fabrication tests |
| CF03-FR-041 | Donor privacy, anonymous use and explicit revocable acknowledgment | `sabri_cf03_donor_acknowledgments`, public projections | Consent-field and non-privilege tests |
| CF03-FR-042 | Recurring donation management and easy cancellation | `/billing/donations`, `donation-management` REST contract | Fail-closed management contract tests |
| CF03-FR-043 | Rights-aware download of eligible invoices, receipts, finance exports and verified transparency snapshots | `FinancialDownloadContract`, `FinancialDownloadGrant`, four explicit routes, REST contract | Three-plan harmonization tests: asset type, owner/audience, filename, SHA-256, expiry, denial, delivery-reference and ownership boundaries |

## Current-chat directive traceability

| Directive | CF-03 applicability | Resolution |
|---|---|---|
| `CHAT-DL-001` | Eligible financial documents and exports | Native eligibility/download grant; File 20 manager; File 25 green/Ionicons/RTL/accessibility; File 24 assurance; CF-04 delivery after activation |
| `CHAT-QA-001` | Post-GitHub harmonization and iterative correction | Dedicated audit evidence, 20 regression tests, PHP 8.1–8.3 CI, deterministic package parity |
| `CHAT-PRIV-001` | Financial minimization and anti-surveillance | Donor identity private by default, aggregate transparency, no secret/raw financial telemetry |
| `CHAT-UX-002`–`CHAT-UX-004` | Global navigation, Back/Home and right-priority layout | Not owned by CF-03; delegated to Files 20 and 25 through route/presentation contracts |

## Persistence traceability

- Historical base: `Schema::VERSION = 2.0.0`, 28 tables.
- Final extension: `TransparencySchema`, 3 tables.
- Canonical complete schema: `CompleteSchema::VERSION = 3.0.0`, 31 tables.
- Download harmonization adds no table and does not rewrite financial history.
- Activation verifies every table after `dbDelta` before publishing the complete schema version.

## Cross-file traceability

- CF-03: canonical financial truth and financial-asset eligibility.
- File 00: identity, membership and entitlement; donation cannot grant entitlement.
- File 20: global shell and Global Download Manager.
- File 25: modal/cards/download control, platform primary green, Ionicons, RTL, responsive and accessibility rendering.
- File 24: security/privacy/provider/download assurance without taking native financial ownership.
- CF-04: signed/expiring secure delivery after separate activation.
- File 19: notification delivery after trusted financial events and preferences.
- File 26: no donation-based ranking favoritism.

## Runtime boundary

All code and contracts remain provider-neutral and fail closed. Download routes are contracts only; they do not serve Live files. This traceability is repository-source evidence, not legal, provider, staging or Live acceptance.
