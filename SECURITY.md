# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Never place credentials, provider secrets, webhook signing keys, card data, personal identity evidence, financial records, activation evidence, or incident playbooks in a public issue.

## Non-negotiable controls

- Hosted or tokenized payment collection only.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials, or provider secrets in code, logs, support records, exports, browser storage, or ordinary WordPress options.
- Browser redirects never prove payment success.
- Provider facts require signature validation, replay-window validation, event-ID uniqueness, provider/intent/amount parity, durable idempotency, ledger posting, and outbox reconciliation before entitlement facts may be emitted.
- Unknown provider states are quarantined.
- File 00 remains the entitlement authority; CF-03 never writes access directly.
- Activation fails closed unless a versioned evidence record matches the configured SHA-256 and all required evidence blocks are valid.
- Checkout provider hosts must be explicitly allowlisted.

Version `0.2.0` contains no live checkout, provider implementation, webhook route, refund executor, or financial persistence migration.
