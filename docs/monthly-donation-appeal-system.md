# Monthly Donation Appeal System — Final Policy

Decision: `SSH-FIN-DONATION-2026-08-04-01`

- At most one appeal in one calendar month.
- Remind Me Later, Not Now and Close each suppress the appeal for at least 30 days.
- A completed one-time donation suppresses the appeal for at least 30 days.
- An active monthly donor receives no general appeal.
- No more than one prompt per page view or session and no repeat loop after payment failure.
- Login, registration, password recovery, guardian consent, clinical consultation, emergency warning, support appeal and payment-error contexts are excluded.
- Suggested amounts are USD 10, 14 and 50 plus positive custom USD.
- No amount or recurrence is preselected.
- Buttons are Donate, Remind Me Later, Not Now and Close.
- Donation never creates access, ranking, verification, visibility, publishing or other privilege.

Logged-in fields: `last_donation_prompt_at`, `next_donation_prompt_at`, `donation_prompt_status`, `donation_prompt_snoozed_until`, `last_donation_completed_at`, `recurring_donation_status`.

Guest first-party keys: `sabri_donation_prompt_seen_at`, `sabri_donation_prompt_next_at`.

The previous weekly system and seven-day snoozes are superseded.
