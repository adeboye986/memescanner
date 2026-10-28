# Dashboard UX Revamp — Phase 1 Audit

Date: 2026-10-28
Scope: read-only review of the Laravel dashboard and its TypeScript trading-engine presentation boundary
Disposition: audit and planning only; no redesign, route, schema, configuration, or trading-behavior change is authorized by this report

## Executive summary

The application has the functional pieces of a trading platform, but its customer-facing information architecture still reflects the order in which those pieces were built. The main dashboard combines onboarding, trading preferences, PAPER strategy, scanner controls, scanner history, operational health, two PAPER ownership models, wallets, positions, and manual exits. The result is technically rich but difficult to scan, especially on mobile.

The most consequential confirmed UX problem is scanner result presentation. TypeScript correctly stores execution-level counts and candidate-level outcomes in PostgreSQL and emits a bounded terminal event. Laravel safely projects the aggregate event, but the dashboard:

- maps a TypeScript succeeded_with_warnings result to the generic activity status completed;
- retains the warning outcome in result_summary but never renders that outcome;
- describes engine-owned scans as Laravel scanner commands;
- defaults engine fields that are not present in the version-1 event to zero and then displays those zeros as if they were measured;
- hides skipped, candidate-failure, and provider-warning counts inside a collapsed recent-activity item; and
- has no user-facing path to the candidate reason codes retained in PostgreSQL.

That explains why the cited production scan could be understood only through database inspection. The data was not lost, but the presentation model does not expose the distinctions a user needs.

No Critical data-integrity or authorization defect was confirmed in this UI audit. The highest-priority work is to make existing states truthful and legible before changing the visual style: introduce a dedicated Token Scanner surface, preserve completed-with-warnings as a first-class state, stop presenting unavailable engine metrics as zero, and move operational diagnostics away from the customer overview.

The proposed structure—Overview, Token Scanner, Opportunities, Trading, Wallets, and Settings & Diagnostics—fits the actual implementation. It should be introduced additively, preserving historical Laravel records, engine projections, signed webhooks, user isolation, and every LIVE safeguard.

## Audit method and boundaries

This report is grounded in:

- the registered Laravel routes in routes/web.php and a read-only route listing;
- customer and administrator layouts and Blade views;
- dashboard, activity, opportunity, trade-history, account, wallet, settings, and onboarding controllers/services;
- resources/js/app.js and the activity poller;
- Laravel scanner request/event projection and SystemActivity presentation;
- PostgreSQL migration 010 and the TypeScript scanner terminal-event publisher; and
- relevant Laravel feature tests and frontend poller tests.

This was a source and route audit. It did not connect to production, run a scan, create data, modify feature flags, exercise LIVE trading, or visually test every viewport in a browser. Responsive and accessibility findings based solely on markup are identified as such.

### Finding labels

- **Confirmed** means the behavior is directly present in current source.
- **Risk** means the implementation creates a credible UX or operational failure mode that should be tested.
- **Recommendation** is proposed Phase 2 work and is not a statement that the feature exists today.

## 1. Current dashboard inventory

### 1.1 Customer and operator pages

