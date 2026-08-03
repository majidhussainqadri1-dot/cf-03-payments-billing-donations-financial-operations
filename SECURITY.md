# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Do not place credentials, provider secrets, webhook signing keys, card data, personal identity evidence, financial records, or incident playbooks in a public issue.

## Non-negotiable controls

- Hosted or tokenized payment collection only.
- No PAN, CVV, PIN, OTP, magnetic-stripe data, raw bank credentials, or provider secrets in code, logs, support records, exports, browser storage, or ordinary WordPress options.
- Browser redirects never prove payment success.
- Webhook and provider facts require signature validation, replay protection, idempotency, and reconciliation before financial or entitlement effects.
- File 00 remains the entitlement authority.
- Runtime activation fails closed unless every configured approval gate is present.

Version `0.1.0` contains no provider adapter or live payment endpoint.
