# CF-03 — Payments, Billing, Donations and Financial Operations

Canonical financial owner for the **Sabri Social Homeopathy Platform**.

> **Source status:** `1.1.0-rc.1`, complete schema `3.0.0`. The final governing decision is `SSH-FIN-DONATION-2026-08-04-01`. Live collection remains disabled and fail closed pending external acceptance.

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

## Canonical ownership

CF-03 owns financial truth. File 00 owns identity, membership and entitlement. File 20 owns the global shell, File 25 presentation/RTL/accessibility, File 24 assurance and File 19 notification delivery. File 26 must not use donation for ranking favoritism.

## QA

```bash
php tests/run.php
php tests/run-0.2.php
php tests/run-1.0.php
php tests/run-free-donation-policy.php
php tests/run-founder-donation-transparency.php
php tests/run-plan-completion.php
php tests/run-adversarial-2.php
php tests/run-review-40.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash scripts/build-package.sh
```

## External acceptance boundary

No Live provider adapter, webhook route or collection is enabled. Legal/tax/accounting, PCI, independent security, Hostinger staging, browser/RTL/accessibility/performance, backup/restore/rollback and explicit Founder Live approval remain external gates.
