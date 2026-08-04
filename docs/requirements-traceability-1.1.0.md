# CF-03 Final Policy Requirements Traceability — 1.1.0-rc.1

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

## Persistence traceability

- Historical base: `Schema::VERSION = 2.0.0`, 28 tables.
- Final extension: `TransparencySchema`, 3 tables.
- Canonical complete schema: `CompleteSchema::VERSION = 3.0.0`, 31 tables.
- Activation verifies every table after `dbDelta` before publishing the complete schema version.

## Cross-file traceability

- CF-03: canonical financial truth.
- File 00: identity, membership and entitlement; donation cannot grant entitlement.
- File 20: global-shell mounting.
- File 25: modal/cards, green-led visual language, RTL, responsive and accessibility rendering.
- File 24: security/privacy/provider assurance without taking native financial ownership.
- File 19: notification delivery after trusted financial events and preferences.
- File 26: no donation-based ranking favoritism.

## Runtime boundary

All code and contracts remain provider-neutral and fail closed. This traceability is repository-source evidence, not legal, provider, staging or Live acceptance.
