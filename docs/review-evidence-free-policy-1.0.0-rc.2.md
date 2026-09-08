# Review Evidence — CF-03 1.0.0-rc.2

## Governing change

Founder Decision `SSH-FIN-2026-08-04-01` changed the active financial policy from planned paid membership/AI products to a completely free platform with optional donations only.

## Review and fix round 1

### Findings

1. Existing paid-product checkout remained constructible despite the new Founder decision.
2. The old activation status option would not update on plugin upgrade because `add_option` does not replace an existing value.
3. Existing tests expected an education membership provider request and used obsolete module versions.
4. CI/package/manifests/documentation still identified `1.0.0-rc.1` or `0.2.0`.
5. No executable weekly frequency/snooze/engagement/sensitive-context policy existed.
6. Logged-in and guest prompt-state contracts were not represented.

### Corrections

- Added `PlatformFinancialPolicy` and made non-donation checkout fail closed.
- Updated activation to overwrite the runtime policy status safely while preserving the activation record.
- Added prompt state/context/policy/service/store models and exact requested fields/guest keys.
- Added bilingual appeal and donation-intent contracts.
- Updated tests, CI, package version, manifests and governing documentation to `1.0.0-rc.2`.

### Retest

The complete GitHub Actions matrix passed PHP 8.1, 8.2 and 8.3 syntax and all four test suites, manifests, credential scan and deterministic package parity.

## Fresh adversarial review and fix round 2

### Adversarial finding

A generic prompt-state service method could record `DONATION_COMPLETED_*` actions without proving trusted provider evidence. Although this could not grant financial or entitlement privilege, it could corrupt donor-frequency state and falsely suppress future appeals.

### Correction

- Split user prompt actions from trusted financial facts.
- User actions are restricted to Shown, Remind Later, Not Now and Close.
- Added normalized donation financial fact types.
- Added `TrustedDonationFact`, which requires signature/replay/event uniqueness and provider/intent/amount parity through `ProviderEvidence`.
- Completion, monthly-active and monthly-cancelled prompt states can now be persisted only through a trusted financial fact.
- Added forged-completion and mismatched-fact adversarial tests.

### Retest

Reviewed implementation head `7911c356c4712b3e2567cd808eb169166c122753` passed GitHub Actions run 116:

- PHP 8.1: success
- PHP 8.2: success
- PHP 8.3: success
- Foundation suite: 12 tests
- Product/evidence suite: 27 tests
- Complete source-candidate suite: 25 tests
- Free-platform donation suite: 25 tests
- Total domain tests per PHP version: 89
- JSON manifests: success
- Credential material scan: success
- Free-policy source assertions: success
- Deterministic package build/parity: success

## Known unresolved boundaries

No known unresolved source defect was identified within this amendment scope after the two review/fix rounds. The following are external acceptance dependencies, not source claims:

- File 20 mount implementation;
- File 25 visual/accessibility implementation;
- File 24 assurance integration;
- File 19 notification integration;
- provider/legal/PCI/security/staging/restore/rollback/operations/Founder live approval.

The PR remains draft and live donation collection remains disabled.
