# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Never place credentials, provider secrets, webhook signing keys, card data, personal identity evidence, financial records, activation evidence, internal runtime diagnostics or incident playbooks in a public issue.

## Current release boundary

CF-03 `1.2.0-rc.1` contains source routes and provider-neutral contracts required for staging and future approved operation. It does **not** contain an approved Live payment-provider adapter, Live provider credentials or Founder authorization for real collection. Runtime state defaults to `preparing` and all financial mutation paths fail closed until every external gate is evidenced.

## Governing policy

Founder Decision `SSH-FIN-DONATION-2026-08-04-01` keeps core platform services free, makes prior membership/education/AI charges dormant and permits only voluntary donation infrastructure. Clinic and Marketplace commission remains 0%.

## Non-negotiable controls

- Paid-product checkout is prohibited while the free-platform policy is active.
- Hosted or tokenized donation collection only after approved activation.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials or provider secrets in code, logs, support records, exports, browser storage, ordinary WordPress options or support intake.
- No donation amount or monthly recurrence is preselected; recurring support requires explicit consent.
- Donation cannot change access, ranking, verification, moderation, visibility, support priority, clinic/marketplace eligibility, education, AI or clinical access.
- Browser redirects never prove payment or donation success.
- Provider facts require signature validation, replay-window validation, event-ID uniqueness, provider/intent/amount parity, durable idempotency, ledger posting and outbox reconciliation.
- Unknown provider states are quarantined.
- File 00 remains the entitlement authority; CF-03 never writes access directly.
- Activation fails closed unless a versioned evidence record and all required gates are valid.
- Provider checkout hosts must be explicitly allowlisted.
- Public policy responses redact provider identity, gate evidence, missing-gate names and incident details.
- Public operational conflicts do not disclose internal activation or incident reasons.
- Webhook bodies and headers are size- and format-bounded before provider verification.
- Guest financial references use first-party HttpOnly SameSite cookies with a bounded lifetime; prompt state remains privacy-minimized.
- Financial exports require the dedicated finance-export capability and remain audience- and expiry-bound.
- Posted ledger/audit history is not deleted during ordinary uninstall.

## Supported source versions

Security corrections are applied to the open `1.2.0-rc.1` source-candidate branch. Live operational support begins only after staging, independent security acceptance and explicit Founder activation.
