# CF-03 — Forty source-to-plan audit lenses, corrective follow-up (21 Oct 2026)

## Evidence basis and meaning

Primary: CF-03 v1.1 Future40 Word master plan supplied by the Founder. Governing higher-order source: the supplied Three Central Plans Consolidated Governing Master Plan. Related supplied File 11/16/17/18, CF-01/CF-02 and platform-wide owner-contract boundaries. The numbered FR-001…034 headings and requirements are drawn from the provided CF-03 plan; six additional lenses test central/cross-module concerns. This is a **40-distinct-dimension source review** and an accompanying 40-assertion automated governance suite; **not forty complete independent Live repetitions**.

Initial repository main HEAD: `24a8bbff7fd1940191ac947e4a23455d0567a0a3`. Corrections live in Draft PR #9, candidate version `1.4.0-rc.2`; active source schema `4.0.0`, Future40 disabled. The exact deployed code is NOT verified.

**Source status categories:** `Source contract` proves code shape, not external implementation; `Source invariant` means the current source asserts the constraint, not that connected modules obey; `Corrected in Draft` means source patch with dedicated CI but no Live deployment; `Source guards` means binding code exists but cross-repository integration is not verified.

## Forty review lenses

| # | Governing requirement | Exact file/code evidence | Source status | Outstanding external proof |
|---:|---|---|---|---|
| 1 | FR-001 — Canonical financial product | `ApprovedDonationCatalogGate, DonationCatalogSeeder` | Corrected in Draft | Staging approved catalogue migration |
| 2 | FR-002 — Historical/versioned price policy | `PriceVersion, PriceCatalog, PriceLifecycle` | Source contract | Legal/accounting change approval |
| 3 | FR-003 — Pre-donation disclosure | `WordPressDonationDisclosure, WordPressRestApi, WordPressPublicUi` | Corrected in Draft | Approved actual provider/fee/tax/refund text; UI staging |
| 4 | FR-004 — Zero commission | `CommissionPolicy, PlatformFinancialPolicy` | Source invariant | File 08/18 staging |
| 5 | FR-005 — No donor privilege | `DonationNeutralityPolicy` | Source invariant | File 00/26 signed consumer parity |
| 6 | FR-006 — Hosted tokenized checkout | `DonationPaymentProvider, WordPressProviderRegistryFactory` | Source contract | Real provider + PCI |
| 7 | FR-007 — Idempotent checkout intent | `DonationCheckoutService, DonationIntentDraft` | Source corrected | Provider sandbox + concurrent replay |
| 8 | FR-008 — Trusted signed webhook | `ProviderWebhookVerifier, WebhookIngestionService` | Source contract | Live provider signature/key rotation |
| 9 | FR-009 — Provider state transitions | `ProviderEventStateMapper, ProviderEvidence` | Source contract | Full out-of-order event matrix |
| 10 | FR-010 — No browser success truth | `PaymentConfirmationService, WebhookIngestionService` | Source invariant | Real redirect/webhook journey |
| 11 | FR-011 — Provider exit/capability | `ProviderRegistry, PaymentProvider` | Source contract | Provider failover, exit and rollback |
| 12 | FR-012 — Balanced immutable ledger | `LedgerJournal, WordPressFinancialRepository` | Source contract | Independent restored-ledger tie-out |
| 13 | FR-013 — Receipts, snapshots and owners | `FinancialDocumentService, WordPressFinancialDocumentApi` | Source contract | Legal templates, HTML/PDF verification |
| 14 | FR-014 — Settlement files | `SettlementOperationsService` | Source contract | Approved bank/provider sample |
| 15 | FR-015 — Daily reconciliation | `DailyReconciliationService, WordPressDailyReconciliation` | Source contract | Staging fault injection |
| 16 | FR-016 — Adjustments and audit | `FinancialAdjustmentService` | Source contract | Independent role/threshold acceptance |
| 17 | FR-017 — Integer minor units | `Money, LedgerJournal` | Source invariant | Tax/FX jurisdiction acceptance if activated |
| 18 | FR-018 — One-time donation lifecycle | `DonationCheckoutService, WebhookIngestionService` | Source contract | Provider timeout/abandon tests |
| 19 | FR-019 — Seven-day prompt and one-time consent | `DonationPromptPolicy, DonationAppealCopy` | Source invariant | Browser persistence/RTL |
| 20 | FR-020 — Failure and outbox recovery | `OutboxDispatcher, dueOutbox repository contract` | Corrected in Draft | Consumer at-least-once replay drill |
| 21 | FR-021 — Donor/non-donor access parity | `DonationNeutralityPolicy, File00FinancialFact` | Source invariant | File 00/05/16/21/26 cross-repo consumers |
| 22 | FR-022 — Abandonment and refund clarity | `DonationManagementService, RefundWorkflowService` | Source contract | User/support journey |
| 23 | FR-023 — Free education and AI | `PlatformFinancialPolicy, AiUsageBillingService` | Source invariant | File 05/16 running acceptance |
| 24 | FR-024 — Refund amount/ownership | `RefundWorkflowService` | Source contract | Provider sandbox and user claims |
| 25 | FR-025 — Separated refund execution | `RefundWorkflowService, SeparationOfDutiesPolicy` | Source contract | Real provider uncertain/retry drill |
| 26 | FR-026 — Chargeback lifecycle | `RiskOperationsService` | Source contract | External provider dispute evidence |
| 27 | FR-027 — Private donation history/refunds | `BillingQueryService, FinancialDocumentService` | Source contract | Accounting/legal receipt review |
| 28 | FR-028 — Fraud manual review | `RiskOperationsService, FraudReviewCase` | Source contract | Approved velocity rules and appeal SLA |
| 29 | FR-029 — Finance role separation | `SeparationOfDutiesPolicy, WordPressFinanceAdminApi` | Source contract | Quarterly privileges/SoD drill |
| 30 | FR-030 — Financial privacy/minimization | `WordPressRestApi, WordPressPrivacy` | Source contract | Pen test and privacy assessment |
| 31 | FR-031 — Encrypted scoped exports | `SecureExportService, WordPressSecureArtifactStoreFactory` | Source contract | Real secure artifact store and expiry |
| 32 | FR-032 — Period lock and close | `FinancePeriod, SettlementOperationsService` | Source contract | Monthly close/reopen dry-run |
| 33 | FR-033 — Provider/key incidents | `IncidentOperationsService, WordPressIncidentStateStore` | Source contract | Key incident/tabletop drill |
| 34 | FR-034 — Backup/restore reconciliation | `BackupManifest, RestoreReconciliation` | Source contract | Isolated restoration plus provider match |
| 35 | CROSS-01 — Three central plans supremacy | `PlatformFinancialPolicy, GoverningPlanRegistry` | Source references | Newest founder decision/versioned owner acceptance |
| 36 | CROSS-02 — Files 00/05/16 access and free AI | `RuntimeConfiguration, DonationNeutralityPolicy` | Source guards | Actual module-to-module parity |
| 37 | CROSS-03 — Files 08/18 zero-commission direct payments | `CommissionPolicy, PlatformFinancialPolicy` | Source guards | Real deal/clinic finance boundaries |
| 38 | CROSS-04 — Files 17/19/CF-02 notifications and cases | `WordPressOutboxTransport, OutboxDispatcher` | Source guarded | Consumer durable ACK + case integration |
| 39 | CROSS-05 — Files 20/24/25 security-shell-design | `WordPressPublicUi, WordPressRequestGuard` | Source guards | Independent security + UX acceptance |
| 40 | CROSS-06 — Future40, schema, build/release parity | `FutureExpansionRegistry, CompleteSchema, CI` | Source + CI green | Exact deployed checksum/DB migration/live re-test |