| Area | Route and implementation | Current responsibility | Primary data source |
|---|---|---|---|
| Dashboard | GET /dashboard; PaperTradingDashboardController; resources/views/dashboard.blade.php | Trading mode, entry mode, opportunity summary, onboarding alerts, PAPER strategy, scanner/manual actions, activity, legacy wallets/positions, engine projections, and—when authorized—operations/engine health | Laravel SQLite models and services plus TypeScript projection tables |
| Activity polling | GET /dashboard/activity; SystemActivityController; SystemActivityService; resources/views/partials/system-activity.blade.php; resources/js/app.js | Current/recent manual work, scanner summaries, tracker status, and polling updates | system_activities and scanner result_summary JSON |
| Onboarding | GET /onboarding; OnboardingController; resources/views/onboarding.blade.php | Account readiness, Telegram, execution/entry preferences, PAPER strategy, and first scan action | User/settings/readiness services |
| Opportunities | GET /opportunities and GET /opportunities/{opportunity}; OpportunityController; resources/views/opportunities/* | User-scoped qualified opportunities, filters, assessment detail, ignore/approve, and chain-specific confirmation preparation | Laravel opportunity projections and execution-attempt relations |
| Trade history | GET /trades; TradeHistoryController; PaperTradeHistoryService; resources/views/trades/index.blade.php | Funded legacy PAPER positions, performance summaries, exit breakdown, and filters | Laravel PaperPosition and exit records |
| Account | GET /account; AccountSettingsController; resources/views/account/edit.blade.php | Profile, email, password, and both wallet-management interfaces | User plus active verified Solana/Ethereum wallet records |
| Telegram | GET /settings/telegram; UserTelegramBotController | Personal bot linking and notification interaction | User Telegram bot configuration |
| Platform settings | GET /settings; SettingsController; resources/views/settings/index.blade.php | Admin execution/entry preferences, PAPER strategy, integration status, provider secrets, risk controls, tests, and audit history | Application settings and integration health |
| Admin overview | resources/views/dashboard/admin.blade.php under the same dashboard controller | Platform metrics, scanner controls, health, wallets, positions, activity, and engine projections | Mixed Laravel operational and projected engine data |
| Ethereum eligibility | /ethereum-eligibility/*; reviewer-authorized controllers/views | Privileged evidence collection, confirmation, reconsideration, and publication | Eligibility evidence and audit records |

The wallet routes under /wallets/solana/* and /wallets/ethereum/* are action and data endpoints, not standalone wallet pages. Their user interface is embedded in Account. LIVE confirmation is embedded in opportunity detail flows rather than represented by a consolidated LIVE trading page.

### 1.2 Navigation and shells

The customer shell in resources/views/components/layouts/app.blade.php:15-47 is a single horizontal row containing Dashboard, Opportunities, Trade History, optional admin Settings, Telegram Bot, Setup, Account, and Sign Out. It has no customer mobile drawer, wrap behavior, or explicit horizontal overflow treatment.

The administrator shell in resources/views/components/layouts/admin.blade.php uses a responsive sidebar, backdrop, grouped headings, and a mobile menu. It is structurally stronger, but several entries are dashboard fragment links, so navigation still points into one large page instead of purpose-built operational pages.

The two shells also establish different visual languages:

- customer: slate/emerald radial background, compact top navigation;
- administrator: deep navy, blue/violet gradients, icon-led sidebar, larger panels.

This makes role transitions feel like switching products rather than entering a privileged area of one platform.

### 1.3 Dashboard composition

PaperTradingDashboardController assembles both customer and administrator data. It loads:

- user and legacy/null-owned PAPER wallets;
- legacy open PAPER positions and detailed derived presentation values;
- engine PAPER wallet and position projections;
- current and recent system activity;
- PAPER strategy and execution/entry preferences;
- opportunity summaries;
- integration, operations, engine, and onboarding status; and
- administrator overview metrics.

resources/views/dashboard.blade.php then presents most of those concerns on one page:

1. page heading and multiple mode/tracker badges;
2. onboarding, LIVE, and session notices;
3. five summary cards, several of which repeat the header state;
4. optional administrator operational and engine-health panels;
5. execution/entry preference controls;
6. legacy PAPER wallets and legacy ledgers;
7. PAPER strategy controls;
8. scanner/manual command controls and system activity;
9. detailed legacy open-position cards with manual close; and
10. a separate engine-owned PAPER positions partial.

The administrator dashboard is shorter in line count but equally dense because much of its Blade markup is compressed onto long lines. It includes scanner controls, background-process health, engine health, wallet/ledger sections, positions, activity, and the close-position dialog.

### 1.4 Reusable UI and JavaScript

Reusable components exist, but they are area-specific:

- x-admin.panel, x-admin.stat-card, and x-admin.status-badge;
- x-settings.input and x-settings.secret;
- opportunity status/execution partials;
- wallet connection/swap cards;
- system activity, scan result, health, and engine-position partials.

resources/css/app.css imports Tailwind and defines Instrument Sans; it does not define a shared product-level token or component layer. Most visual decisions are repeated utility strings in Blade.

resources/js/app.js centrally handles:

- wallet cards and swap interactions;
- opportunity execution interactions;
- administrator sidebar behavior;
- position-close dialog behavior;
- trading preference warnings;
- dashboard action state;
- activity rendering and scanner summary rendering.

resources/js/dashboard-activity-poller.js provides useful resilience: bounded request timeout, no overlapping polls, visibility pause/resume, authentication expiry handling, and unavailable/stale states.

### 1.5 Trading and ownership surfaces

The UI currently exposes ownership implementation details directly:

- “Legacy Laravel PAPER” wallets and positions;
- separate “Engine-owned PAPER” wallets/positions;
- scanner controls described as Laravel queue-backed commands even when ownership routing sends a canary request to TypeScript.

The separation is important internally and must remain auditable, but it should not be the primary customer information hierarchy. A customer needs a truthful consolidated view with an ownership/source detail available on demand.

Historical Laravel PAPER records are still available through the dashboard and Trade History. Engine-owned PAPER state is available through projections, but the existing trade-history page is centered on Laravel PaperPosition data. No single current page presents all open positions, closed positions, orders, executions, and settlement evidence across both ownership generations.

### 1.6 Existing automated coverage

The repository has strong domain and safety coverage, including:

- dashboard authorization and scoped data;
- dashboard scan dispatch and duplicate prevention;
- TypeScript scanner canary routing, durable delivery, ambiguous acceptance, recovery, and projection;
- opportunity ownership and chain-specific LIVE confirmation safeguards;
- PAPER decision, entry, lifecycle, and settlement ownership boundaries;
- wallet challenge, ownership, balance, transaction, and disconnect behavior;
- legacy trade-history filtering and performance calculations; and
- activity poller timeout, overlap, hidden-tab, stale, redirect, and authentication behavior.

The visible gap is presentation coverage. The frontend tests concentrate on the poller rather than the rendered information hierarchy. No test found asserts that:

- succeeded_with_warnings is visibly different from succeeded;
- engine-only absent metrics render as unavailable rather than zero;
- the server-rendered and JavaScript-rendered scan summaries communicate the same fields;
- customer navigation remains usable at narrow widths;
- scanner diagnostics meet keyboard/screen-reader expectations; or
- legacy and engine positions are presented in one coherent customer workflow.

## 2. UX findings

### 2.1 Severity summary

| Severity | Count | Interpretation |
|---|---:|---|
| Critical | 0 | No confirmed UI defect that directly compromises authorization, financial integrity, or trading safety |
| High | 7 | Core workflows are difficult to interpret or reach, or the UI communicates materially incomplete state |
| Medium | 9 | Significant clarity, consistency, responsiveness, and maintainability issues |
| Low | 4 | Polish and secondary usability issues |

### 2.2 High-severity findings

#### H-01 — Scanner warning outcomes are retained but not presented

**Confirmed evidence**

- TypeScript persists succeeded_with_warnings and emits it in token.scan.completed.v1 when provider failures or candidate failures exist (trading-engine/src/workers/token-scan-dispatcher.ts:1210-1270).
- Laravel stores the exact outcome under system_activities.result_summary.outcome (app/Services/TradingEngine/TradingEngineScanProjector.php:106-142).
- Laravel deliberately maps every non-failed engine terminal outcome to the parent activity status completed (same file, lines 132-140).
- SystemActivityService retains outcome during safe normalization, but resources/views/partials/scan-result-summary.blade.php and resources/js/app.js do not render it.
- Recent activity displays the parent completed label, which uses the normal success color.

**Impact**

A degraded scan appears successful until the user expands a collapsed activity and interprets raw counts/codes. This is the central cause of the cited diagnostic failure.

**Recommended correction**

Make scan outcome a first-class presentation state. Show “Completed with warnings” in amber at summary, history, and detail levels while retaining completed as the parent execution’s terminal state internally. Never infer warning state from color alone.

#### H-02 — The scanner has no dedicated results or history workflow

**Confirmed evidence**

Scanner actions and results are embedded in the dashboard’s Manual Scanner & Operations/System Activity region. The recent list is a bounded, collapsed activity list; SystemActivityService returns at most 10 recent records and the dashboard requests 8. There is no GET scanner route or scanner detail/history controller.

**Impact**

Users cannot reliably find a past scan, compare scans, inspect a result funnel, or navigate from an opportunity to its originating scan. Operational activity is being used as a product history interface.

**Recommended correction**

Add an authenticated Token Scanner area with Run, Current, History, and Detail views. Keep the existing activity record as durable evidence, but do not make it the only discovery mechanism.

#### H-03 — Candidate-level failure reasons are stored but unavailable to users

**Confirmed evidence**

PostgreSQL token_discoveries stores asset identity, provider, state, reason_code, market/security/qualification snapshots, and opportunity_id (migration 010, lines 79-125). The terminal event contains only seven aggregate counts, provider failure codes, and one execution error code. Laravel projects only that event payload.

**Impact**

The dashboard can say that eight candidates failed but cannot say which candidates failed or why. Operators must query PostgreSQL, and a customer cannot distinguish security rejection, malformed provider data, missing liquidity, or another safe reason.

**Recommended correction**

Introduce a versioned, user-scoped candidate diagnostic read model. Options are a bounded engine query API or an additive authenticated event/projection contract. Do not expose direct PostgreSQL access to Laravel views and do not include raw provider bodies or secrets.

#### H-04 — The main dashboard is an overloaded multi-workflow page

**Confirmed evidence**

The customer dashboard combines preferences, strategies, scanner submission, operational activity, wallets, portfolio, positions, engine projections, alerts, and manual exits. Administrator health is also conditionally injected into the same controller/view family.

**Impact**

There is no clear primary task. Important alerts compete with configuration forms and low-level tracker information. Users must scroll through unrelated sections to reach positions or scanner results.

**Recommended correction**

Restrict Overview to current mode, connection/engine/scanner health, actionable alerts, portfolio/open positions, latest scan, opportunities, and recent relevant activity. Move controls and diagnostic detail to dedicated pages without deleting them.

#### H-05 — Customer navigation is not viable as the product grows and is risky on mobile

**Confirmed evidence**

The customer shell renders up to eight links plus brand and Sign Out in one flex row. It has no mobile disclosure, wrapping, or overflow handling. The administrator shell already has a responsive drawer pattern.

**Impact**

At narrow widths, links can compress or overflow, and adding Scanner, Trading, and Wallets would make the row untenable.

**Recommended correction**

Adopt a shared responsive sidebar/drawer with a compact mobile header. Reuse the established administrator mechanics, but align both roles to one design system and keep unauthorized links absent.

#### H-06 — Wallet trading controls are placed inside Account

**Confirmed evidence**

GET /account loads both verified wallet records. resources/views/account/edit.blade.php places full Solana and Ethereum wallet cards—including balances, swap/transaction interactions, and disconnect controls—before profile and password forms. There is no Wallets page.

**Impact**

Trading infrastructure obscures identity/security settings, and users looking for wallet balances would not naturally choose Account.

**Recommended correction**

Move wallet connection, balances, transaction history, and chain-specific wallet actions into Wallets. Keep profile, email, password, and session/security concerns in Account. Preserve every existing wallet authorization and endpoint.

#### H-07 — Ownership labels are technically accurate in places but misleading in others

**Confirmed evidence**

Dashboard action buttons say “Laravel queue-backed command,” and SystemActivityService summaries say “Laravel scanner,” even when TradingEngineScannerOwnership routes an authorized canary scan to TypeScript. Elsewhere, “Legacy Laravel” and “Engine-owned” are emphasized as top-level portfolio categories.

**Impact**

Users cannot form a stable mental model of who performed a scan or owns a position. Operator implementation terms dominate customer language while still being wrong for cut-over scans.

**Recommended correction**

Use customer language first: “New-token scan,” “PAPER position,” and “Completed with warnings.” Expose “Execution owner: TypeScript engine” or “Historical owner: Laravel” in a detail/evidence row for support and reconciliation.

### 2.3 Medium-severity findings

#### M-01 — Status is duplicated across header badges and summary cards

Trading mode, entry mode, tracker health, opportunity state, and integration state are repeated near the top of the dashboard. Repetition consumes the most valuable viewport without adding a decision.

**Fix:** one compact mode/status strip and one alert rail. Expand only abnormal states.

#### M-02 — Absent engine metrics are displayed as measured zeroes

SystemActivityService.safeResultSummary normalizes legacy-oriented fields such as downstream_failed, exports_acknowledged, and every export state to zero when they are absent. The TypeScript terminal scan event does not carry those fields, yet both Blade and JavaScript display them.

This avoids a rendering exception, but it changes “not supplied by this contract” into “measured as zero.”

**Fix:** define source-aware presentation capabilities. Render unsupported fields as “Not reported” or omit them. Only show zero when the source contract actually reported zero.

#### M-03 — Scanner result language loses meaningful failure counts for zero-result scans

SystemActivityService uses the zero_result branch before its detailed completed summary. A scan with 0 qualified, 12 skipped, and 8 failed is summarized as completing successfully with zero qualified opportunities, without mentioning the failures or warning outcome.

**Fix:** outcome-aware copy should lead with “Completed with warnings” and include the candidate disposition.

#### M-04 — Technical operations are exposed on customer-facing surfaces

Activity cards include queue-worker wording, last structured progress timestamps, export states, process leases, heartbeats, provider success, and batch detail. Some information is useful to support staff but distracts customers and risks implying they must understand internal workflow machinery.

**Fix:** display plain-language status and action to customers; put safe identifiers, queue/export states, leases, and health telemetry in role-gated Diagnostics.

#### M-05 — Trading is fragmented across Dashboard, Opportunities, Trade History, and Account

Open positions and manual closes are on Dashboard; opportunity approval/confirmation is under Opportunities; legacy closed/open funded records are under Trade History; wallet transaction history is under Account. Engine-owned positions are a separate partial.

**Fix:** create Trading with Positions, Orders/Executions, and History tabs. Keep Opportunity analysis separate and link the records bidirectionally.

#### M-06 — Platform Settings combines unrelated and differently sensitive concerns

The administrator settings page combines execution preference, entry behavior, PAPER strategy, integration status, Telegram secrets, provider/RPC credentials, integration tests, kill switch, future LIVE risk controls, and configuration audit.

**Fix:** split into Trading Policy, Integrations, Notifications, Risk & Safety, and Audit/Diagnostics sections while retaining the same authorization.

#### M-07 — Visual design is inconsistent between customer and administrator areas

The customer shell uses emerald accents and a simple top bar; the administrator shell uses blue/violet accents and a polished sidebar. Status colors, border radii, headings, and panel density are repeated ad hoc.

**Fix:** one semantic token set and shared navigation/panel primitives, with role indicated by content and badges rather than a different product skin.

#### M-08 — Mobile content density remains high even where grids collapse

Many grids have responsive breakpoints, which is positive. However, the scanner summary renders nine tiny metrics in three columns on small screens, position cards contain extensive strategy/market detail, and the primary customer navigation has no collapse behavior.

**Fix:** mobile summaries should show three to five priority metrics, with secondary detail behind disclosure. Validate at 320, 375, 768, and 1024 CSS pixels.

#### M-09 — Presentation-state regression tests are missing

Domain safety tests are extensive, but current frontend coverage does not prove warning-state visibility, scan summary parity, mobile navigation, empty/error states, or accessibility of new interactions.

**Fix:** add component/feature assertions for rendered semantic states, frontend DOM tests for polling updates, and browser-level keyboard/responsive smoke tests in Phase 2.

### 2.4 Low-severity findings

#### L-01 — Status terminology is inconsistent

Examples include Dashboard versus Overview, Trade History versus positions, Setup versus onboarding, connected/configured/active/stale, and completed versus succeeded.

**Fix:** establish the vocabulary in section 6 and map internal states to it.

#### L-02 — Some controls rely on styling more than explicit affordance

Activity details, compact status badges, and some admin icon treatments depend heavily on color and small labels.

**Fix:** add text/icon pairing, visible focus states, and explanatory labels. Do not use color as the only signal.

#### L-03 — Dense one-line Blade reduces maintainability

Several administrator/settings templates compress entire panels into single physical lines. This is not a runtime defect, but it makes review, accessibility maintenance, and consistent component extraction harder.

**Fix:** reformat only when those views are touched for authorized Phase 2 work.

#### L-04 — Financial number presentation varies

tabular-nums is used in some metrics but not consistently. Currency/native-asset precision and positive-sign treatment vary by surface.

**Fix:** centralize display formatters for fiat, native assets, percentages, market cap, and unavailable values.

## 3. Navigation restructuring proposal

### 3.1 Current versus proposed

| Current destination | Current content | Proposed destination | Treatment |
|---|---|---|---|
| Dashboard | Nearly every workflow | Overview | Keep only summary, alerts, latest items, and shortcuts |
| Dashboard manual actions | New-token/momentum scans and operations | Token Scanner | Move scanner controls/results/history; put non-scanner commands in Diagnostics |
| Dashboard positions | Legacy and engine positions | Trading → Positions | Merge presentation, preserve owner/source metadata |
| Dashboard strategy/preferences | Execution mode, entry mode, PAPER strategy | Settings & Diagnostics → Trading Preferences | Move forms; show read-only current mode on Overview |
| Opportunities | Qualified signals and confirmations | Opportunities | Preserve; simplify list/detail hierarchy and link to source scan/trade |
| Trade History | Legacy funded PAPER records | Trading → History | Preserve records and filters; extend presentation to engine projections when a safe read model exists |
| Account wallet cards | Wallet connection, balances, swaps, history | Wallets | Relocate UI; keep endpoint authorization and signatures unchanged |
| Account profile/password | Identity/security | Account menu → Profile & Security | Preserve separately |
| Telegram Bot | Personal bot linking | Settings & Diagnostics → Notifications | Preserve as a dedicated subpage or tab |
| Platform Settings | All privileged configuration | Settings & Diagnostics | Split into role-gated subsections |
| Admin dashboard fragments | Scanner, health, wallets, positions, activity | Corresponding product pages plus Diagnostics | Keep privileged data; avoid one-page fragment navigation |
| Ethereum eligibility | Reviewer workflow | Diagnostics/Operations → Ethereum Eligibility | Preserve explicit authorization and audit history |

### 3.2 Proposed primary navigation

1. **Overview**
   - mode and readiness;
   - portfolio/open-position snapshot;
   - latest scan and opportunities;
   - actionable alerts and recent relevant activity.

2. **Token Scanner**
   - Run Scan;
   - Current Scan;
   - Scan History;
   - Scan Detail and Candidate Diagnostics.

3. **Opportunities**
   - Qualified;
   - Pending review/confirmation;
   - Historical/ignored where current data supports it.

4. **Trading**
   - Positions;
   - Orders & Executions;
   - History;
   - explicit PAPER/LIVE labels without implying that LIVE AUTO is available.

5. **Wallets**
   - Connected Wallets;
   - Balances;
   - Transactions;
   - chain-specific connection and confirmation actions.

6. **Settings & Diagnostics**
   - Trading Preferences;
   - Strategy/Risk;
   - Notifications;
   - Profile & Security via account menu;
   - privileged Integrations, Engine Health, Queues, Audit, and Eligibility.

### 3.3 Navigation behavior

- Desktop: 248–280px sidebar with product mark, primary sections, compact status footer, and user menu.
- Tablet/mobile: fixed top bar with product name, one primary action, and an accessible drawer.
- Keep scanner and trading actions out of global navigation labels; navigation should take the user to a page, not mutate state.
- Hide unauthorized administrator/reviewer entries server-side as today.
- Preserve deep links and existing routes through redirects or secondary links only after an authorized, tested migration.

## 4. Scanner diagnostics assessment

### 4.1 Confirmed data flow

    PostgreSQL token_scan_executions
        aggregate state, attempt/lease, counts, provider failures, safe error
                    |
                    +-- token_discoveries
                    |   per-asset state, reason code, snapshots, opportunity link
                    |
                    v
    token.scan.completed.v1 / token.scan.failed.v1
        aggregate counts + provider codes + execution error only
                    |
                    v
    Laravel authenticated event inbox
                    |
                    v
    TradingEngineScanProjector
        trading_engine_scan_requests + system_activities.result_summary
                    |
                    v
    SystemActivityService safe presentation
                    |
                    +-- server-rendered scan-result-summary Blade
                    +-- /dashboard/activity JSON -> app.js renderer

Candidate-level rows stop at the TypeScript/PostgreSQL side. They do not enter the version-1 terminal event or a Laravel projection.

### 4.2 Answers to the required diagnostic questions

#### 1. Where are execution results stored?

- Authoritative engine execution: PostgreSQL token_scan_executions.
- Authoritative candidate evidence: PostgreSQL token_discoveries.
- Laravel command delivery/reconciliation: trading_engine_scan_requests.
- Laravel presentation summary: system_activities.result_summary JSON.
- Authenticated terminal event evidence: trading_engine_event_inbox/TradingEngineEvent.
- Legacy Laravel scans also use the older scan execution/history structures, which carry a richer export-oriented summary. They are a separate historical path.

#### 2. How do summaries reach the dashboard?

The engine publishes a version-1 terminal event through its outbox. Laravel authenticates and stores the event, validates identity against the durable scan request, projects aggregate counts/outcome/provider failures into the related SystemActivity, and the dashboard obtains the normalized result through SystemActivityService. Initial HTML uses the Blade partial; polling uses app.js.

#### 3. Which metrics are displayed?

The scan summary currently displays:

- discovered;
- examined;
- qualified;
- opportunities created;
- opportunities existing;
- downstream failures;
- skipped;
- candidate failures;
- exports acknowledged;
- every export-state count;
- provider warning codes/counts;
- downstream warning codes/counts; and
- last structured progress.

For engine-owned scans, the event actually supplies only discovered, examined, qualified, opportunities created, opportunities existing, skipped, failed, provider failures, outcome, and error code. The other displayed metrics are normalized to zero, not received from the engine event.

#### 4. Why are failed and skipped counts hard to find?

They are:

- nested inside System Activity rather than a Scanner page;
- inside a collapsed recent details element after completion;
- two of nine equally weighted tiny cells;
- not included in the zero-result summary sentence;
- visually subordinate to a green completed activity label; and
- not linked to candidate rows.

#### 5. Are candidate reasons stored and accessible?

They are stored in token_discoveries.reason_code, with per-candidate state and bounded snapshots. They are not accessible through the current Laravel dashboard or the version-1 terminal event. Access today is operational/database-level, not a user workflow.

#### 6. Are provider failure details available?

Stable provider failure codes are persisted in PostgreSQL, emitted as a bounded list, counted by Laravel, and displayed as raw code/count pairs. Raw provider bodies and secrets are correctly not exposed. The UI lacks a plain-language explanation, affected stage, timestamp, or relationship to candidate outcomes.

#### 7. Does the dashboard distinguish execution failure from candidate failure?

Only partially:

- execution failure maps the SystemActivity to failed;
- candidate failures contribute to succeeded_with_warnings in TypeScript;
- the result grid labels a separate Candidate failures count.

However, the warning outcome is not rendered and the parent label is completed. The overall presentation therefore does not make the distinction clear enough.

#### 8. Are all states handled accurately?

| State | Current handling | Assessment |
|---|---|---|
| Pending | Parent activity shows pending/waiting for queue | Present, but ownership wording can be wrong |
| Running | Parent activity shows running and polling elapsed time | Present; no stage/progress model beyond safe summary |
| Completed | Parent activity completed and result summary | Present |
| Completed with warnings | Stored as result_summary.outcome but parent becomes completed; outcome not rendered | Inaccurate/incomplete presentation |
| Failed | Parent activity failed with safe code | Present; candidate progress may still need clearer preservation |

### 4.3 Interpretation of the cited production scan

The only safe interpretation from the supplied evidence is:

- the execution reached a terminal non-failed state;
- the terminal outcome was succeeded_with_warnings;
- 20 candidates were discovered and all 20 were examined;
- the examined candidates partitioned into 12 skipped, 8 failed, and 0 qualified;
- no opportunity was created or reused;
- one PROVIDER_RATE_LIMITED incident was recorded.

The provider incident is execution-level evidence. The current aggregate event does not prove that it caused any particular one—or all eight—candidate failures. Candidate reason_code values must be consulted to establish those causes.

### 4.4 Recommended scanner result presentation

#### History row

- scanner type and chain;
- started/completed time and duration;
- status badge: Pending, Running, Completed, Completed with warnings, or Failed;
- 20 examined · 0 qualified · 0 opportunities;
- amber warning summary: 8 candidate failures · 1 provider incident;
- View details action.

#### Detail header

- plain-language outcome banner;
- scanner, chain, owner, request time, duration;
- retry/attempt information only in an operator disclosure;
- a safe execution error description when failed.

#### Candidate funnel

Discovered → Examined → Qualified → Opportunities, with Skipped and Failed as explicit branches from Examined. Counts must reconcile visibly.

For the cited result:

    20 discovered
      └─ 20 examined
          ├─ 0 qualified
          ├─ 12 skipped
          └─ 8 failed
      └─ 0 opportunities

#### Candidate table

Columns should be Asset, Provider, Outcome, Reason, Market/Security summary, Opportunity, and Evaluated at. It must be paginated, user-scoped, and based on bounded safe fields. Reason codes should map to concise copy while retaining the code in a disclosure for support.

#### Provider incidents

Show provider incidents separately from candidate disposition:

- “Market-data provider rate limited one request.”
- explain whether the scan continued and whether results may be incomplete;
- do not claim which candidates were affected without causal evidence.

#### Empty and error states

- true zero discovery: “No candidates were returned.”
- examined but no qualified: show the disposition breakdown, not a generic empty state;
- provider-degraded completion: preserve results and show an amber warning;
- execution failure: show safe failure, retry/recovery status if available, and no fabricated zero counts;
- candidate diagnostics unavailable: say “Candidate details are not projected for this historical scan.”

## 5. Proposed Overview layout

### 5.1 Desktop wireframe

    ┌──────────────────────────────────────────────────────────────────┐
    │ Overview                         PAPER · AUTO/CONFIRM · User menu │
    ├──────────────────────────────────────────────────────────────────┤
    │ Actionable alerts: wallet disconnected / scan degraded / risk   │
    ├───────────────────┬───────────────────┬──────────────────────────┤
    │ PAPER portfolio   │ Open positions    │ Wallet connections       │
    │ available/invested│ count + P/L       │ SOL / ETH status         │
    ├───────────────────────────────────────┬──────────────────────────┤
    │ Latest scan                           │ Qualified opportunities  │
    │ outcome, funnel, warnings, details    │ count + newest signals   │
    ├───────────────────────────────────────┴──────────────────────────┤
    │ Open positions: compact responsive table/cards                  │
    ├──────────────────────────────────────────────────────────────────┤
    │ Recent relevant activity                   View all in context   │
    └──────────────────────────────────────────────────────────────────┘

Engine, scanner, and background health should be a compact header/status item when normal and an alert when degraded. Detailed process/queue/webhook information belongs in Diagnostics.

### 5.2 Mobile order

1. mode and safety state;
2. actionable alerts;
3. wallet connection;
4. portfolio/open positions;
5. latest scan;
6. qualified opportunities;
7. recent activity.

Each card should expose one primary action and no more than three priority metrics before disclosure.

### 5.3 Metric availability

| Proposed metric | Available now? | Source/constraint |
|---|---|---|
| Current PAPER/LIVE request mode | Yes | User trading preference; LIVE safeguards must remain explicit |
| Entry mode | Yes | User preference |
| Engine readiness | Yes | Existing engine/integration health presentation |
| Scanner activity/status | Yes, aggregate | SystemActivity plus result_summary |
| Connected wallet state | Yes | Active verified Solana/Ethereum wallet records |
| Wallet balance | Yes, asynchronously | Existing authorized balance endpoints; provider failure must remain unavailable, not zero |
| Legacy PAPER available/invested | Yes | PaperWallet |
| Engine PAPER wallet projection | Yes | Engine wallet projection |
| Consolidated PAPER total | Not safely defined in one current read model | Must avoid double counting legacy and engine ownership |
| Open legacy positions | Yes | PaperPosition presentation |
| Open engine positions | Yes | Engine position projections |
| Unified open position count/P&L | Partially | Requires explicit aggregation and currency rules |
| Latest scan aggregate | Yes | system_activities.result_summary |
| Latest scan candidate details | No in Laravel | Requires versioned user-scoped read model |
| Qualified opportunity count/newest | Yes | OpportunitySummaryService/user-scoped query |
| Unified order/execution status | Partial | Distributed across opportunity attempts, wallet transactions, and engine projections |

No Overview metric should be hardcoded or inferred from feature flags alone. “Configured,” “reachable,” “ready,” and “actively processing” are distinct states.

## 6. Design system recommendations

### 6.1 Visual direction

Use a restrained trading-workstation aesthetic:

- base: ink/navy surfaces rather than pure black;
- neutral borders: slate with clear elevation;
- primary action: blue;
- positive/available: emerald;
- warning/degraded/manual attention: amber;
- destructive/failed/LIVE danger: red;
- informational/engine-owned evidence: cyan or violet, used sparingly.

Semantic colors must have text/icon equivalents and WCAG-compliant contrast. Ownership must not be encoded only by color.

### 6.2 Typography

Retain Instrument Sans unless product branding changes. Establish:

- page title: 30–36px, semibold;
- section title: 20–24px, semibold;
- card title: 15–18px, semibold;
- body: 14–16px;
- metadata: 12–13px, never the only location of critical state;
- financial figures: tabular numerals with consistent precision.

Use uppercase tracking only for short eyebrows/status labels, not descriptive copy.

### 6.3 Layout and spacing

- 8px spacing base; 4px for compact internal alignment.
- Page max width should reflect the surface: wide for tables, narrower for settings.
- Use 16px mobile card padding and 20–24px desktop card padding.
- Standardize 12px card radius and 8px control radius rather than mixing many values.
- Keep overview rows aligned to a 12-column desktop grid and a single mobile column.

### 6.4 Components

Create shared, semantic components rather than page-specific Tailwind strings:

- AppShell / ResponsiveSidebar / MobileHeader;
- PageHeader and AlertRail;
- MetricCard and PortfolioCard;
- StatusBadge with explicit state mapping;
- DataTable with responsive card fallback;
- EmptyState, ErrorState, LoadingState, and StaleState;
- FilterBar;
- Disclosure/DiagnosticPanel;
- ConfirmDialog;
- FinancialValue and AddressCopy;
- ScanOutcomeBanner, ScanFunnel, and CandidateReasonBadge.

Components should accept domain values and map them centrally; views should not invent status colors.

### 6.5 Status vocabulary

| Internal family | User label |
|---|---|
| pending / queued | Pending |
| processing / running | Running |
| succeeded | Completed |
| succeeded_with_warnings | Completed with warnings |
| failed | Failed |
| acceptance_unknown | Awaiting confirmation |
| retrying | Retrying |
| stale/unreachable | Status unavailable |
| PAPER | PAPER |
| LIVE confirm boundary | LIVE · Confirmation required |
| disabled LIVE path | LIVE unavailable |

Do not collapse “completed with warnings” into “completed.” Do not display internal state names without readable copy.

### 6.6 Buttons and forms

- One primary action per card/section.
- Secondary actions use neutral outline treatment.
- Destructive actions require explicit confirmation and describe scope.
- LIVE actions always include chain, wallet, token, amount, and confirmation boundary.
- Submit buttons show pending state and prevent accidental duplicate submission without implying server idempotency is optional.
- Validation messages remain adjacent to the field and are announced.

### 6.7 Financial presentation

- Fiat: currency symbol and two decimals unless market-cap compact notation is explicitly used.
- SOL/ETH: fixed maximum precision with trailing-zero policy applied consistently.
- Percent: sign plus two decimals for P/L; use “—” for unknown, never 0.
- Addresses: shortened visually with accessible full-value/copy control.
- Timestamps: user-local date/time plus relative time where useful.
- Preserve the source currency; do not add unlike assets.

### 6.8 Responsive behavior

- 320px minimum supported viewport without horizontal page scrolling.
- Tables become labeled cards or retain a deliberately scrollable region with an accessible cue.
- Sidebar becomes a focus-trapped drawer; Escape closes it; focus returns to trigger.
- Priority metrics remain visible; technical details collapse.
- Touch targets at least 44×44 CSS pixels.

### 6.9 Accessibility baseline

Target WCAG 2.2 AA:

- logical heading order and landmark labels;
- visible focus for every interactive element;
- keyboard-operable drawer, disclosure, tabs, filters, and dialogs;
- focus management for modal/dialog actions;
- aria-live limited to meaningful status changes, not every polling tick;
- text labels for status and chart values;
- contrast verified in normal, hover, focus, disabled, and error states;
- reduced-motion support;
- error summaries linked to invalid fields; and
- no action conveyed solely by color, position, or icon.

## 7. Implementation roadmap

Each phase is intentionally small and independently testable. None changes trading authority or financial behavior.

### Phase 2A — Presentation contract hardening

Goal: make current scanner output truthful before moving it.

- Define a source-aware scanner presentation DTO.
- Render outcome explicitly.
- Preserve succeeded_with_warnings.
- Treat absent engine export/downstream fields as unavailable.
- Correct Laravel/TypeScript ownership copy.
- Make server and polled renderers equivalent.

Tests:

- Laravel feature cases for succeeded, succeeded_with_warnings, and failed engine events;
- assertions that absent fields are not rendered as zero;
- frontend DOM tests for polled warning state and provider codes;
- historical legacy summary compatibility.

### Phase 2B — Shared shell and navigation

Goal: introduce the responsive information architecture without deleting pages.

- Build shared customer/admin shell primitives.
- Add Overview, Token Scanner, Opportunities, Trading, Wallets, and Settings/Diagnostics navigation.
- Preserve authorization-aware link visibility.
- Keep existing route names operational until callers and tests migrate.

Tests:

- role-based navigation;
- active states;
- keyboard/mobile drawer behavior;
- no unauthorized links;
- route compatibility.

### Phase 2C — Dedicated Token Scanner

Goal: separate scanner workflow from operational activity.

- Add scanner index/history/detail routes and controllers.
- Reuse durable scan request/activity data for aggregate history.
- Add filters and truthful empty/error/loading states.
- Keep current dashboard action route as a compatibility path initially.
- Add a versioned, bounded, user-scoped candidate diagnostic contract.

Tests:

- user isolation;
- pagination and bounds;
- warning/failure rendering;
- candidate reason-code mapping;
- no raw provider payload/secrets;
- one request never invokes both scanner owners.

### Phase 2D — Clean Overview

Goal: make the landing page decision-oriented.

- Replace configuration forms with read-only status and deep links.
- Add alert rail, portfolio/open-position snapshot, latest scan, latest opportunities, and recent relevant activity.
- Keep administrator diagnostics off the customer surface.
- Explicitly avoid double-counting engine and legacy portfolios.

Tests:

- role and user scoping;
- empty and degraded states;
- mixed legacy/engine fixtures;
- no trading side effects from reads.

### Phase 2E — Trading and Wallets

Goal: align workflows with user expectations.

- Move wallet cards from Account to Wallets.
- Build Trading tabs around existing records/projections.
- Preserve historical Laravel views and actions.
- Add source/evidence disclosures rather than separate top-level portfolios.
- Keep all confirmation, signature, freshness, kill-switch, and ownership checks in their existing services/controllers.

Tests:

- wallet ownership/authorization regression suite;
- legacy and engine record visibility;
- no cross-user access;
- manual close and LIVE confirmation safety;
- transaction history bounds.

### Phase 2F — Settings and Diagnostics

Goal: separate preference, secret, risk, and operational concerns.

- Split user preferences, notifications, profile/security, privileged integrations, risk, engine health, queues, and audit.
- Relocate non-scanner manual commands to Diagnostics/Operations.
- Preserve current gates and secret masking.

Tests:

- ability/role checks;
- masked-secret behavior;
- safe health failures;
- audit persistence;
- no secrets in HTML or logs.

### Phase 2G — Accessibility, responsive, and regression pass

- automated accessibility scan on primary states;
- keyboard-only workflow pass;
- viewport matrix;
- color/contrast review;
- loading/stale/offline state review;
- screenshot/visual-regression baselines if the project adopts them;
- full Laravel, frontend, TypeScript contract, and integration suites.

## 8. Risk assessment and invariants

### 8.1 Contracts and ownership that must not change

- Laravel remains the authentication, account, presentation, and control plane.
- TypeScript remains authoritative for new engine-owned scanner and PAPER trading state at the configured cutover boundary.
- Historical Laravel PAPER records remain readable and retain their lifecycle.
- Signed/authenticated trading-engine event ingestion, replay protection, payload hashes, and identity checks remain unchanged unless replaced by a versioned contract.
- Scanner submission remains durable and idempotent; ambiguous acceptance is not converted to rejection.
- Candidate diagnostics must be user-scoped by authoritative control-plane identity.
- PAPER wallets, ledger evidence, positions, exits, and settlement are never recomputed in the browser.
- LIVE remains gated, confirm-only where implemented, and disabled wherever currently disabled.
- Wallet connection, challenge, signature, ownership, rate-limit, and transaction-integrity checks remain server-authoritative.
- No redesign view may trigger a scan, entry, exit, swap, or retry on page load.

### 8.2 Principal implementation risks

| Risk | Consequence | Control |
|---|---|---|
| Consolidating legacy and engine portfolio values | Double counting or mixing currencies/authority | Define aggregation rules; show source; test mixed fixtures |
| Projecting candidate diagnostics | Data growth or cross-user exposure | Bounded pagination, explicit user identity, safe fields, retention policy |
| Renaming states | Operators lose traceability | Maintain internal code in diagnostic disclosure and central mapping |
| New navigation/routes | Broken bookmarks/forms/tests | Additive routes, named-route compatibility, phased redirects |
| Reusing polling for richer pages | Excess load/stale UI | Paginated reads, ETags/timestamps, visibility pause, request bounds |
| Moving wallet UI | Authorization accidentally shifts client-side | Reuse existing endpoints/controllers; move presentation only |
| Combining PAPER/LIVE trading surfaces | LIVE appears enabled | Persistent mode labels, disabled-state copy, existing gates unchanged |
| Hiding diagnostics | Support evidence becomes harder to reach | Role-gated Diagnostics with safe IDs/codes, not deletion |
| Visual component extraction | Subtle action/form changes | Snapshot behavior first; narrow feature tests per component |

### 8.3 Deployment safety for future phases

Future UI releases should:

- deploy with scanner/trading feature flags unchanged;
- require no production data rewrite merely to introduce navigation;
- keep old queue workers and new serialized jobs compatible where relevant;
- build frontend assets from the same reviewed commit;
- verify authorized routes, event projection, wallet ownership, and safe LIVE-off state;
- preserve additive migrations and evidence on rollback; and
- never use a UI rollout as authorization for scanner or trading activation.

## 9. Open questions before Phase 2

1. Should Overview present one combined PAPER portfolio total, or show legacy and engine balances separately until currency/aggregation semantics are formally approved?
2. Which candidate fields may be projected to Laravel: reason code only, bounded snapshots, or a purpose-built explanation generated by TypeScript?
3. What retention period and pagination limit should scanner candidate diagnostics use?
4. Should scan history include both legacy Laravel and TypeScript scans in one list with an owner filter, or use a migration boundary marker?
5. Is Ethereum “relevant token” scanning intended for the same customer Scanner page now, or should it remain an operator-only path until parity/cutover is approved?
6. Which operational health states are useful to ordinary customers beyond “available,” “degraded,” and “unavailable”?
7. Should Wallets include swap/order controls, or should it show connection/balance/history and route execution back through Opportunities/Trading?
8. What is the approved definition of a unified order/execution across browser-wallet LIVE attempts, legacy PAPER records, and engine-owned PAPER evidence?
9. Are administrator and customer roles ever used in the same session often enough to require an in-product role/context switch?
10. Which existing deep links and external Telegram links depend on Dashboard fragment IDs?
11. Should personal Telegram configuration remain a standalone page or become a Notifications tab?
12. Is a browser-driven accessibility/visual baseline available for Phase 2, or should one be introduced?

## Final assessment

The proposed navigation is compatible with the application’s real capabilities, but the first implementation step should be scanner presentation correctness, not a cosmetic reskin. Current storage and contracts preserve the evidence needed to explain the cited scan, yet the UI hides the warning outcome, buries the disposition counts, and cannot show candidate reasons.

Phase 2 should proceed additively:

1. harden the scanner presentation contract;
2. establish the shared responsive shell;
3. introduce a dedicated scanner history/detail workflow;
4. simplify Overview;
5. relocate Trading, Wallets, and Settings/Diagnostics without changing authority; and
6. verify accessibility, mobile behavior, and all trading safety regressions.

This audit does not authorize implementation, migration, production inspection, scanner execution, LIVE activation, deployment, or configuration changes.
