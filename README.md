# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical financial owner for the **Sabri Social Homeopathy Platform**.

> **Source status:** `1.1.0-rc.3`, complete schema `3.0.0`. Live collection and financial file delivery remain disabled and fail closed pending external acceptance.

## Three governing plans

1. **Sabri Social Homeopathy Platform Definitive Master Plan 2026 v3.0** — `SSH-PMP-2026-v3.0`;
2. **Sabri Platform All-Chats Recovered Directive Register 2026 v2.0**, dated 5 August 2026;
3. **CF-03 Integrated Final Plan 2026 v2.0**, governed by `SSH-FIN-DONATION-2026-08-04-01`.

The repository correction evidence is recorded in `docs/review-evidence-three-plan-harmonization-1.1.0-rc.3.md`. The platform-wide post-GitHub harmonization and zero-known-defect rule is `CHAT-QA-001`.

## Explicit recovered-directive resolution

The All-Chats register contains `RCD-022`, which repeats the earlier seven-day Donation Appeal wording. It is **superseded for CF-03** by the later, dated and more specific Founder decision `SSH-FIN-DONATION-2026-08-04-01`. The active rule is:

- at most one Donation Appeal in each calendar month; and
- at least 30 days after Remind Me Later, Not Now, Close or a completed donation.

`RCD-020`, `RCD-021` and `RCD-023` remain active and consistent: the platform is free, donation amounts are USD 10/USD 14/USD 50/custom with no preselection, and Clinic/Marketplace commission is 0% with no donor advantage.

## Governing financial law

- The platform is owned by **Dr. Allamah Majid Hussain Sabri Muhaddith Murshid** and is not a Trust, charitable trust, welfare trust or trust fund.
- Registration, membership, education, AI, profile, verification, listing, publishing and all core platform services have no fixed platform fee.
- Clinic and Marketplace platform commission is **0%**.
- Donations are voluntary, one-time or monthly, default-off and never affect access, ranking, verification, visibility, publishing, moderation, support, clinic, marketplace, education, AI priority or clinical decisions.
- Suggested amounts are USD 10, USD 14, USD 50 and positive custom USD; no amount or recurrence is preselected.
- Approved uses are institutional sustainability/operations, technical development, administration/payment costs and homeopathy education, research and advancement.
- Founder compensation, expense reimbursement, advance repayment and owner withdrawal are separately recorded and publicly aggregated.
- Hosted/tokenized providers only; PAN, CVV, PIN, OTP, bank passwords, raw credentials and provider secrets are never accepted or stored.
- Browser returns never prove payment success.

## Final policy implementation

- At most one Donation Appeal in each calendar month.
- At least 30 days of suppression after Remind Me Later, Not Now, Close or a completed donation.
- Active-monthly-donor, sensitive-context, page-view, session and payment-failure suppression.
- Founder ownership/non-Trust public disclosure.
- Approved expense classification and separate Founder-related categories.
- Verified aggregate `/transparency/` snapshots without donor, bank or credential disclosure.
- Explicit, revocable donor acknowledgment consent without privilege.
- Recurring-donation view, supported amount change, next payment date, cancellation, receipts and support contracts.
- Complete schema `3.0.0`: historical 28-table base plus expenses, transparency snapshots and donor acknowledgments, for 31 canonical tables.

## Universal financial download contract

Directive `CHAT-DL-001` is implemented for eligible CF-03 assets:

- invoice snapshots;
- receipts;
- secure finance exports;
- verified aggregate transparency snapshots.

CF-03 owns asset eligibility and click-time authorization. File 20 owns the Global Download Manager, File 25 owns the green-led Ionicons/RTL/accessibility presentation, File 24 owns assurance, and CF-04 owns secure delivery after activation. The source enforces safe filenames, approved media types, SHA-256, expiry, audience binding, denial/revocation reasons and opaque or vault-scoped delivery references. No Live delivery route is enabled.

## Canonical ownership

CF-03 owns financial truth. File 00 owns identity, membership and entitlement. File 20 owns the global shell and download manager, File 25 presentation/RTL/accessibility, File 24 assurance and File 19 notification delivery. File 26 must not use donation for ranking favoritism.

## QA

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
php tests/run-free-donation-policy.php
php tests/run-founder-donation-transparency.php
php tests/run-three-plan-harmonization.php
php tests/run-plan-completion.php
php tests/run-adversarial-2.php
php tests/run-review-40.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

The nine suites execute **253 tests per PHP version** and **759 domain-test executions** across PHP 8.1, 8.2 and 8.3, plus manifest validation, prohibited-secret scanning, all-three-plan assertions, explicit `RCD-022` precedence assertions and deterministic package parity.

## External acceptance boundary

No Live provider adapter, webhook route, collection or financial file delivery is enabled. Legal/tax/accounting, PCI, independent security, Hostinger staging, File 20/File 25 presentation integration, browser/RTL/accessibility/performance, backup/restore/rollback and explicit Founder Live approval remain external gates.
