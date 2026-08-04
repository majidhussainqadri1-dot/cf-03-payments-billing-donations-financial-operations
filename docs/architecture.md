# CF-03 Architecture — 1.0.0-rc.2

## Status boundary

This release is a **free-platform, donation-only conditional financial source candidate**, not a live payment processor. Founder Decision `SSH-FIN-2026-08-04-01` is the governing financial policy. Runtime collection remains disabled.

## Canonical ownership

CF-03 owns financial-policy enforcement, dormant product/price history, donation amount and intent, provider references/evidence, recurring donation mandates, receipts/refunds, immutable ledger transactions, reconciliation, finance exports and financial audit evidence.

It does **not** own:

- membership, role, feature entitlement or access truth — File 00;
- Clinic or Marketplace direct-deal funds — platform commission remains 0%;
- global-shell mounting — File 20;
- modal presentation, responsive behavior or accessibility rendering — File 25;
- security/privacy policy assurance ownership — File 24, while native CF-03 enforcement remains preserved;
- notification transport — File 19;
- escrow, wallet, remittance, lending, investment, cryptocurrency custody, payouts or split payments;
- support authority to mutate ledger or provider state — CF-02 may request and observe through contracts only.

## Governing layers

1. **WordPress bootstrap:** plugin loading, non-destructive activation metadata, registered prompt-state fields and Site Health visibility.
2. **Founder financial policy:** all core services free; paid products dormant/non-collectible; donation only; commission 0%; no donation privilege.
3. **Donation prompt policy:** seven-day cap, thirty-day post-donation suppression, active-monthly suppression, sensitive-context and engagement gates.
4. **Donation presentation contract:** exact bilingual copy, amounts, actions, guest keys and logged-in fields for Files 20/25.
5. **Donation intent:** positive USD amount, explicit monthly consent, idempotency and service-state gate.
6. **Activation governance:** runtime constant, canonical evidence-record hash, versioned approvals, expiry and duplicate-evidence checks.
7. **Provider evidence:** hosted/tokenized mode, signature/replay/event uniqueness, provider/intent/amount parity and unknown-state quarantine.
8. **Financial invariants:** exact money, immutable balanced ledger, 0% commission and donation non-privilege.
9. **Integration contracts:** File 00 facts, File 24 assurance evidence, File 19 notifications and provider-neutral ports.
10. **Future persistence/application services:** live provider adapter, webhooks, receipts, refunds, settlement and operational reconciliation after external acceptance.

## Free-policy law

- Registration, membership, public knowledge, basic education and all core platform services are free.
- PKR 400 membership, AI add-on/usage and other earlier price records remain historical/dormant; they are not deleted, but cannot create checkout.
- `CheckoutCommand` applies `PlatformFinancialPolicy`; every non-donation product is rejected while the decision remains active.
- Only a donation product with voluntary billing and no entitlement mapping can be collectible.
- Re-enabling any fee requires a new Founder Change-Control, migration, tests, staging and rollback evidence.

## Donation prompt law

The source combines the latest applicable suppression date from:

- last prompt + seven days;
- Remind Me Later/Not Now/Close snooze + seven days;
- completed one-time donation + thirty days.

Active monthly donors are suppressed without an arbitrary expiry. The prompt cannot show during sensitive contexts, before engagement, more than once per page view or again in the same session after checkout failure.

Logged-in truth is server-side. Guest storage is a first-party, privacy-minimized projection using only `sabri_donation_prompt_seen` and `sabri_donation_prompt_next_at`.

## Activation governance

A boolean option is insufficient. Future live donation approval requires:

- `SABRI_CF03_RUNTIME_ACTIVATION === true`;
- a valid `SABRI_CF03_ACTIVATION_EVIDENCE_HASH`;
- a canonical record for module version `1.0.0-rc.2`;
- Founder, legal/tax/accounting, PCI, independent security, staging and rollback evidence;
- validated hosted/tokenized provider evidence;
- a legal entity/receiving account and explicit Founder collection activation.

Passing the evidence gate does not create a live route in this release.

## Idempotency, privacy and evidence law

- Mutation keys are scoped, actor-bound, request-fingerprinted and immutable.
- Floating-point money is rejected.
- Provider events cannot map to financial success until signature, replay, uniqueness and parity checks pass.
- No platform form or data store accepts PAN, CVV, PIN, OTP, raw bank credentials or provider secrets.
- Browser return pages never prove completion.
- File 00 receives only past-tense financial facts and decides entitlement/access independently.

## Packaging

The build script creates a deterministic `1.0.0-rc.2` WordPress ZIP with one canonical top-level folder and SHA-256 record. Tests, CI metadata and repository-only build files are excluded from the installable package.
