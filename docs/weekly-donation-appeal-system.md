# Weekly Donation Appeal System — Source and Integration Specification

## Governing status

This specification implements Founder Decision `SSH-FIN-2026-08-04-01`. It defines CF-03 financial policy and integration contracts. It does not make CF-03 the global shell or visual-system owner and does not enable live collection.

## Outcome

The platform remains completely free. Every paid membership, education, AI and platform-service product is dormant and non-collectible. The only currently permitted financial purpose is voluntary donation.

## Financial choices

- USD 10
- USD 14
- USD 50
- positive custom USD amount
- optional monthly donation, unchecked by default

No amount is preselected. A donation cannot map to entitlement, access, ranking, verification, moderation, visibility, support priority, clinic priority, marketplace priority, education result or clinical access.

## Bilingual copy contract

### Urdu

**اس علمی و انسانی خدمت کو قائم رکھنے میں ہمارا ساتھ دیجیے**

صابری سوشل ہومیوپیتھی پلیٹ فارم فی الحال تمام صارفین کے لیے فی سبیل اللہ مکمل مفت رکھا گیا ہے۔ اس نظام کو قائم رکھنے، مزید وسعت دینے، نئی آسانیاں پیدا کرنے، علمِ ہومیوپیتھی کی حفاظت و اشاعت، انسانیت کی خدمت، اور دنیا بھر کے ڈاکٹروں اور مریضوں کی بھلائی کے لیے آپ کی اختیاری اعانت ہمارے لیے قیمتی ہے۔

مستقل خدمت میں تعاون کے لیے آپ اختیاری ماہانہ اعانت بھی منتخب کرسکتے ہیں۔

اعانت مکمل اختیاری ہے۔ اعانت نہ دینے سے آپ کی رسائی، عزت، ranking، verification یا کسی سہولت پر کوئی اثر نہیں پڑے گا۔

### English (US)

**Help Us Sustain and Expand This Service**

Sabri Social Homeopathy Platform is currently provided completely free for the sake of serving humanity. Your voluntary support helps us maintain the platform, introduce new facilities, preserve and advance homeopathic knowledge, and serve doctors and patients around the world.

Donations are entirely optional. Donating or not donating will never affect your access, visibility, verification, ranking, or eligibility for any core service.

## Prompt-state model

Logged-in state is server-side and consists of:

- `last_donation_prompt_at`
- `donation_prompt_status`
- `donation_prompt_snoozed_until`
- `last_donation_completed_at`
- `donation_frequency_preference`

Guest state is a privacy-safe first-party browser projection:

- `sabri_donation_prompt_seen`
- `sabri_donation_prompt_next_at`

The guest projection must not contain account identity, donation amount, provider reference or sensitive financial data.

## Decision order

The prompt is suppressed when any of the following is true:

1. the subject is an active monthly donor;
2. the current context is sensitive;
3. the prompt was already shown in the page view;
4. checkout failed in the current session;
5. the user has not reached thirty seconds or a meaningful interaction;
6. the seven-day shown/snooze cap has not expired;
7. a completed one-time donation is within its thirty-day suppression period.

The maximum applicable future date controls. This prevents a later donation from accidentally shortening an existing suppression interval.

## Sensitive contexts

The appeal is prohibited during:

- login;
- registration;
- password recovery;
- guardian consent;
- clinical consultation;
- emergency warning;
- support or complaint appeal;
- payment error.

File 20 must also avoid mounting it over essential article/video content or during another blocking notice. File 25 must render it as a dismissible dialog with visible close, keyboard escape, focus return, focus containment while open, screen-reader name/description, touch-safe controls, RTL/LTR support, reduced motion and mobile reflow.

## Actions

- `Support Now`: validates amount and explicit monthly consent, then requests CF-03 donation intent. In `preparing`, it returns a non-financial service-preparation state.
- `Remind Me Later`: minimum seven-day snooze.
- `Not Now`: minimum seven-day snooze.
- `Close`: minimum seven-day snooze and no penalty.

## Provider boundary

No platform-owned form collects PAN, CVV, PIN, OTP, bank password or raw financial credentials. When future gates are approved, CF-03 creates only a hosted/tokenized provider session. Browser return is not proof of donation; signed provider evidence, idempotency, ledger posting and reconciliation remain mandatory.

## Cross-file integration

### CF-03

Owns financial policy, amount validation, donation intent, recurring consent fact, provider reference/evidence, receipt/refund, ledger and prompt-state policy.

### File 20

Owns global-shell mounting and route/context timing. It receives a pure decision/result contract and must not calculate financial eligibility or write CF-03 financial records.

### File 25

Owns modal presentation and accessibility. It consumes copy, choices and state, and emits declared actions. It must not create payment intents or infer donation completion.

### File 24

Consumes security/privacy/provider assurance evidence. CF-03 native controls continue if File 24 is unavailable.

### File 19

Delivers receipt or recurring donation notifications only after a trusted CF-03 event and applicable consent/preferences.

### File 00

Remains identity/entitlement authority. Donation never produces an entitlement command.

## Acceptance tests

- Paid membership/education/AI checkout is rejected.
- Donation is the only collectible product kind.
- Exact suggested amounts and custom positive USD validation pass.
- No default amount or recurring state exists.
- Weekly cap cannot be bypassed by prompt action variants.
- One-time donation suppresses for thirty days.
- Active monthly donor is suppressed without an arbitrary expiry.
- Sensitive contexts, page-view cap, checkout-failure cap and engagement threshold work.
- Logged-in storage round-trips all five fields.
- Guest contract exposes only the two approved keys.
- Explicit monthly consent is mandatory.
- Preparing state creates no hosted checkout.
- Provider payload excludes donor canonical identity and secrets.
- PHP 8.1–8.3, JSON manifests and deterministic package parity pass.

## External acceptance

Files 20 and 25 must separately implement and accept the mount/render contracts. Live donation collection additionally requires provider, legal/tax/accounting, PCI, independent security, Hostinger staging, restore/rollback and Founder acceptance evidence.
