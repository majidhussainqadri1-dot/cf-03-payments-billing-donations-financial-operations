# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Never place credentials, provider secrets, webhook signing keys, card data, personal identity evidence, financial records, activation evidence, or incident playbooks in a public issue.

## Governing policy

Founder Decision `SSH-FIN-2026-08-04-01` keeps the platform free and makes all membership, education, AI and platform-service charges dormant and non-collectible. Only voluntary-donation infrastructure may proceed. Live donation collection remains disabled until every external gate is evidenced.

## Non-negotiable controls

- Paid-product checkout is prohibited while the free-platform policy is active.
- Hosted or tokenized donation collection only after approved activation.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials, or provider secrets in code, logs, support records, exports, browser storage, ordinary WordPress options or support intake.
- No donation amount or monthly recurrence is preselected; recurring support requires explicit consent.
- Donation cannot change access, ranking, verification, moderation, visibility, support priority, clinic/marketplace eligibility, education or clinical access.
- Browser redirects never prove payment or donation success.
- Provider facts require signature validation, replay-window validation, event-ID uniqueness, provider/intent/amount parity, durable idempotency, ledger posting, and outbox reconciliation before financial facts may be emitted.
- Unknown provider states are quarantined.
- File 00 remains the entitlement authority; CF-03 never writes access directly.
- Activation fails closed unless a versioned evidence record matches the configured SHA-256 and all required evidence blocks are valid.
- Provider checkout hosts must be explicitly allowlisted.
- Guest prompt state is first-party and limited to the approved seen/next timestamps; logged-in prompt state is server-side and privacy-minimized.

Version `1.0.0-rc.2` contains no live provider implementation, payment route, donation webhook route or production financial activation.