## Newly proven source defects in this continuation

**CF03-CODE-008 — Due-time queue starvation.** Old `OutboxDispatcher` used repository `find(state)` ordered by auto-increment ID DESC. New arrivals could displace older eligible work; future-dated pending candidates could occupy the bounded sample, and the first N unexpired processing leases could conceal later expired leases. Correction: `QueryableFinancialRepository::dueOutbox()` plus MySQL and in-memory implementations filter by due deadline, order oldest-first, and cap batch size; `OutboxDispatcher` merges pending/retry by due time and selects overdue processing leases directly. Dedicated tests reproduce 80 future pending and 80 live worker leases before an expired tail event. Source only; full volume/concurrency staging tests remain mandatory.

**CF03-CODE-009 — Missing approved donor disclosure at the live collection boundary.** The public donation UI and API were not requiring Founder-approved provider name, purpose, fee, tax, refund, support and data-use terms before hosted checkout. Correction: `WordPressDonationDisclosure` looks up a provider- and financial-policy-matched approved record; public availability, server-side donation mutation and UI gate all fail closed without complete disclosure. No provider terms are fabricated or automatically approved. Legal/tax/provider copy and user/browser acceptance remain external prerequisites.

**CF03-QA-001 — Divergent test entry points.** `composer test` originally ran only three older suites while CI ran additional governing and security tests. Corrected by including all twelve active suites in composer scripts; final CI must confirm JSON and scripts parity.

## Remaining blockers

1. GitHub repository About/description still advertises an obsolete paid/tier model (issue #6); connector currently has no supported repository metadata writer.
2. Native atomic pre-provider rate/velocity control and WAF/provider controls need evidence and approved thresholds (issue #10); absence of visible native code is not proof that deployed WAF does nothing.
3. Live site WPVibe connection remains unavailable: earlier Cloudflare HTTP 530; most recent attempt returned provider-side `D1 DB is overloaded` from connector, which is not evidence of a WordPress root cause (issue #11). No exact deployed artifact, live DB, migration or logs were retrieved.
4. Cross-repository File 00/05/08/16/17/18/19/20/24/25/26/CF-02 versioned contracts and durable consumers, legal/tax/PCI, host provider accounts, staging browser/RTL/a11y, real reconciliation, backup/restore, rollback and Founder acceptance are not verified.
5. Passing 40/40 source governance checks plus targeted CI is insufficient to call the finance module operational, Live deployed or incident resolved.

## Release truth report

| Repository HEAD (`main`) | `24a8bbff7fd1940191ac947e4a23455d0567a0a3` at the start of this continuation |
|---|---|
| Draft correction candidate | `1.4.0-rc.2` (read exact HEAD and CI just before review) |
| Source schema | `4.0.0` / 27 canonical active tables per declaration |
| Deployed version | **Unverified** |
| Live DB version | **Unverified** |
| Migration state | **Unverified** |
| Live verification | **Not performed** |

Exact deployed code is unverified; repository-based diagnosis about Live behavior remains provisional. No direct payment activation, production changes or database writes were performed.
