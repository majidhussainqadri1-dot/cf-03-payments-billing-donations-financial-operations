# CF-03 1.1.0-rc.1 — Definition of Done

## Repository-source gates

The release is source-complete only when all of the following are true on one exact commit:

- `SSH-FIN-DONATION-2026-08-04-01` is the canonical current policy and the interim weekly decision is explicitly marked superseded.
- Founder ownership and non-Trust/non-charitable-trust status are enforced in domain policy, public disclosure and documentation.
- Fixed platform fees and nonzero Clinic/Marketplace commissions are rejected.
- Donation amount and monthly recurrence are never preselected and donation grants no privilege.
- Donation Appeal is limited to one calendar-month occurrence, one page view and one session, with at least 30-day suppression after Remind Me Later, Not Now, Close or completed donation.
- Active monthly donors and all sensitive contexts are suppressed.
- Exact logged-in and guest-state contracts are implemented with migration aliases for prior keys.
- Expenses accept only approved categories and Founder-related categories cannot be hidden as ordinary expenses.
- Public transparency contains verified aggregate receipts, expenses, Founder amounts, balance and last update, never fabricated values.
- Donor identity is private by default; public acknowledgment is explicit, revocable and non-privileged.
- Recurring-donation management contracts expose view, supported amount change, next date, cancellation, receipts and support without dark patterns.
- Complete schema `3.0.0` verifies all 31 canonical tables after installation.
- PHP 8.1, 8.2 and 8.3 syntax/tests, manifests, credential scan, governing assertions and deterministic package parity pass.
- Two fresh review/fix/retest rounds after implementation reveal no unresolved repository defect.

## External operational gates

Source completion does not establish Live collection. Operational completion additionally requires:

- legal entity and receiving account;
- legal, tax and accounting acceptance;
- PCI responsibility validation;
- approved hosted/tokenized provider and sandbox evidence;
- independent security acceptance;
- Hostinger staging installation and migration acceptance;
- real-browser mobile/desktop, RTL, accessibility and performance acceptance;
- backup, restore, provider-exit and rollback drills;
- operational staffing and incident procedures;
- explicit Founder Live-collection approval.

Until all external gates pass, provider mutations and collection remain fail closed.
