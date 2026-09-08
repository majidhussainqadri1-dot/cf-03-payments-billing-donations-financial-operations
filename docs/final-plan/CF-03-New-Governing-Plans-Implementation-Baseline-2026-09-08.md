# CF-03 New Governing Plans — Implementation Baseline

**Source candidate:** `1.3.0-rc.1`  
**Active schema:** `4.0.0`  
**Baseline date:** 2026-09-08  
**Status:** source implementation baseline; runtime collection remains fail closed.

## Governing sources

1. `SSH-PMP-2026-v3.0` — Sabri Social Homeopathy Platform Definitive Master Plan v3.0.
2. `CF-03-Payments-Billing-Donations-Financial-Operations-Conditional-Complete-Master-Plan-2026-v1.0`.

No earlier recovered directive, CF-03 v2.0 text, README, PR description or runtime code overrides these two current plans.

## Reconciled constitutional decisions

- one free core tier;
- approved structured education is free;
- approved Sabri Classical Homeopathy AI core capability is free;
- voluntary one-time donation only;
- no recurring donation control/mandate or automatic repeat charge;
- at least seven days between eligible donation appeals after display/dismissal/completion;
- no donation amount preselected;
- donor/non-donor access, rank, verification, support, education, AI, quota and feature parity;
- Clinic/Marketplace platform commission 0%;
- File 00 remains access/entitlement authority;
- hosted/tokenized provider only; no raw card/bank secrets in CF-03;
- browser return is never financial settlement truth;
- wallet/escrow/custody/payout/split-payment flows remain disabled absent separate approved Change-Control.

## Source reconciliation performed

### Donation policy and appeal

`PlatformFinancialPolicy`, `DonationPromptPolicy`, `DonationPromptState`, `DonationAppealCopy`, REST, public UI and browser JavaScript now encode seven-day, one-time-only behavior and explicit one-time consent.

### Recurring/subscription retirement

The former active recurring/subscription model is not merely hidden in UI. Active creation/mutation paths are retired or fail closed:

- `RecurringConsent` is a compatibility tombstone;
- `DonationManagementService` recurring mutation methods fail closed;
- `SubscriptionOperationsService` fails closed;
- `DonationCheckoutService` creates only `donation.one_time` and never creates recurring consent;
- `WebhookIngestionService` quarantines retired financial-product events rather than reviving them;
- billing projection omits subscriptions and active recurring state.

### Free AI boundary

`AiUsageBillingService` is retained only as a fail-closed compatibility boundary. It cannot authorize charges, post AI receivables or meter AI through finance. Fair-use/reliability controls, if any, are non-financial concerns owned outside a paid CF-03 path.

### Active schema v4

`RuntimeSchemaExtension::VERSION = 4.0.0` and the active canonical schema excludes:

- `recurring_consents`;
- `subscriptions`;
- `usage_authorizations`;
- `usage_facts`.

Existing installations may physically retain legacy tables during bounded migration/audit retention. Their presence does not make them active truth and no current route/service may create a new recurring mandate or paid-AI/subscription state.

### Provider/webhook and accounting safety

Current active provider processing preserves:

- signature/timestamp/replay evidence checks;
- duplicate provider-event parity;
- intent/provider/amount/currency binding;
- chronology checks;
- immutable ledger postings;
- one-time donation receipts;
- refund reservation/reconciliation;
- no-access-event semantics;
- quarantine of legacy product events.

## Status boundary

This baseline may be called **Coded** only after exact-head syntax/current-plan tests pass. It may be called **Packaged** only after deterministic package parity passes on that same HEAD. It is not Staging-Accepted, Live-Deployed or Operational until the separate evidence gates in the governing plan are completed.
