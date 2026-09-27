# TypeScript Trading Engine Migration Audit

**Audit date:** 2026-09-27
**Repository:** Meme Scanner Laravel application
**Purpose:** Define an incremental migration from the current Laravel trading implementation to a hybrid Laravel control plane plus Node.js/TypeScript trading engine.

This is an implementation audit and target-state proposal, not an assertion that every named capability is production-ready. The classifications below are based on the repository as it exists at the audit date. No database mutation, trade execution, deployment, or runtime behavior change is part of this document.

## 1. Executive summary

The repository already contains substantially more than a scanner prototype. It has user-scoped PAPER portfolios, chain-aware strategy settings, opportunity workflows, browser-wallet connections, manual Solana and Ethereum swap preparation/submission, receipt reconciliation, and unusually careful Ethereum inventory-accounting evidence. Its strongest areas are PAPER state transitions and the safeguards around uncertain wallet broadcasts.

It is not yet a complete multi-chain live trading engine:

- The generic LIVE executor in `app/Services/Trading/LiveTradeExecutor.php` always refuses execution. `app/Services/TradeExecutionManager.php` therefore cannot provide generic LIVE behavior.
- Solana LIVE is a manual wallet swap path (`SolanaSwapQuoteController`, `SolanaSwapOrderController`, and `SolanaSwapExecuteController`) and is not connected to scanner opportunities, `LivePosition`, sell/close logic, or a live portfolio.
- Ethereum has both a manual swap path and a safer opportunity-linked confirm-first path. The latter creates `LivePosition` only after a confirmed receipt and then performs inventory accounting. It still has no live sell/close state machine, ongoing valuation, stop-loss/protection execution, or customer-facing live portfolio/history.
- PAPER supports stop loss and two protection floors, but exits the full position. There are no partial take-profit exits and no independently configurable trailing-stop algorithm. The historical `trailing_stop_hit` label is reused for protected-floor exits.
- Token discovery and qualification exist for Solana and Ethereum. BNB Chain and Base do not appear in `app/Chain.php`, adapters, routes, migrations, or tests.
- Mobile wallet interoperability, unattended LIVE authorization, copy trading, and subscriptions are absent. `app/Services/TokenScannerService.php` is empty; actual scanning lives in commands and chain-specific services.

The following architecture decisions are settled:

1. Laravel remains the control plane on HostGator and continues using its existing SQLite database. No whole-application PostgreSQL migration is required.
2. The TypeScript engine lives at `trading-engine/` in this repository, owns a separate PostgreSQL database, and is independently buildable/deployable from Laravel.
3. The engine initially runs only on the developer's MacBook for bounded development and tests. The production Laravel site must not depend on the laptop being online.
4. Hostinger Business Web Hosting is a candidate deployment target for the TypeScript HTTP API. The account already has an unrelated Solana/Ethereum validator, `validator.tandafrica.com`, running as a Node.js 22.x application and reports one of five Node.js application slots in use. That proves Node.js web application availability for this account, not PostgreSQL, Redis, background-worker, or scheduler capability. The validator is outside this migration and must remain untouched.
5. Laravel and the engine communicate only through authenticated, versioned APIs and replay-protected events/webhooks. They do not read or write each other's database.
6. The engine is the sole writer of engine-owned market observations, opportunities, PAPER ledgers, orders, fills, transaction attempts, LIVE positions, and trading audit events.
7. SIGNAL, CONFIRM, and AUTO are first-class entry modes across PAPER and LIVE. PAPER AUTO is delivered before LIVE AUTO. A stored LIVE/AUTO preference never enables unattended execution by itself.
8. Desktop and mobile wallets are supported. Mobile flows cannot depend on desktop extensions, and wallet connection authentication remains separate from transaction authorization.
9. Existing PAPER data is not migrated. The engine starts with fresh test wallets, balances, and positions; Laravel's SQLite data and tests remain untouched as references.
10. No server-side private-key custody or delegated execution is implemented in the initial phases. Existing confirm-first intent binding, signing claims, rejection handling, and receipt reconciliation remain the safety baseline.

The recommended migration remains incremental: establish a local-only engine foundation, port scanning in read-only shadow mode, implement fresh PAPER portfolios including PAPER AUTO, migrate confirm-first LIVE on test networks, then select and audit a bounded authorization model before implementing unattended LIVE AUTO. Hostinger Business may later host the API in a new application slot, with external PostgreSQL and Redis, but continuous scanning, automatic monitoring, reconciliation, notifications, or unattended execution remain unavailable until independently deployable worker and scheduler processes are proven reliable on Hostinger or another affordable non-VPS service. No VPS purchase is authorized.

The largest migration risk is accidentally creating two writers or treating a quote, prepared transaction, wallet return, browser broadcast, or queue acknowledgement as a fill. The cutover must maintain one authoritative writer per aggregate and retain the repository's existing rule that only authoritative chain evidence can confirm LIVE execution.

## 2. Existing functionality inventory

### 2.1 Capability status

| Capability | Status | Repository evidence and audit conclusion |
|---|---|---|
| Solana new-token scanning | Implemented | `app/Console/Commands/ScanNewTokens.php` uses Birdeye discovery/overview, liquidity/market-cap/holder filters, GoPlus, scoring, DexScreener context, persistence, and opportunity creation. |
| Solana momentum scanning | Implemented | `app/Console/Commands/ScanMomentumTokens.php` uses DexScreener profiles, Solana RPC analysis, Pump.fun/developer and holder checks, GoPlus, and Birdeye budget controls. |
| Solana follow-up monitoring | Partial | `app/Console/Commands/FollowUpTokens.php` refreshes existing Solana scans and sends status alerts; it does not implement a generic cross-chain follow-up engine or create a new trade lifecycle. |
| Ethereum discovery/qualification | Partial/experimental | `app/Services/EthereumScannerService.php` uses GeckoTerminal discovery and DexScreener validation. Qualification is simpler than Solana. It records security as unavailable and does not use the existing `GoPlusEthereumService` during discovery qualification. |
| Generic scanner service | Absent stub | `app/Services/TokenScannerService.php` is empty. There is no central scanner contract implemented there. |
| Opportunity workflow | Implemented for PAPER; partial for LIVE | `TradeOpportunityService`, `EntryPolicy`, `OpportunityActionService`, opportunity controllers, models, and event records support SIGNAL, CONFIRM, AUTO, ignore, approve, and per-user snapshots. Generic LIVE remains blocked; Ethereum has a separate confirm-first path. |
| PAPER entry and wallet accounting | Implemented | `PaperTradeEntryService`, `PaperWalletService`, and `PaperTradeExecutor` use transactions/locks, debit virtual balances, snapshot strategy, and block an existing funded open position for the same chain/token. |
| PAPER tracking and exits | Implemented with simulation limits | `TrackPaperPositions`, `TrackPaperPositionsFast`, `PaperMarketObservation`, and `PaperTradeExitService` provide batched observations, stop-loss/protection exits, wallet settlement, snapshots, locks, and health diagnostics. Fills use observed market marks, not executable quote/depth simulation. |
| PAPER stop loss | Implemented | Stored per-position strategy snapshot and applied before protection-floor logic. Tests cover actual observed fills and idempotent wallet credit. |
| PAPER take profit | Partial | Two configurable protection milestones arm/upgrade a floor. There are no partial exits; the later floor breach closes the entire position. |
| PAPER trailing stop | Absent as an independent feature | There is no configurable trailing percentage/distance algorithm. `trailing_stop_hit` is legacy terminology used by protected-floor behavior. |
| Manual PAPER close | Implemented | `ClosePaperTradeController` and `PaperTradeExitService` require a fresh validated observation, recheck ownership/state under lock, and settle once. |
| User strategy settings | Implemented for current PAPER model | `PaperStrategySettingController`, `PaperStrategyService`, and `PaperStrategySetting` provide per-user settings and new-position snapshots. |
| PAPER dashboard/history | Implemented | `PaperTradingDashboardController`, `PaperTradeHistoryService`, `TradeHistoryController`, `resources/views/dashboard.blade.php`, and `resources/views/trades/index.blade.php` are PAPER-focused. |
| Generic LIVE execution | Deliberately disabled | `app/Services/Trading/LiveTradeExecutor.php` throws that live execution is not enabled. `TradeExecutionArchitectureTest` verifies this server-side block. |
| Solana LIVE buy | Partial/manual | Jupiter quote/order/execute services, encrypted prepared attempts, local/remote intent validation, browser signing, submission, expiry, and RPC reconciliation exist. The flow is not opportunity-linked and does not create a live inventory position. |
| Solana LIVE sell/position management | Absent | No live inventory ledger, close/sell orchestration, strategy exits, or live history/portfolio is produced from Solana swaps. |
| Ethereum manual LIVE buy | Partial/manual | `EthereumSwapController`, `EthereumSwapPreparationService`, `ZeroXSwapService`, browser broadcast, attempt persistence, and receipt reconciliation exist. A manual swap without an opportunity does not become a `LivePosition`. |
| Ethereum opportunity LIVE buy | Partial but strongly guarded | Reservation, fresh market/security revalidation, one-time signing claim, exact transaction binding, receipt reconciliation, `LivePositionService`, and inventory accounting exist. This is the closest current path to an engine-grade workflow. |
| Ethereum LIVE sell/position management | Absent | No sell/close order workflow, live strategy exit, P&L marking, or live customer portfolio/history exists. |
| Receipt reconciliation | Implemented for submitted manual attempts | `ReconcileSubmittedSolanaSwaps`, `ReconcileSubmittedEthereumSwaps`, chain RPC services, and Ethereum receipt reconciliation avoid treating preparation/submission as success. |
| Ethereum acquired-inventory accounting | Implemented but operationally gated | `EthereumInventoryAccounting` and related eligibility/evidence/review services require reviewed token bytecode eligibility, finality, receipt/transfer evidence, and support reconsideration. |
| Wallet connection proof | Implemented | Solana and Ethereum connection services use expiring one-use challenges. Proof binds domain, user, chain, wallet, nonce, and timestamps and explicitly is not transaction authorization. |
| Browser wallet signing | Implemented | `resources/js/solana-wallet.js`, `ethereum-wallet.js`, and `ethereum-opportunity.js` keep transaction signing/broadcast in the wallet/browser. |
| Mobile wallet standards | Absent/not generalized | No WalletConnect/Reown, EIP-6963, Solana Mobile Wallet Adapter, or generalized mobile return/recovery integration was found. Current flows are browser JavaScript and wallet-specific behavior. |
| Unattended LIVE authorization | Absent | No delegated smart-account/session permission, on-chain strategy vault, Solana delegate/program authority, or approved server signer exists. Browser wallet confirmation cannot provide LIVE AUTO. |
| Telegram control/notifications | Implemented | Telegram bot linking, webhook routing, menus, callback/command handlers, queue job, and per-user notification routing exist. LIVE approval is intentionally web-only because Telegram approval does not supply the required execution input. |
| Multi-user isolation | Implemented in major current flows | User ownership is present across preferences, opportunities, PAPER positions/wallets, wallets, attempts, and tests such as `MultiUserTradingIsolationTest`. |
| Multi-chain architecture | Partial | Solana and Ethereum are explicit enum cases with a small adapter layer. Many services, commands, schemas, and UI paths still branch directly by chain or retain SOL-named columns. |
| BNB Chain | Absent | No enum value, EVM network configuration, adapter, provider policy, routes, migrations, or tests. |
| Base | Absent | No enum value, EVM network configuration, adapter, provider policy, routes, migrations, or tests. |
| Copy trading | Absent | No leader/follower, allocation, delay, slippage, consent, or copied-order domain was found. |
| Subscription/billing | Absent | No plan, entitlement, billing-provider, invoice, or subscription model was found. |

### 2.2 Discovery, enrichment, and qualification

The application has two command entry points for discovery:

- `tokens:scan` in `app/Console/Commands/ScanNewTokens.php`, accepting Solana or Ethereum and an optional user.
- The momentum workflow in `app/Console/Commands/ScanMomentumTokens.php`, also branching by chain.

The Solana path is the more mature scanner. Its provider set includes:

- `BirdeyeService` for listings and token overview.
- `DexScreenerService` for market/pair context and momentum discovery.
- `GoPlusService` for token security.
- `SolanaService` and token metadata services for RPC-derived holder/developer/token context.
- `NewTokenClassificationService` for classification.

The Ethereum path delegates to `EthereumScannerService`, using `GeckoTerminalService` for newly created pools and `DexScreenerService` for market confirmation. `EthereumQualificationEvaluator` applies new-token and momentum thresholds. An important gap is that `GoPlusEthereumService` exists and is used during the later LIVE revalidation path, but is not wired into scanner qualification. Consequently, an Ethereum opportunity can be qualified while its stored discovery security status is unavailable. PAPER may be acceptable under an explicitly documented simulation policy; LIVE must continue to fail closed and revalidate.

`TokenScan` and `TokenScanHistory` retain discovery and follow-up data. `TradeOpportunityService` turns qualifying data into user-scoped `TradeOpportunity` records, taking a preference snapshot and delegating the decision to `EntryPolicy`.

### 2.3 Strategy, opportunity, and execution behavior

`ExecutionMode` contains PAPER and LIVE. `EntryMode` contains SIGNAL, CONFIRM, and AUTO. `EntryPolicy` behaves as follows:

- Kill switch or disabled trading: record an ignored opportunity.
- SIGNAL: retain a qualified opportunity without execution.
- CONFIRM: retain a pending-confirmation opportunity.
- AUTO: execute through `TradeExecutionManager`.

`UserTradingPreferenceService` constrains LIVE to CONFIRM. Generic LIVE AUTO is also prevented by `LiveTradeExecutor`. PAPER AUTO uses `PaperTradeExecutor` and `PaperTradeEntryService`.

That is the current implementation, not the final product boundary. The target model retains SIGNAL, CONFIRM, and AUTO for both PAPER and LIVE, with separate capability gates. PAPER AUTO is implemented and soaked before LIVE AUTO; LIVE AUTO remains disabled until a separately approved unattended-authorization design exists.

The boolean `trading_enabled` is persisted and enforced, but no customer-facing route/controller dedicated to toggling it was found. That control should be made explicit before the engine can rely on it as a user kill switch.

`OpportunityActionService` handles approval and ignore actions. PAPER approval enters a PAPER position. Ethereum LIVE approval takes a special reservation/preparation path and requires explicit amount and slippage values, which is why it cannot be approved through the current Telegram callback.

### 2.4 PAPER portfolio behavior

The PAPER implementation is user- and chain-scoped:

- `PaperWallet` holds virtual balances per user and chain.
- `PaperPosition` identifies the chain/token position and stores an entry and strategy snapshot.
- `PaperPositionSnapshot` records periodic and terminal observations.
- `PaperStrategySetting` stores current per-user settings; existing positions retain their snapshot.

`TrackPaperPositions` batches positions by chain and applies the following full-position state machine:

1. Obtain an eligible current observation.
2. Apply stop loss first.
3. At protection level 1, arm a protected floor without selling.
4. At protection level 2, upgrade the floor without selling.
5. If a later observation reaches/breaches the active floor, close the entire position.

There is no partial inventory, order/fill model, fee model, gas model, depth-aware slippage, or latency model. `PaperMarketObservation` validates identity, freshness, storage ranges, and liquidity; some supply discrepancy/severe decline flags are diagnostic rather than blocking. This means PAPER performance must be labeled simulated and must not be presented as expected executable performance.

`TrackPaperPositionsFast` runs a bounded long-lived loop with a process lock/heartbeat, while `routes/console.php` schedules the normal tracker every ten seconds as fallback. By default the tracker uses file-backed cache/locks. This supports one host but does not provide a safe distributed lease across multiple engine instances.

### 2.5 Wallet and LIVE transaction flows

`ConnectedWallet` permits one connected wallet per user/chain and currently has a globally unique address hash. Global address uniqueness can be too strong for EVM networks because the same externally owned account legitimately exists on Ethereum, BNB Chain, and Base. Target uniqueness should be scoped by user, network, and canonical address, with a separate policy for whether an account may be linked to multiple users.

The connection challenge is short-lived and single-use. Solana signatures are verified locally. Ethereum connection signatures use `RemoteEthereumSignatureValidator`, whose endpoint is configured under the historically named `services.solana_transaction_validator` namespace. Rename that configuration during migration to avoid hiding a trust boundary.

Solana manual LIVE flow:

1. `SolanaSwapQuoteController` asks `JupiterSwapQuoteService` for a quote and applies `SolanaQuoteLimitService`/balance controls.
2. `SolanaSwapOrderController` asks `JupiterSwapOrderService` for a versioned transaction, then uses `RemoteSolanaTransactionValidator` to verify the expected wallet, required signer, fee payer, and unsigned intent.
3. The server stores an encrypted prepared transaction, message hash, recent blockhash, and short expiry in `SolanaSwapAttempt`.
4. `resources/js/solana-wallet.js` signs in the wallet.
5. `SolanaSwapExecuteController` verifies that the signed message is unchanged and that the expected signature is present, persists the signature, and submits through `JupiterSwapExecutionService`.
6. `ReconcileSubmittedSolanaSwaps` obtains authoritative RPC status, slot, and fee, including blockhash-expiry handling.

Ethereum manual LIVE flow:

1. `EthereumSwapPreparationService` and `ZeroXSwapService` build a native-ETH-to-token transaction and apply amount/slippage/balance limits.
2. The attempt stores an encrypted exact transaction payload.
3. `resources/js/ethereum-wallet.js` broadcasts with `eth_sendTransaction` and reports the hash. Local storage supports recovery without automatically sending twice.
4. `EthereumSwapController` fetches the transaction by hash and binds hash, sender, recipient, value, calldata, and chain ID to the prepared intent.
5. `ReconcileSubmittedEthereumSwaps` and `EthereumReceiptReconciliationService` decide confirmed or failed from the receipt.

The Ethereum opportunity flow adds a reservation lease, fresh market/security checks, quote publication, one-time signing claim, claim arm/release/rejection states, opportunity linkage, and a confirmed-receipt-only `LivePosition`. `EthereumInventoryAccounting` then independently establishes acquired inventory from final chain evidence. These safety properties should be generalized rather than replaced.

### 2.6 Scheduling, queueing, and operational model

`routes/console.php` schedules:

- Operational heartbeat each minute.
- Ethereum inventory reconciliation each minute.
- Expiry and receipt reconciliation for prepared/submitted Ethereum and Solana attempts each minute.
- PAPER tracking every ten seconds with overlap prevention and activity hooks.

It does **not** schedule new-token or momentum scans. Those are one-shot commands dispatched manually through dashboard jobs or CLI. `RunDashboardCommand` has a long timeout and one attempt. `ProcessTelegramUpdate` is queued separately with retries.

The repository defaults to Laravel's database queue and database/cache configuration, with deployment guidance in `docs/cpanel-background-processes.md` for cron-driven scheduling, bounded queue drains, and the fast PAPER tracker. This is appropriate for the current single-application footprint but is not a horizontally scalable event-processing topology.

### 2.7 Database and model inventory

Current trading data is spread across these groups:

- Discovery: `token_scans`, `token_scan_histories`.
- Decisions: `trade_opportunities`, `trade_opportunity_events`.
- PAPER: `paper_wallets`, `paper_positions`, `paper_position_snapshots`, `paper_strategy_settings`.
- Preferences/control: `user_trading_preferences`, `application_settings`, `setting_audits`.
- Wallet identity: `connected_wallets`, `wallet_connection_challenges`.
- Execution attempts: `solana_swap_attempts`, `ethereum_swap_attempts`.
- LIVE inventory: `live_positions`.
- Ethereum accounting: eligibility, evidence, review-head, and reconsideration tables introduced by the 2026-09-22 and 2026-09-23 migrations.
- Operations/integration: `system_activities`, Telegram tables, Laravel jobs/cache tables.

Several early PAPER columns retain SOL-specific names despite later chain support. Important provider provenance and transition context is often JSON. There are no explicit engine command-inbox, transactional-outbox, consumer-inbox, immutable ledger-entry, order, or fill tables.

### 2.8 Existing test coverage

The current PHPUnit suite is a valuable migration oracle, especially:

- `tests/Feature/PaperTrackerStrategyTest.php`: stop loss, protection floors, chain-separated accounting, provider failure, snapshots, batching, and idempotence.
- `tests/Feature/PaperTrackerReliabilityTest.php`: stale/unverified observations, lock loss, cooldown/budget behavior, races, diagnostics, and current-observation requirements.
- `tests/Feature/ClosePaperTradeControllerTest.php`: ownership recheck, provider validation, stale/wrong-token/low-liquidity rejection, and exactly-once wallet settlement.
- `tests/Feature/TradeExecutionArchitectureTest.php`: SIGNAL/CONFIRM/AUTO behavior, PAPER execution, kill switch, and generic LIVE refusal.
- `tests/Feature/EthereumOpportunityPreparationTest.php` and `EthereumOpportunityExecutionTest.php`: leases, exact handoff, expiry, authorization races, one-time signing claims, and confirmed-receipt success.
- `tests/Feature/SolanaSwapOrderTest.php`, `SolanaSwapExecutionTest.php`, `ReconcileSubmittedSolanaSwapsTest.php`, `EthereumSwapTest.php`, and `ReconcileSubmittedEthereumSwapsTest.php`: wallet attempt preparation/submission/reconciliation.
- `tests/Feature/EthereumInventoryAccountingTest.php` and related eligibility/review tests: evidence and operator review behavior.
- `tests/Feature/MultiChainTradingTest.php` and `MultiUserTradingIsolationTest.php`: current chain and tenant boundaries.
- Unit tests for 0x, Jupiter, transaction validation, chain RPC services, metadata, amount formatting, and lock retries.
- Browser-side tests in `resources/js/*.test.js` and `tests/js/solana-transaction-validator.test.mjs`.

These tests should be translated into contract vectors and TypeScript characterization tests before the matching PHP code is retired. PHP UI/auth/BFF tests should remain in Laravel.

## 3. Laravel/TypeScript responsibility matrix

The boundary should be organized by authority, not programming language convenience. Every mutable aggregate has one authoritative writer.

The three ownership classifications are:

- **Remains in Laravel:** customer/control-plane functionality for which Laravel is the system of record.
- **Moves to TypeScript:** trading-engine functionality for which the engine becomes the only writer.
- **Integration contract:** workflows that start in Laravel or the browser but require an authorized, versioned engine command/query/event. A contract does not imply shared database writes.

| Concern | Laravel control plane | TypeScript trading engine | Boundary rule |
|---|---|---|---|
| User identity, sessions, email verification, password reset | Own/write | Read claims only | Engine never reads Laravel session storage directly. |
| Future plans, billing, subscriptions, entitlements | Own/write | Consume versioned entitlement snapshot | Subscription code is not currently present; add it only in Laravel. |
| Onboarding and account UX | Own/write | Expose status needed by UI | Laravel composes UI-facing progress. |
| Admin configuration UI and audits | Own/write configuration intent | Validate/activate trading config version | Secrets belong in a secret manager/environment, not ordinary settings records. |
| User trading preferences and strategy editing | Own user-facing intent | Own immutable execution snapshot and effective policy | Each command records preference/config versions used. |
| User kill switch request | Own UI/API authorization | Own enforced trading halt state | Engine acknowledgement must be observable; a Laravel flag alone is insufficient. |
| Wallet connection UX and proof | Own initially across desktop/mobile | Consume immutable wallet account ID; may later own chain-proof verification | Connection proof never authorizes a transaction. |
| Interactive wallet signing | Serve desktop/mobile transport, return, and recovery UI | Construct intent and verify exact signed/broadcast transaction | Extensions, QR sessions, MWA, or deep links remain per-transaction authorization; neither server stores private keys. |
| Unattended LIVE authorization | Expose consent/revocation UX after approval | Own scoped authorization evaluation/execution | Not implemented initially; requires a separate chain-specific ADR and capability gate. |
| Market discovery and enrichment | Display/query only | Own/write | Providers are called by engine workers after scanner cutover. |
| Token security assessment | Display/query only | Own/write | LIVE fails closed when required checks are missing/stale. |
| Opportunity qualification/state | Display and authorized user commands | Own/write | Laravel must not update opportunity status tables directly. |
| PAPER order, fill, ledger, position, valuation | Display/query only | Own/write | No dual-write period for balances or positions. |
| LIVE intent, quote, attempt, submission, receipt | Proxy/display only | Own/write | Engine state plus chain evidence is authoritative. |
| LIVE position/inventory accounting | Display/query and admin review UI | Own/write evidence/state | Review decisions are signed commands; audit remains append-only. |
| Risk checks and limits | Expose configuration UI | Evaluate and enforce | The engine rechecks at reservation and before publishing signing material. |
| Portfolio/history read models | Render | Produce projection | Laravel may cache but cannot invent/repair financial state. |
| Telegram inbound auth/routing | Own | Receive authorized commands via API | Telegram payloads never reach engine as trusted user identity. |
| Customer notifications | Own delivery/preferences/templates | Emit domain events | Notification failure cannot roll back a committed trade state. |
| Operational scheduler/work queues | Schedule only control-plane work | Own trading schedules and workers | Laravel scheduler stops dispatching a workflow immediately after its engine cutover. |
| Audit/observability | Control-plane audit | Trading/event audit | Correlation and trace IDs span both systems. |

Recommended browser request path:

```text
Desktop or mobile wallet -> Laravel session + CSRF -> Laravel BFF
Laravel BFF -> short-lived signed internal command -> TypeScript API
TypeScript API -> idempotent command + database transaction
TypeScript API -> prepared signing payload -> Laravel BFF -> browser wallet
Browser reports signed transaction/hash -> Laravel BFF -> TypeScript API
TypeScript worker -> RPC receipt reconciliation -> outbox event
Laravel consumer -> UI projection/notification
```

Keeping the engine private avoids duplicating customer authentication, CSRF policy, rate limits, and account authorization. If direct browser-to-engine requests are later needed, Laravel must mint a short-lived, audience-restricted token with `sub`, tenant/user ID, wallet ID, operation scope, `exp`, and one-use `jti`; the engine must never trust a plain `X-User-Id` header.

## 4. Proposed TypeScript structure

### 4.1 Technology choices

Recommended baseline as of the audit date:

| Layer | Choice | Reason |
|---|---|---|
| Runtime | Node.js 24 LTS, pinned to an exact patched version/image | Node 24 is the current LTS line and supports local macOS development plus a later Linux deployment. See the official [Node.js release schedule](https://nodejs.org/en/about/previous-releases). |
| Language | TypeScript 6.x, strict ESM | Stable tooling during the TypeScript 7 transition. Re-evaluate TypeScript 7 after the selected lint/schema/test stack supports it. See the official [TypeScript 7 transition notes](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/). |
| HTTP API | Fastify with TypeBox/JSON Schema request and response contracts | Fastify supports schema validation/serialization and TypeScript type providers. Schemas are trusted application code, never user-provided. See [Fastify validation](https://fastify.dev/docs/latest/Reference/Validation-and-Serialization/). |
| Engine database | Dedicated PostgreSQL; Kysely plus `pg` | The engine needs transactions, row locks, constraints, advisory locks, JSONB, and fixed-precision numeric types. Kysely stays close to explicit SQL and lock-sensitive behavior. Laravel remains on SQLite. See [Kysely getting started](https://kysely.dev/docs/getting-started). |
| Queue/schedules | Redis-backed BullMQ; external Redis in production | Supports delayed/repeatable jobs and workers. Worst-case delivery is at-least-once, so PostgreSQL idempotency remains mandatory. Redis runs locally in Docker for development and may be an external managed service in production. Do not assume HostGator or Hostinger Business provides installable/local Redis. See [BullMQ semantics](https://docs.bullmq.io/) and [idempotent jobs](https://docs.bullmq.io/patterns/idempotent-jobs). |
| Runtime schemas | TypeBox/JSON Schema for transport; explicit domain constructors for invariants | One schema can drive Fastify validation, OpenAPI, and Laravel/client contract tests. Transport validation is not domain validation. |
| Logging | Pino structured JSON with secret redaction | Low-overhead machine-queryable logs with stable correlation fields. |
| Telemetry | OpenTelemetry traces and metrics; local console/collector first, OTLP later | Preserves the same instrumentation when the engine moves from the MacBook to always-on hosting. See [OpenTelemetry JS](https://opentelemetry.io/docs/languages/js/). |
| Tests | Vitest, Testcontainers PostgreSQL/Redis, provider fixtures, HTTP/event contract tests, property-based tests where useful | Real PostgreSQL/Redis integration tests are required for locks, constraints, outbox claims, and duplicate delivery. |
| Packaging | Self-contained `pnpm` workspace rooted at `trading-engine/` with its own lockfile | Keeps one Git repository while allowing the engine to build, test, and deploy without changing Laravel's Composer or Vite lifecycle. |
| Deployment | Local Docker Compose initially; separate API, worker, and scheduler entry points/artifacts for hosting | Hostinger Business is a candidate for the API. Workers and the scheduler may require a different affordable managed service if persistent independent processes are unsupported. No VPS is assumed or authorized. |

Do not use Redis balances, in-memory position state, JavaScript floating-point values, BullMQ completion, or Laravel's SQLite rows as engine financial truth.

### 4.2 Monorepo and deployability

The settled repository location is `trading-engine/` inside the existing Meme Scanner repository. Preserve the existing Laravel directory structure and root Composer/Vite setup. The engine owns its own `package.json`, `pnpm-lock.yaml`, TypeScript configuration, environment template, Dockerfile, Compose file, migrations, tests, and build output.

Independent deployment means:

- Laravel can be built and deployed to HostGator without installing engine dependencies.
- The engine can be built from `trading-engine/` without running Composer or changing Laravel's SQLite database.
- CI uses path-filtered Laravel and engine jobs; a change touching shared API schemas runs both contract suites.
- Runtime configuration contains URLs and credentials only. No environment-specific branch or HostGator assumption appears in domain code.
- The engine supplies separate `api`, `worker`, and `scheduler` entry points and start commands. Each role is independently deployable, can use the same versioned code artifact, and communicates through external PostgreSQL/Redis rather than local process memory.
- A Hostinger API deployment must use a new Node.js application slot and its own domain, build settings, environment variables, and deployment lifecycle. It must not modify, redeploy, restart, or reuse `validator.tandafrica.com`.

### 4.3 Suggested repository layout

```text
trading-engine/
  package.json
  pnpm-lock.yaml
  pnpm-workspace.yaml
  tsconfig.json
  tsconfig.build.json
  eslint.config.js
  vitest.config.ts
  Dockerfile
  compose.yaml
  .env.example
  apps/
    api/
      src/main.ts
    worker/
      src/main.ts
    scheduler/
      src/main.ts
  src/
    application/
      commands/
      queries/
      handlers/
      policies/
      ports/
    domain/
      identity/
      markets/
      opportunities/
      strategies/
      orders/
      portfolio/
      ledger/
      risk/
    chains/
      solana/
      evm/
        ethereum/
        bnb/
        base/
    providers/
      birdeye/
      dexscreener/
      geckoterminal/
      goplus/
      jupiter/
      zero-x/
      rpc/
    infrastructure/
      database/
        migrations/
        repositories/
        outbox/
      queue/
      http/
      telemetry/
      crypto/
      config/
    contracts/
      http/
      events/
    shared/
      amount/
      clock/
      errors/
      ids/
  tests/
    unit/
    integration/
    contract/
    characterization/
    e2e/
    fixtures/
```

Domain code must not import Fastify, BullMQ, a provider SDK, or concrete database classes. Chain and provider implementations satisfy application ports so the core stays blockchain-independent and testable with deterministic clocks and recorded fixtures.

### 4.4 Core domain aggregates

Use explicit aggregates and state machines rather than one generic JSON status record:

- `MarketAsset` and `MarketPair`: canonical chain/network/address identity, decimals, provenance.
- `ScanRun` and `MarketObservation`: provider calls, observed-at/received-at, freshness, raw-payload digest, normalized facts.
- `SecurityAssessment`: provider, network, policy version, findings, freshness, fail-open/fail-closed applicability.
- `Opportunity`: qualification snapshot, user/strategy/policy version, state transitions.
- `ExecutionIntent`: PAPER or LIVE, SIGNAL/CONFIRM/AUTO, actor, amount, limits, authorization type, idempotency key.
- `Order` and `ExecutionAttempt`: quote, reservation, signing/authorization, broadcast, receipt, terminal failure.
- `Fill`: simulated or chain-proven quantity, price, fee, provenance.
- `Position`: inventory lots and lifecycle derived from fills, not merely a status flag.
- `LedgerAccount`, `LedgerTransaction`, and `LedgerEntry`: append-only double-entry balances.
- `StrategyInstance`: immutable strategy parameters attached to a position/order.
- `RiskDecision`: policy version, input digest, decision, reason codes, expiry.

PAPER and LIVE share the same opportunity, strategy, risk, order, fill, and position vocabulary. They use different execution adapters and clearly discriminated evidence. A `SimulatedFill` can never satisfy a LIVE accounting transition.

### 4.5 Initial hosting model and portability

The MacBook is a development/test host, not a production worker. Laravel on HostGator continues operating independently until an always-on engine deployment is affordable and ready. Do not expose the laptop as a permanent public dependency or point production callbacks at a temporary tunnel.

Hostinger Business Web Hosting is now the preferred candidate to evaluate for the public TypeScript API because the account already runs the Solana/Ethereum validator as a Node.js 22.x web application and has unused application slots. Hostinger's current documentation lists Fastify and Node.js 22.x/24.x support for Business Web Hosting and documents connections to external databases. Those facts do not establish that the plan can run an independent persistent BullMQ worker or scheduler, provide local PostgreSQL/Redis, or meet trading-engine uptime and restart requirements. See [Hostinger's Node.js deployment guide](https://www.hostinger.com/support/how-to-deploy-a-nodejs-website-in-hostinger/) and [Node.js version guide](https://www.hostinger.com/support/how-to-select-the-node-js-version-for-your-application/).

The candidate production topology is deliberately split:

| Role | Candidate placement | Requirement before production use |
|---|---|---|
| `api` | New Hostinger Node.js web application slot | Verify monorepo subdirectory build, selected Node version, Fastify start command, TLS/custom domain, environment secrets, outbound connectivity, logs, restart behavior, resource limits, and health checks. |
| PostgreSQL | External managed PostgreSQL | Verify TLS, connection limits/pooling, backups, restore testing, region/latency, storage growth, and credentials independent from Hostinger. Do not install or colocate it on Hostinger Business. |
| Redis | External managed Redis | Verify TLS, eviction policy, persistence expectations, latency, connection limits, and recovery behavior. Redis remains coordination infrastructure, never financial truth. |
| `worker` | Separate always-on process on a verified affordable service; Hostinger only if explicitly proven | Verify that it remains running independently of HTTP traffic/deploys, supports graceful shutdown/restart, and can reach external PostgreSQL/Redis and providers. |
| `scheduler` | Separate singleton/fenced process on a verified affordable service; Hostinger only if explicitly proven | Verify persistent scheduling, clock behavior, deployment/restart semantics, and single-active-instance fencing. |

Do not consume, reconfigure, or test against the existing Solana/Ethereum validator application or its domain. A hosting proof uses a new disposable application slot and synthetic Phase 1 endpoints only. It must be reversible and must not introduce production Laravel dependence while the topology is incomplete.

| Capability | Reliable during bounded local sessions | Requires always-on hosting for product operation |
|---|---|---|
| Type checking, linting, unit/contract/property tests | Yes | No |
| PostgreSQL/Redis integration, crash/retry, outbox/inbox, migration tests | Yes, with local containers | No |
| Recorded provider fixtures and deterministic scan qualification | Yes | No |
| Live provider/RPC smoke tests and one-shot shadow scans | Yes, while the Mac is awake and connected | Continuous scanning, provider budgeting, and timely signals do |
| Fresh PAPER wallets, manual PAPER trades, bounded PAPER AUTO simulations | Yes | Continuous position monitoring, SL/TP, and unattended PAPER AUTO do |
| Desktop wallet and mobile wallet connect/sign/reject/return flows | Yes, using localhost/LAN plus a temporary HTTPS development URL where mobile wallets require it | Stable production redirect/universal-link domains do |
| Devnet/testnet confirm-first LIVE preparation and reconciliation | Yes, explicitly initiated and supervised | Mainnet operation, durable reconciliation, and recovery while the user is offline do |
| Telegram/event delivery tests | Yes with fixtures or a development webhook | Timely production alerts and durable retries do |
| LIVE AUTO | No | Yes, plus an approved delegated authorization design, audits, monitoring, and incident response |

Future hosting must support TLS ingress for the API, outbound HTTPS/WebSocket access, encrypted secrets, logs, and independently supervised API/worker/scheduler roles. PostgreSQL and Redis may be external managed services and must be configurable entirely by URLs/credentials. The roles may be split across Hostinger and another affordable managed platform without redesigning the domain. No plan depends on purchasing a VPS.

## 5. Database ownership and integration

### 5.1 Settled topology

Laravel keeps its existing SQLite database on HostGator. The TypeScript engine owns a separate PostgreSQL database: a local container during development, then an external persistent PostgreSQL service reachable by every deployed engine role. It is not assumed to run on Hostinger Business. There is no requirement to migrate Laravel's users, sessions, settings, Telegram records, or other control-plane tables to PostgreSQL.

The databases are deliberately isolated:

- Laravel never opens a PostgreSQL connection to query or repair engine tables.
- The engine never opens Laravel's SQLite file or assumes filesystem/network access to HostGator storage.
- No cross-database foreign keys or shared migration ownership exist.
- Laravel sends authenticated, idempotent commands and queries to the engine API.
- The engine delivers versioned events from its PostgreSQL outbox to an authenticated Laravel webhook. Laravel records event IDs in an SQLite inbox before updating control-plane projections or sending notifications.
- If the engine is offline, Laravel shows engine state as unavailable/stale. It must not fall back to mutating legacy SQLite trading balances, positions, orders, fills, or attempts.

This physical separation enforces the architectural boundary more reliably than conventions inside a shared cluster.

### 5.2 Source-of-truth mapping

| Data | Authoritative store/owner | Disposition |
|---|---|---|
| `users`, authentication, sessions, onboarding | Laravel SQLite | Keep unchanged. Engine receives only a stable Laravel user reference and signed claims. |
| Future subscriptions/billing/entitlements | Laravel SQLite | Keep in Laravel; synchronize versioned entitlement facts through the API. |
| `application_settings`, `setting_audits` | Laravel SQLite for editable control-plane intent | Engine stores the exact activated trading-policy snapshot/version in PostgreSQL. |
| `user_trading_preferences`, `paper_strategy_settings` | Laravel SQLite for UI intent | Synchronize with an idempotent versioned command; engine snapshots applied values with every decision/order. |
| `connected_wallets`, `wallet_connection_challenges` | Laravel SQLite initially | Connection authentication remains a Laravel concern. Engine orders store the referenced canonical account/network and authorization evidence. |
| Existing `token_scans`, histories, opportunities, PAPER tables | Legacy Laravel SQLite | Do not migrate. Preserve untouched as test/reference data; stop writing a workflow only when its engine replacement is authoritative. |
| Existing Solana/Ethereum attempts, `live_positions`, accounting tables | Legacy Laravel SQLite | Do not migrate or resubmit. Preserve as reference/audit test data; there are no completed production LIVE transactions to import. |
| New observations, opportunities, PAPER wallets/ledger/positions | Engine PostgreSQL | Create fresh. Engine is the sole writer. |
| New LIVE intents, orders, attempts, receipts, fills, positions, accounting evidence | Engine PostgreSQL | Engine is the sole writer when those capabilities are enabled. |
| Notifications and dashboard projections | Laravel SQLite/cache as derived control-plane data | Updated only from authenticated engine queries/events; never authoritative for trading state. |
| Laravel jobs/cache | Laravel infrastructure | Continue supporting Laravel-only work on HostGator. |
| Engine jobs/leases/outbox/inbox | External engine PostgreSQL and Redis | Stay entirely within engine infrastructure; neither HostGator nor Hostinger Business is assumed to provide local databases, Redis, or persistent engine workers. |

### 5.3 Trading schema requirements

- Use UUIDv7/ULID identifiers generated by the engine. Store a stable `control_plane_user_id` string rather than a database foreign key to Laravel SQLite.
- Use `timestamptz` in UTC and store both `observed_at` and `received_at` for provider facts.
- Store token/native quantities as base-unit integers in `numeric(78,0)` (or a justified tighter bound) plus decimals. Never use binary floating point for money, amounts, prices, fees, balances, or P&L.
- Store prices/rates as fixed-point numerics with explicit scale and quote currency.
- Enforce unique idempotency keys for commands, orders, provider submissions, transaction hashes by network, and consumed events.
- Enforce legal states with check constraints plus transition code. Add an optimistic `version` column to aggregates.
- Use `SELECT ... FOR UPDATE` or serializable transactions for reservation and ledger settlement. Perform provider/RPC calls outside long transactions, then re-lock and revalidate version/lease before publication.
- Make ledger entries append-only and balanced. Corrections are compensating transactions, never updates to historical entries.
- Preserve raw provider/chain evidence by encrypted object storage or compressed JSONB with a digest, retention policy, and access audit. Normalized columns remain queryable.
- Use chain-neutral base-unit names from the first engine migration. No legacy SOL-named financial columns are imported.

### 5.4 Outbox, inbox, and consistency

Every engine transition that must notify Laravel inserts an outbox row in the same PostgreSQL transaction. A dispatcher claims unpublished rows with `FOR UPDATE SKIP LOCKED` and POSTs them to the Laravel event endpoint over HTTPS. Laravel verifies the signature/timestamp, inserts `event_id` into an SQLite inbox in the same transaction as its projection update, and treats a duplicate as success/no-op.

Commands from Laravel carry an idempotency key persisted in the engine command inbox before handling. Engine-to-Laravel delivery uses a signed webhook because the two applications do not share Redis or a database. Redis/BullMQ is internal engine coordination only and remains at-least-once in failure scenarios:

- BullMQ `jobId`/deduplication reduces duplicate work but is not the business guarantee.
- PostgreSQL unique keys and aggregate state enforce engine idempotency.
- Laravel's SQLite inbox enforces event-consumer idempotency.
- Outbox events remain replayable independently of Redis retention.
- Failed deliveries enter an observable retry/dead-letter workflow and retain the original event ID.

### 5.5 Fresh engine data and cutover

No existing PAPER wallet, balance, position, snapshot, or trade is imported. No existing LIVE attempt or legacy record is resubmitted. Phase 3 creates fresh test users/references, PAPER wallets, opening ledger transactions, balances, and positions in PostgreSQL through engine APIs or deterministic test fixtures.

Cut over one workflow at a time:

1. Capture the PHP behavior as tests/fixtures and define the engine contract.
2. Build and validate the engine workflow locally against fresh engine data.
3. Shadow-read or compare results where useful without copying financial state.
4. Disable the corresponding Laravel writer/schedule only when the engine is hosted and accepted for that workflow.
5. Switch Laravel to engine commands/projections.
6. Keep legacy SQLite rows untouched and read-only for reference until a separately approved cleanup task.

Never dual-write balances, order states, fills, attempts, or positions. A rollback switches the whole workflow authority and cannot merge divergent ledgers.

## 6. APIs and events between Laravel and the engine

### 6.1 API style and authentication

Use versioned JSON REST for commands/queries and versioned asynchronous events for facts. Publish OpenAPI for HTTP and JSON Schema/AsyncAPI-compatible definitions for events. Generate PHP DTOs or validate responses in Laravel; do not pass untyped provider payloads through the boundary.

During local development, Laravel and the engine should normally run on the MacBook together. A temporary authenticated HTTPS tunnel may be used for physical-phone or HostGator-to-development contract tests, but production Laravel must never depend on that tunnel or laptop. Once hosted, prefer private engine ingress where the platform permits it; otherwise expose only TLS endpoints protected by rotating service authentication, strict rate limits, and an IP/origin policy. User-scoped calls carry a short-lived Laravel-signed assertion with:

- `iss`, `aud`, `sub`, tenant/user ID.
- Authorized command scope and resource IDs.
- `iat`, `nbf`, short `exp`, and one-use `jti` for signing/execution commands.
- Correlation ID and session/authentication strength where relevant.

The engine independently verifies resource ownership, current entitlement, preference/config version, kill switches, and state. Service authentication is not user authorization.

### 6.2 Candidate HTTP surface

Names are illustrative; contract design should precede implementation.

| Method/path | Purpose | Idempotency/authorization |
|---|---|---|
| `PUT /v1/scanners/{network}/{scanner}/state` | Start/stop recurring discovery or follow-up scheduling | Admin/system scope; compare-and-set configuration version; desired state is idempotent. |
| `POST /v1/scan-runs` | Start an authorized one-shot scan for network/mode | Admin/system scope; idempotency key. |
| `POST /v1/scan-runs/{id}/cancel` | Request best-effort cancellation of a queued/running scan | Admin/system scope; cancellation state is idempotent. Already committed observations remain valid. |
| `GET /v1/scan-runs/{id}` | Read status, counts, provider degradation | Authorized operator. |
| `GET /v1/opportunities` | User-filtered projection | User claim; cursor pagination. |
| `GET /v1/opportunities/{id}` | Detail plus available actions | User ownership and entitlement. |
| `POST /v1/opportunities/{id}/approve` | Approve PAPER or begin LIVE reservation | Required idempotency key; one-use user assertion. |
| `POST /v1/opportunities/{id}/ignore` | Ignore once | Required idempotency key. |
| `POST /v1/trades/paper` | Request a user-initiated PAPER trade independent of an opportunity | User claim, entitlement, limits, strategy version, and idempotency key. |
| `POST /v1/trades/live` | Request a confirm-first LIVE intent; never directly broadcasts | User claim, network capability, entitlement, risk checks, and idempotency key. |
| `POST /v1/live-intents/{id}/confirm` | Confirm exact amount/slippage/network and begin preparation/signing handoff | One-use user assertion and current intent version. |
| `POST /v1/live-intents/{id}/reject` | Reject/cancel before any possible broadcast | User ownership; only legal pre-broadcast states; idempotent. |
| `POST /v1/execution-attempts/{id}/prepare` | Revalidate and produce exact signing payload | Owned attempt, state/version/expiry checks. |
| `POST /v1/execution-attempts/{id}/signing-claims` | Issue a one-time browser handoff | One-use `jti`; never log payload. |
| `POST /v1/execution-attempts/{id}/submitted` | Bind signed transaction/hash to intent | Idempotent by network/hash and attempt. |
| `POST /v1/execution-attempts/{id}/rejected` | Record known wallet rejection | Exact active claim ownership. |
| `POST /v1/positions/{id}/close-intents` | Request PAPER close or prepare a confirm-first LIVE close | User ownership; mode-specific risk/market checks and idempotency key. |
| `GET /v1/portfolios/{mode}` | PAPER or LIVE projection | User-scoped; mode is explicit. |
| `GET /v1/trades` | Order/fill/history projection | User-scoped; cursor pagination. |
| `PUT /v1/users/{id}/trading-settings` | Synchronize strategy, execution mode, entry mode, and limits | Laravel service scope; compare-and-set version; returns active engine version. |
| `PUT /v1/users/{id}/automation-state` | Start/stop automatic PAPER or an approved future LIVE strategy | User claim and entitlement; desired state is idempotent; LIVE AUTO remains capability-gated. |
| `POST /v1/users/{id}/kill-switch` | Stop new exposure | High-priority idempotent command; return enforced state. |
| `GET /v1/operations/{id}` | Read asynchronous command progress/result | Caller ownership/scope; stable terminal result. |
| `GET /v1/health/readiness` | Dependency readiness | Internal infrastructure only. |

Commands that finish asynchronously return `202 Accepted`, an operation ID, and a `Location` for `GET /v1/operations/{id}`. Operation states should be `queued`, `running`, `succeeded`, `failed`, or `cancelled`, while the referenced trading aggregate retains its more precise domain state. A timeout by Laravel does not mean the command failed; Laravel repeats the same idempotency key or queries the operation.

All mutation responses should return aggregate ID, state, version, idempotency key, and trace ID. Errors should use stable codes such as `MARKET_DATA_STALE`, `SECURITY_UNAVAILABLE`, `RISK_REJECTED`, `ATTEMPT_EXPIRED`, `STATE_CONFLICT`, and `TX_INTENT_MISMATCH`, plus `retryable` and a safe human message.

### 6.3 Event envelope

```json
{
  "event_id": "0199...",
  "event_type": "execution.transaction_confirmed.v1",
  "schema_version": 1,
  "occurred_at": "2026-09-27T12:00:00.000Z",
  "producer": "trading-engine",
  "aggregate_type": "execution_attempt",
  "aggregate_id": "0199...",
  "aggregate_version": 7,
  "user_id": "42",
  "network": "eip155:1",
  "correlation_id": "0199...",
  "causation_id": "0199...",
  "idempotency_key": "approve:...",
  "payload": {},
  "payload_sha256": "..."
}
```

Initial event families:

- `market.asset_discovered`, `market.observation_recorded`, `security.assessment_recorded`.
- `opportunity.qualified`, `opportunity.pending_confirmation`, `opportunity.ignored`, `opportunity.expired`.
- `execution.intent_reserved`, `execution.quote_prepared`, `execution.signing_requested`.
- `execution.transaction_submitted`, `execution.transaction_confirmed`, `execution.transaction_failed`.
- `fill.recorded`, `position.opened`, `position.protection_changed`, `position.closed`.
- `ledger.transaction_posted`, `portfolio.valuation_updated`.
- `risk.decision_recorded`, `risk.kill_switch_changed`, `provider.degraded`.
- `accounting.evidence_recorded`, `accounting.eligibility_changed`.

Laravel consumes notification-worthy and UI-projection events. The engine must not call email/Telegram inside a transaction that changes financial state.

### 6.4 Engine-to-Laravel event delivery

The engine delivers outbox events to a Laravel webhook; Laravel does not consume the engine's Redis queue. Sign `timestamp + method + path + raw_body` with a rotating HMAC key, reject stale timestamps, store event IDs before processing, and retry with backoff. HTTPS is mandatory. A successful HTTP response acknowledges delivery only; it does not redefine the trading result.

## 7. Multi-chain architecture

### 7.1 Network identity

Replace the two-case `app/Chain.php` assumption with an explicit immutable `NetworkId`. Prefer CAIP-style identifiers at boundaries:

- Ethereum mainnet: `eip155:1`.
- BNB Smart Chain mainnet: `eip155:56`.
- Base mainnet: `eip155:8453`.
- Solana mainnet-beta: use a stable configured Solana namespace/reference and never the display label alone.

Internally retain `family` (`evm` or `solana`), network/reference, native asset, finality policy, explorer templates, RPC pool, and capability flags. Do not equate all EVM networks with Ethereum mainnet.

Canonical asset identity is `(network_id, asset_address_or_native_marker)`. EVM addresses should be stored as 20 canonical bytes (with checksummed display text); Solana public keys as 32 canonical bytes/base58 display. The same hex address on Ethereum, BNB, and Base is three distinct assets/accounts.

### 7.2 Adapter contracts

Split adapters by capability rather than one oversized chain interface:

```text
DiscoveryProvider
MarketDataProvider
TokenMetadataProvider
SecurityProvider
BalanceProvider
QuoteProvider
TransactionBuilder
TransactionIntentVerifier
TransactionSubmitter (only where server submission is intended)
ReceiptProvider
InventoryEvidenceExtractor
FinalityPolicy
ExplorerLinkProvider
```

An engine network module composes the supported capabilities. Unsupported capabilities fail explicitly at configuration/startup or return `CAPABILITY_UNAVAILABLE`; they must not silently skip a LIVE safety check.

### 7.3 Chain-specific concerns

Solana:

- Preserve versioned-message hash, recent blockhash, expected fee payer/signers, address lookup tables, token program, mint decimals, and commitment/finality level.
- Verify that the signed message is byte-for-byte the prepared intent before submission.
- Add inventory extraction and LIVE position linkage before calling the flow complete.

EVM (Ethereum, BNB, Base):

- Parameterize chain ID, RPC, finality depth, wrapped native token, allowance/permit model, gas assets, explorer, 0x/DEX support, and security-provider support per network.
- Bind `from`, `to`, `value`, calldata, chain ID, and relevant fee fields to the prepared intent.
- Treat transaction replacement, dropped transactions, reorgs, fee-on-transfer tokens, rebasing tokens, proxies, decimals, and transfer-log ambiguity as explicit outcomes.
- Generalize the strong Ethereum inventory-evidence system, but require network-specific eligibility and code-hash reviews. An Ethereum review must not automatically approve the same address on BNB or Base.

### 7.4 Rollout order

1. Preserve Solana and Ethereum behavior with the new abstraction.
2. Prove PAPER and manual LIVE parity on those networks.
3. Add Base in discovery/PAPER-only mode, because it reuses the EVM family while still exercising network isolation.
4. Add BNB in discovery/PAPER-only mode with independent provider/security/liquidity policies.
5. Enable LIVE separately per network only after quote, intent verification, receipt, finality, inventory, sell/close, risk, and reconciliation acceptance criteria pass.

A global `supportsLive=true` flag is insufficient. Capabilities should be independently enabled for discovery, PAPER buy/sell, LIVE buy, LIVE sell, token security, inventory accounting, and automatic execution.

## 8. PAPER and LIVE execution model

### 8.1 Shared decision pipeline and mode semantics

```text
observation -> security assessment -> qualification -> opportunity
-> entry-mode policy -> risk decision -> execution intent
-> order -> attempt -> fill -> position -> ledger/projection
```

`execution_mode` (PAPER or LIVE) and `entry_mode` (SIGNAL, CONFIRM, or AUTO) are independent typed dimensions. The same mode names have consistent intent across both execution modes, while capability gates determine whether execution is allowed.

| Entry mode | PAPER | LIVE |
|---|---|---|
| SIGNAL | Record and notify; never create a PAPER order automatically | Record and notify; never prepare, sign, or submit a transaction |
| CONFIRM | User approves the exact PAPER intent before the simulator executes | User approves intent and then explicitly authorizes each wallet signature; existing exact-intent and receipt safeguards apply |
| AUTO | Engine may execute within strategy/risk/kill-switch limits; implement before LIVE AUTO | Ultimate product requirement, but disabled until an approved delegated authorization mechanism and always-on operations exist |

The engine stores the requested mode and an independent capability decision. `entry_mode=auto` is never itself sufficient authorization. Unsupported combinations return `CAPABILITY_UNAVAILABLE` and cannot create signing material or orders. PAPER AUTO is the first unattended execution milestone.

### 8.2 PAPER semantics

PAPER must remain incapable of broadcasting a chain transaction. Its adapter should:

- Obtain an executable quote when possible, including route, depth, slippage, provider timestamp, and quote expiry.
- Apply configurable simulated latency, gas/network fees, protocol fees, price impact, and failure/rejection rules.
- Produce a `SimulatedFill` with explicit provenance and confidence; fall back to mark-based valuation only for display, not a pretend fill.
- Post balanced virtual ledger entries and update lots/positions atomically.
- Support full and partial exits so take-profit tiers can sell a defined quantity rather than only arm a floor.
- Keep stop loss, take profit, protected floor, and trailing stop as separate typed rules with a deterministic precedence policy.

Use the existing Laravel behavior/tests as migration references, but create fresh engine data. First reproduce stop loss, protection level 1, protection level 2, and later full exit. Introduce partial take-profit and true trailing stops only as separately reviewed, versioned simulator behavior.

### 8.3 LIVE semantics

LIVE should use a monotonic state machine similar to:

```text
requested
  -> risk_reserved
  -> quote_prepared
  -> authorization_pending
  -> submitted
  -> confirmed
  -> inventory_reconciled

interactive authorization detail:
signing_claimed -> signing_armed -> wallet_approved | wallet_rejected | outcome_unknown

terminal alternatives:
rejected | expired | cancelled_before_broadcast | failed_on_chain | unsupported
```

Rules:

- Quote/preparation is not a fill.
- Wallet handoff is not a broadcast.
- A transaction hash is not success.
- A successful receipt at configured finality is the earliest execution confirmation.
- Inventory evidence determines acquired/sold quantity; the quote does not.
- An armed/uncertain signing claim is never automatically reissued. Reconcile first.
- A submitted transaction cannot be cancelled in the database as if it never existed.
- Every provider call has timeout, retry classification, circuit-breaker/budget controls, and redacted evidence.
- LIVE entry cannot launch until matching LIVE exit, inventory, emergency controls, and authorization exist for that network/token class.

### 8.4 Strategy and risk controls

Required hierarchy:

- Platform kill switch.
- Network kill switch.
- Execution-mode kill switch.
- Entry-mode/capability kill switch, including an independent `live_auto_enabled` defaulting false.
- User kill switch.
- Strategy kill switch.
- Provider degradation block.

Risk limits should include per-order amount, daily notional, concurrent exposure, per-asset exposure, per-network exposure, minimum liquidity, maximum price impact/slippage, observation/security freshness, gas ceiling, token eligibility, authorization scope, and cooldown. Decisions store effective values and policy version.

The kill switch prevents new exposure and new authorization/signing material. It cannot undo an already broadcast transaction, so reconciliation and permitted protective-close processing continue while entry is disabled.

### 8.5 Desktop and mobile wallet architecture

The wallet layer must support desktop extensions, desktop-to-phone QR sessions, and same-device mobile wallet apps without assuming Chrome extensions:

- **EVM desktop:** discover injected wallets through [EIP-6963](https://eips.ethereum.org/EIPS/eip-6963), with EIP-1193 fallback only where required.
- **EVM mobile and QR:** use WalletConnect-compatible sessions through Reown AppKit/Universal Connector. Reown supports mobile SDKs and EVM/Solana adapters; project origins/bundle IDs must be allowlisted. See the [Reown documentation index](https://docs.reown.com/llms.txt) and [relay allowlisting guidance](https://docs.reown.com/walletkit/ios/cloud/relay).
- **EVM connection authentication:** use a nonce-bound [Sign-In with Ethereum](https://eips.ethereum.org/EIPS/eip-4361)-style message where compatible, while retaining Laravel session authentication.
- **Solana desktop/in-wallet browser:** use Solana Wallet Standard discovery and feature detection. See the [Solana Wallet Standard](https://github.com/anza-xyz/wallet-standard).
- **Solana Android mobile:** evaluate the official Mobile Wallet Adapter web integration through `@solana-mobile/wallet-standard-mobile`; current documentation describes Android intent-based local connections. See [Mobile Wallet Standard installation](https://github.com/solana-mobile/solana-mobile-doc-site/blob/main/docs/mobile-wallet-adapter/web-installation.md).
- **Solana cross-platform mobile/iOS:** evaluate Reown's Solana adapter and supported wallets first. Wallet-specific universal/deep links are a fallback only after compatibility tests; do not make a single wallet vendor protocol the core abstraction.

Connection/authentication flow:

1. Laravel creates a short-lived, one-use challenge bound to domain, Laravel user, network, canonical wallet address, nonce, issued time, and expiry.
2. The web UI selects an injected provider, QR session, universal link, or supported mobile transport and opens the wallet.
3. The wallet displays and signs the authentication challenge. Merely establishing a WalletConnect/MWA session is not proof of account ownership.
4. The wallet returns through an allowlisted HTTPS universal link/app link or the WalletConnect session. The UI verifies a cryptographic `state`/request ID before accepting the response.
5. Laravel validates signature, domain/origin, address, network, nonce, expiry, user ownership, and one-time use, then records the connection.

Transaction authorization flow:

1. Laravel obtains an exact, expiring prepared intent from the engine and stores the attempt/return context before leaving the browser.
2. The UI sends a sign-only request through the active desktop/mobile transport. The wallet displays network, assets, amounts, router/recipient, slippage, and fee bounds.
3. On approval, the returned signed payload/hash is sent through Laravel to the engine. The engine re-verifies the exact message/calldata and attempt state before submission or acceptance.
4. On explicit rejection, the UI reports `wallet_rejected` against the active one-time claim; no transaction is marked submitted.
5. If the app is backgrounded, killed, redirected incorrectly, or loses connectivity, the result is `outcome_unknown`. The client restores the attempt ID from durable local state, queries the engine and chain/wallet session, and never automatically creates a replacement signature or broadcast.
6. After a terminal result, clear local return state and disconnect/revoke the wallet session only when the user requests it or policy requires it.

Universal links/app links are preferred to custom schemes because the operating system can bind them to verified domains. Return URLs, WalletConnect project origins, and mobile bundle IDs must be allowlisted. Connection session tokens/topics are transport credentials, not permission to execute arbitrary transactions.

### 8.6 Architectural decision: unattended LIVE authorization

Interactive browser/mobile wallets are sufficient for LIVE CONFIRM but cannot satisfy unattended LIVE AUTO. The engine needs a revocable, narrowly scoped authority that it can exercise while the user is offline. No such authority is implemented yet.

| Approach | Benefits | Risks/limitations | Decision |
|---|---|---|---|
| User signs every transaction in an external wallet | Strong user control; preserves current safeguards | Not unattended; mobile return/recovery complexity | Keep for LIVE CONFIRM |
| Raw server-held EOA/Solana private key, even encrypted | Simple execution model | Custody, key theft, compliance, broad authority, recovery burden | Rejected for current scope |
| HSM/MPC/managed signing service | Better key isolation and policy options than plaintext keys | Still introduces custody/signing authority, vendor and compliance risk | Do not implement yet; separate future approval required |
| EVM smart account with expiring session key/delegated permissions | Can restrict target contracts, assets, amounts, time, and revocation; compatible with ERC-4337-style execution | Wallet/network support varies; permission standards and modules require security review | Primary EVM research path; no implementation yet. See [ERC-4337](https://eips.ethereum.org/EIPS/eip-4337) and [ERC-7715](https://eips.ethereum.org/EIPS/eip-7715) |
| User-funded on-chain strategy vault with a constrained executor | On-chain enforceable policy, withdrawal/revocation, independent of an always-open wallet | Requires audited contracts, deposits, upgrade/governance design, and chain-specific implementations | Strong long-term candidate for EVM and Solana; architecture decision pending |
| Solana SPL token delegate or purpose-built program/vault | Token delegation can cap an approved amount while the owner retains custody | One delegate per token account, token-specific scope, SOL/gas and arbitrary swap authority need a program design | Solana research path only. See [Solana spend permissions](https://solana.com/docs/payments/advanced-payments/spend-permissions) |
| Third-party automation/relayer | Can provide uptime, gas, and transaction delivery | Does not create authorization by itself; adds vendor trust and availability dependencies | May execute an approved smart-account/vault policy, never replace it |

Decision for Phases 1–5:

- Model `ExecutionAuthorization` as an explicit capability with type, network, subject, scope, limits, expiry, revocation state, and evidence, but implement only `interactive_wallet`.
- Implement and soak PAPER AUTO before designing or enabling LIVE AUTO.
- Keep `live_auto_enabled=false` at platform and network levels regardless of stored user preference.
- Do not store user private keys, seed phrases, raw session keys, or a broadly authorized signer on the MacBook or HostGator.
- Preserve confirm-first preparation, one-time signing claims, exact-intent validation, explicit rejection, uncertain-outcome recovery, receipt finality, and inventory accounting.
- Before LIVE AUTO, approve a separate ADR selecting authorization per chain; complete contract/module audits, revocation and expiry tests, amount/router/asset constraints, key isolation, always-on hosting, monitoring, incident response, and legal/compliance review.

### 8.7 Copy trading

Copy trading is new scope, not a migration of existing code. Do not model it as calling another user's approve endpoint. It requires explicit leader consent, follower opt-in, allocation caps, delay/slippage policy, eligibility filtering, privacy boundaries, revocation, partial-fill rules, and legal/compliance review. Implement it only after the ordinary order/fill/position/ledger model is stable.

## 9. Security and operational risk controls

### 9.1 Critical migration risks

| Risk | Current signal | Required control |
|---|---|---|
| False impression that LIVE is complete | Generic executor is disabled while separate wallet flows exist | UI/API capability matrix; never label a network LIVE-ready until entry, exit, accounting, reconciliation, and emergency controls pass. |
| Ethereum scanner security gap | Scanner does not use `GoPlusEthereumService`; LIVE later revalidates | Engine qualification policy records required security state; LIVE fail closed on missing/stale checks. |
| Double execution during cutover | Two runtimes could process the same opportunity/attempt | One writer per aggregate, unique idempotency keys, fenced leases, no dual-write. |
| Duplicate queue delivery | Database queues/BullMQ retry after crashes | Atomic/idempotent handlers, inbox keys, state/version checks. |
| Stale market data | Providers can delay, fail, or return mismatched assets | Observed/received time, identity verification, quote expiry, source health, fail-closed LIVE. |
| PAPER overstates returns | Current exit uses observed marks, not executable route | Executable-quote simulation, fees/impact/latency, prominent simulation labeling. |
| Distributed lock split brain | PAPER tracker defaults to file locks | PostgreSQL row/fenced leases or Redis lease with fencing token; DB invariant remains authoritative. |
| Missing LIVE exits | Current live paths are buy-oriented | Do not enable automatic LIVE entry until sell/close/reconcile is tested end to end. |
| Browser/XSS transaction substitution | Signing happens in served JavaScript | CSP, dependency integrity, exact intent display, message/calldata verification, one-time claims, no secrets in DOM/logs. |
| Remote validator compromise/outage | Solana and Ethereum proof/intent validation include remote trust | Local verification where practical, authenticated TLS, independent post-sign verification, health/circuit breaker, explicit trust model. |
| Provider key/secret disclosure | Provider settings and payloads cross multiple layers | Secret manager, least privilege, rotation, Pino redaction, encrypted evidence, no raw secrets in DB/events. |
| Incorrect numeric accounting | Multi-chain decimals and legacy SOL names | Integer base units, fixed-point rates, checked conversions, property tests, ledger reconciliation. |
| Reorg/replacement/finality error | Chain-specific transaction semantics | Network finality policy, replacement tracking, reorg reconciliation, immutable evidence versions. |
| Tenant leakage | Global scans become per-user opportunities | Authorization on every query/command, tenant keys in constraints, isolation tests, safe caches. |
| Address collision across EVM networks | Current global address hash uniqueness | Canonical `(network, address)` identity and explicit cross-user linking policy. |
| Notification side effects affect trades | Telegram/email are integrated with workflows | Outbox after commit; notification is downstream and replayable. |
| Laptop mistaken for production | Initial engine runs locally and is not continuously available | Production Laravel has no dependency on it; mark continuous features unavailable until always-on hosting exists. |
| Hostinger capability over-assumption | An existing Solana/Ethereum validator proves web-app hosting, not database, Redis, worker, scheduler, or trading-engine reliability | Treat Hostinger as an API candidate only; require a disposable-slot capability test and external PostgreSQL/Redis before production dependency. |
| Existing validator collateral change | The account already hosts `validator.tandafrica.com` | Use a separate slot/domain/configuration; prohibit changes, restarts, shared secrets, or deployment coupling to the validator. |
| Mobile redirect/session confusion | OS may kill/background apps; deep links can be intercepted/misrouted; wallet result can be unknown | Verified universal links, allowlisted origins, state nonce, durable attempt ID, explicit rejection, query-before-retry recovery. |
| Over-broad unattended authority | LIVE AUTO ultimately requires offline authorization | Default disabled; scoped/revocable on-chain authority, independent capability gates, audits, bounded-loss tests, no raw user keys. |

### 9.2 Transaction and key safety

- Maintain non-custodial desktop/mobile signing for CONFIRM. Any delegated unattended authority is a separately approved, narrowly scoped capability and is not equivalent to wallet connection.
- Never request, transmit, store, or log seed phrases/private keys.
- Encrypt prepared transaction payloads and sensitive provider evidence with envelope encryption; separate encryption keys from application data.
- Display network, asset, amount, recipient/router, maximum slippage, fee/gas bound, and expiry before wallet handoff.
- Re-verify the signed/broadcast transaction against the server-owned intent. Do not rely only on wallet UI.
- Use Content Security Policy, secure cookies, CSRF protection on Laravel BFF routes, dependency scanning, and restricted third-party scripts on signing pages.

### 9.3 Service and data security

- Prefer private engine ingress on the future host. If public ingress is unavoidable, expose only TLS endpoints protected by service authentication, strict rate limits, and an IP/origin policy.
- mTLS/service identity and short-lived user assertions; rotate keys with overlapping key IDs.
- Separate database/Redis credentials per process role and environment.
- Egress allowlists for RPC/market providers where operationally feasible.
- Rate limits and per-user/provider budgets at Laravel and engine layers.
- Signed, replay-protected events/webhooks with schema validation and payload size limits.
- Append-only audit records for policy changes, approvals, signing claims, submissions, reconciliations, manual reviews, and administrative replay.
- Defined retention/deletion policy that preserves required trading evidence while honoring privacy obligations.

### 9.4 Observability and operations

Minimum metrics:

- Scan duration, assets discovered/qualified, provider latency/errors/rate-limit budget.
- Opportunity counts and transition latency by network/mode.
- Queue age, attempts, duplicates, lease expiry, dead letters, outbox lag, inbox duplicates.
- Quote age, signing-claim age, submitted transaction age, receipt/finality lag.
- PAPER observation age, skipped positions, valuation age, ledger reconciliation drift.
- LIVE unresolved/uncertain attempts, inventory reconciliation lag, accounting eligibility backlog.
- Kill-switch state and time from request to enforced acknowledgement.

Alerts should target invariants and stuck states, not only process uptime. Readiness fails when the service cannot safely accept new work; liveness should remain independent so an orchestrator does not restart a healthy process during a provider outage.

## 10. Migration phases and implementation readiness

### 10.1 Final architecture decisions

- Laravel remains on HostGator with SQLite and owns the control plane.
- The engine is a self-contained `trading-engine/` project in the same repository and owns dedicated PostgreSQL plus Redis; production instances may be external managed services.
- Hostinger Business is the preferred candidate for a new TypeScript API application slot, subject to a non-invasive capability proof. The existing Solana/Ethereum validator at `validator.tandafrica.com` remains untouched.
- API, worker, and scheduler are independently deployable. Hostinger worker/scheduler persistence is unproven, so those roles may run on another affordable managed service. No VPS purchase is authorized.
- The systems integrate only through authenticated versioned APIs and signed replay-protected webhooks/events.
- Existing PAPER/LIVE data is not migrated. New engine tests use fresh PostgreSQL wallets, ledger entries, positions, and attempts.
- SIGNAL, CONFIRM, and AUTO are shared entry modes; PAPER AUTO precedes LIVE AUTO, and every mode is capability-gated by execution mode/network.
- Desktop and mobile external wallets are supported for connection and confirm-first authorization.
- No private-key custody or delegated LIVE execution is implemented until a separate authorization ADR and security review are approved.
- The MacBook is for bounded development/testing only. Continuous product features wait for a verified always-on topology, which may split the Hostinger API from externally hosted data services and background roles.

### 10.2 Remaining blockers before Phase 1

There is no unresolved product decision that blocks scaffolding the local foundation. Resolve these setup details before the first code change:

1. Choose Docker Desktop or Colima for local PostgreSQL/Redis/Testcontainers and confirm available CPU, memory, and disk.
2. Reserve local ports and origins (recommended defaults: API `3100`, PostgreSQL `5433`, Redis `6380`) and choose a temporary HTTPS development URL for physical-phone tests. This URL is never a production endpoint.
3. Choose the service-auth signing format and key rotation plan. Recommendation: short-lived Ed25519 JWT assertions from Laravel to engine plus a separate HMAC key for engine-to-Laravel webhooks.
4. Choose a stable Laravel user identifier exposed in claims (`control_plane_user_id`); do not couple PostgreSQL to SQLite row internals if a public UUID can be introduced later.
5. Confirm the Phase 1 dependency versions together on Node 24/TypeScript 6 and commit the resulting lockfile.
6. Decide whether GitHub Actions may run Docker-based PostgreSQL/Redis tests within the available budget. Local acceptance does not depend on paid infrastructure.

Wallet SDK selection, provider credentials, the Hostinger production-topology proof, external PostgreSQL/Redis vendor selection, worker/scheduler hosting, and unattended LIVE authorization are not Phase 1 blockers because Phase 1 has no wallet/provider/trading authority.

### 10.3 First implementation milestone

Create a locally runnable, independently testable engine foundation that exposes authenticated health/version endpoints, connects to disposable PostgreSQL and Redis, runs migrations, persists an idempotent no-op command, publishes/delivers a synthetic outbox event to a fake Laravel receiver, and propagates a trace/correlation ID. It must contain no scanner provider, wallet adapter, balance, position, order, fill, transaction preparation, or trade execution behavior.

### 10.4 Exact Phase 1 files and directories

Phase 1 should create only this initial surface (empty domain/provider/chain folders are deferred until their first implementation):

```text
trading-engine/
  .dockerignore
  .env.example
  .gitignore
  Dockerfile
  README.md
  compose.yaml
  package.json
  pnpm-lock.yaml
  pnpm-workspace.yaml
  tsconfig.json
  tsconfig.build.json
  eslint.config.js
  vitest.config.ts
  apps/
    api/src/
      app.ts
      main.ts
    worker/src/
      main.ts
    scheduler/src/
      main.ts
  src/
    application/
      commands/accept-noop-command.ts
      handlers/accept-noop-command-handler.ts
    config/
      env.ts
    contracts/
      http/health.schema.ts
      http/noop-command.schema.ts
      events/event-envelope.schema.ts
    infrastructure/
      database/client.ts
      database/migrate.ts
      database/migrations/001_foundation.ts
      database/repositories/command-inbox-repository.ts
      database/repositories/outbox-repository.ts
      http/laravel-webhook-client.ts
      queue/connection.ts
      queue/names.ts
      telemetry/instrumentation.ts
    interfaces/
      http/health-routes.ts
      http/noop-command-routes.ts
    shared/
      errors/application-error.ts
      ids/id.ts
    workers/
      outbox-dispatcher.ts
  tests/
    contract/
      health-contract.test.ts
      event-envelope-contract.test.ts
    integration/
      command-idempotency.test.ts
      migration.test.ts
      outbox-delivery.test.ts
    unit/
      env.test.ts
    support/
      fake-laravel-receiver.ts
      test-environment.ts
.github/workflows/
  trading-engine.yml
```

`001_foundation.ts` creates only schema-migration metadata, command inbox, event outbox, and delivery-attempt tables. It does not create wallets, balances, opportunities, orders, fills, positions, or transaction attempts. Laravel integration code/migrations are a later explicit task after the engine contract is stable.

### 10.5 Local development prerequisites for macOS

- A supported macOS release for the pinned Node 24 build (official Node 24 binaries require macOS 13.5 or later; see the [Node.js 22-to-24 migration guide](https://nodejs.org/en/blog/migrations/v22-to-v24)), Git, and Xcode Command Line Tools.
- Node.js 24 LTS managed by Volta, `asdf`, or `nvm`; activate the `pnpm` version pinned in `packageManager`/the lockfile.
- Docker Desktop or Colima with Docker Compose v2 for PostgreSQL, Redis, and Testcontainers. No local system PostgreSQL/Redis install is required.
- At least 8 GB RAM available to development workloads and sufficient disk for container images/database volumes; tune the container allocation to the MacBook.
- Optional PHP 8.5 and Composer when running Laravel locally for end-to-end BFF tests. Otherwise Phase 1 uses the fake Laravel receiver.
- A current Safari/Chrome browser. Physical-phone wallet tests additionally need an iOS/Android device, test wallets, same-network access or a temporary HTTPS tunnel, and allowlisted development redirect origins.
- Solana devnet/EVM testnet accounts only for later wallet phases. No mainnet keys or funds are required for Phase 1.
- Development service-auth/webhook keys stored in uncommitted `.env`; `.env.example` contains names and safe placeholders only.

### 10.6 Phase 1 acceptance criteria

- `pnpm install --frozen-lockfile`, lint, strict typecheck, build, and Vitest pass from `trading-engine/` without running Composer.
- Laravel's existing build/test commands remain unchanged and no Laravel application file or SQLite migration is modified.
- `docker compose up` starts only the local engine PostgreSQL/Redis and the engine process roles; no HostGator service is assumed.
- Migrations apply to an empty PostgreSQL database and re-running them is safe.
- API readiness distinguishes process liveness from PostgreSQL/Redis readiness and returns version/build metadata without secrets.
- Missing/invalid/expired service authentication is rejected; an authorized no-op command returns a stable operation ID.
- Sending the same idempotency key concurrently produces one command-inbox result and no duplicate side effect.
- A transaction containing the no-op result and outbox event commits atomically; forced worker failure/restart eventually delivers one logical event, while duplicate webhook delivery is accepted as a no-op by the fake receiver.
- Logs are structured and redact configured secrets; correlation/trace IDs traverse API, database command, queue job, webhook attempt, and fake receiver.
- Engine shutdown drains HTTP and workers without abandoning a claimed outbox item; restart safely resumes it.
- No endpoint, job, schema, or credential can scan providers, create a PAPER/LIVE trade, construct a wallet transaction, or broadcast to a blockchain.
- CI is path-filtered and can test the engine independently; the monorepo still deploys Laravel independently.

### Phase 0 — Baseline, contracts, and local safety freeze

Work:

- Preserve current PHPUnit/browser tests and convert the most valuable state transitions into behavior tables and provider fixtures.
- Define network IDs, amount types, error codes, HTTP/event schemas, idempotency semantics, and ownership matrix.
- Add explicit capability definitions so SIGNAL/CONFIRM/AUTO and PAPER/LIVE support cannot be inferred from stored preferences.
- Record that Laravel remains on SQLite, the engine starts with fresh PostgreSQL data, and the laptop is not a production dependency.

Acceptance:

- Every current trading writer has an owner and retirement phase.
- Golden fixtures cover Solana/Ethereum qualification, PAPER exits, exact transaction binding, receipts, and Ethereum inventory evidence.
- No UI/API can imply that LIVE AUTO is enabled merely because AUTO is selectable/stored.
- Phase 1 blockers in section 10.2 are resolved.

### Phase 1 — Local engine foundation, no trading authority

Work and acceptance are defined in sections 10.3–10.6. Do not connect production Laravel or add provider/wallet credentials in this phase.

### Phase 2 — Local shadow market scanning and qualification

Work:

- Port provider clients and normalization, starting with Solana new-token scanning, then momentum, then Ethereum.
- Add per-provider budgets, recorded fixtures, freshness, provenance, and security policy.
- Run bounded one-shot scans on the MacBook and compare normalized observations/opportunities without exposing engine decisions to production users.

Acceptance:

- Agreed parity for discovered/qualified/rejected fixture assets; every difference has a reason code.
- Ethereum security coverage is explicit and fail-closed for prospective LIVE opportunities.
- Provider 429/timeout/malformed/wrong-asset/stale-data tests pass.
- Engine scanning cannot debit a PAPER wallet or create LIVE signing material.
- Stopping the laptop causes no production failure because HostGator has no engine dependency yet.

### Phase 3 — Fresh PAPER portfolios and PAPER AUTO

Work:

- Implement immutable strategy snapshots, simulated orders/fills, double-entry virtual ledger, fresh wallets/positions, valuations, and current stop/protection behavior.
- Implement SIGNAL, CONFIRM, and capability-gated AUTO for PAPER.
- Integrate a local Laravel instance or authenticated development BFF with fresh engine projections; do not import SQLite PAPER rows.

Acceptance:

- Opening ledger transactions plus entries reconcile exactly to every fresh wallet balance.
- Existing PHP strategy/reliability/close test vectors pass in TypeScript against fresh fixtures, including duplicate delivery, lock loss, stale data, and cross-user races.
- SIGNAL never opens; CONFIRM requires approval; AUTO opens only within enabled strategy/risk/kill-switch policy.
- No engine test mutates Laravel SQLite or legacy records.
- Bounded local PAPER AUTO survives forced restarts and resumes idempotently. Continuous PAPER AUTO remains labeled unavailable until always-on hosting.

### Phase 4 — Mobile/desktop wallet integration and confirm-first LIVE parity

Work:

- Implement EIP-6963/Wallet Standard desktop discovery and WalletConnect/Reown plus supported Solana mobile transports.
- Port Solana Jupiter and Ethereum 0x preparation, exact intent verification, expiry, wallet handoff, rejection/recovery, submission reporting, and receipt reconciliation.
- Test only devnet/testnets/forked environments locally; preserve uncertain-broadcast recovery and Ethereum accounting evidence.

Acceptance:

- Desktop extension, desktop-to-phone QR, Android same-device, and iOS same-device flows have a documented support matrix and pass connection/sign/reject/return/recovery tests for selected wallets.
- Altered signer/fee payer/recipient/value/calldata/network/message is rejected.
- Repeated prepare/confirm/submit/report/reconcile requests never broadcast twice or create duplicate fills.
- Prepared/submitted attempts never appear as successful trades.
- App-background, killed-browser, broken-return-link, expired-session, wallet rejection, and unknown-outcome cases converge safely.
- Connection proof cannot authorize a transaction, and a transaction approval cannot be replayed as connection proof.

### Phase 5 — Opportunity-driven LIVE SIGNAL and CONFIRM

Work:

- Generalize the Ethereum reservation/revalidation/signing-claim design to engine aggregates.
- Add Solana opportunity linkage only after Solana inventory/exit support exists.
- Run from a verified always-on topology before any production enablement; the API may use Hostinger while worker/scheduler roles run separately. Local tests remain testnet-only.

Acceptance:

- SIGNAL creates no signing material; CONFIRM rechecks entitlement, preference, kill switch, market/security freshness, limits, wallet, and opportunity version.
- The same opportunity cannot reserve or execute twice under concurrent approvals.
- Every confirmed fill has receipt/finality/inventory evidence and balanced ledger entries.
- Protective exits are tested against gaps, illiquidity, provider outage, and partial fills; UI states on-chain stops cannot guarantee price.
- Emergency halt blocks new signing material within the agreed SLO while reconciliation of submitted transactions continues.

### Phase 6 — Unattended LIVE authorization and AUTO

Dependencies: Phase 3 PAPER AUTO soak, Phase 5 LIVE SIGNAL/CONFIRM, a verified always-on API/worker/scheduler topology with external PostgreSQL/Redis, and a separately approved authorization ADR. Buying a VPS is not a dependency or an authorized solution.

Work:

- Select and audit chain-specific bounded authorization (for example, EVM smart-account/session permissions and a Solana program/vault/delegate design).
- Implement revocation, expiry, asset/router/amount/daily limits, authorization evidence, key isolation, executor failover, monitoring, and incident controls.
- Enable LIVE AUTO independently by platform, network, user, strategy, and token capability.

Acceptance:

- No raw user private key/seed is held by Laravel or the engine.
- On-chain/off-chain enforcement prevents calls outside approved networks, assets, routers, methods, amounts, time windows, and daily exposure.
- Revocation and platform kill switch stop new automated authorization within the defined SLO; already submitted transactions continue reconciliation.
- Compromise simulations demonstrate bounded loss and produce complete audit evidence.
- Contract/module audits, legal/compliance review, disaster recovery, and production incident exercises are complete.
- A database/interface AUTO value cannot bypass any capability gate.

### Phase 7 — Base and BNB expansion

Work:

- Add network configuration/providers one capability at a time.
- Run discovery and PAPER before LIVE; establish independent eligibility, finality, router/allowance, gas, authorization, and inventory policies.

Acceptance per network:

- Canonical identity, decimals, native/wrapped asset, RPC failover, finality, explorer, and provider support tests pass.
- Discovery/PAPER soak shows no cross-network address/cache collisions.
- LIVE buy/sell quotes, exact intent validation, receipts, inventory, ledger, authorization, and reorg recovery pass.
- Enabling one EVM network cannot enable another.

### Phase 8 — Laravel retirement and hardening

Work:

- Remove retired writers/schedules and provider secrets from Laravel without deleting legacy SQLite records in the migration task itself.
- Replace legacy trading models with typed engine clients/projections.
- Exercise load, restoration, incident, key-rotation, and disaster-recovery procedures.

Acceptance:

- Architecture and integration tests prove Laravel cannot mutate engine-owned trading state.
- No retired Laravel scheduler/job/controller/service can call a market/execution provider.
- Engine backup restore reproduces ledger/positions and resumes outbox/inbox safely.
- SLOs, dashboards, alerts, on-call runbooks, and reconciliation reports are owned and exercised.
- Legacy code removal is covered by Laravel contract/UI tests and TypeScript engine tests.

## 11. Laravel components to retire or simplify

Retirement happens only after the corresponding phase acceptance criteria pass.

### 11.1 Retire from Laravel after scanner cutover

- `app/Console/Commands/ScanNewTokens.php`
- `app/Console/Commands/ScanMomentumTokens.php`
- `app/Console/Commands/FollowUpTokens.php`
- Empty `app/Services/TokenScannerService.php`
- `app/Services/EthereumScannerService.php`
- `app/Services/EthereumQualificationEvaluator.php`
- `app/Services/NewTokenClassificationService.php`
- Trading-engine provider clients: Birdeye, DexScreener, GeckoTerminal, GoPlus, Solana/Ethereum RPC metadata services.

Laravel may keep operator buttons, but they call `POST /v1/scan-runs` and display engine status.

### 11.2 Retire after PAPER cutover

- `PaperTradeEntryService`, `PaperTradeExitService`, `PaperTradingService`, `PaperWalletService`, `PaperStrategyService`, and `PaperMarketObservation` as writers/domain authorities.
- `TrackPaperPositions`, `TrackPaperPositionsFast`, `ClosePaperPosition`, `ReconcilePaperWallet`, and `PaperTradingReport` command behavior.
- PAPER schedules in `routes/console.php`.
- Direct writes by `PaperStrategySettingController`, `ClosePaperTradeController`, `OpportunityActionService`, and `PaperTradeExecutor`; replace with engine commands.
- `PaperTradeHistoryService` and dashboard model queries; replace with projections/API queries.

The controllers/views can remain as a compatibility UI while their data source changes.

### 11.3 Retire after LIVE cutover

- `TradeExecutionManager`, `TradeExecutor`, the always-disabled `LiveTradeExecutor`, and eventually `PaperTradeExecutor`.
- Solana quote/order/execute controllers and Jupiter/validation services as execution authorities.
- Ethereum swap/opportunity preparation/reservation/revalidation/receipt services as execution authorities.
- Solana/Ethereum attempt expiry and reconciliation commands/schedules.
- `LivePositionService`, Ethereum inventory-accounting workers/services, and their direct Laravel models after evidence is moved.

Keep `resources/js/solana-wallet.js`, `ethereum-wallet.js`, and `ethereum-opportunity.js` initially as behavior references, then evolve the Laravel-served client into a standards-based desktop/mobile wallet layer. Wallet discovery, QR/deep-link handoff, signing UI, return, and recovery stay client-side; transaction construction, authoritative validation, and state transitions belong in the engine.

### 11.4 Keep and simplify in Laravel

- Auth controllers/middleware, user/account/onboarding models and views.
- Future subscription/billing/entitlement management.
- Admin settings and review UI, emitting typed commands rather than mutating trading tables.
- Telegram bot connection, webhook authentication, command authorization, menus, and customer notification delivery.
- Dashboard/opportunity/trade-history views as BFF/read-model consumers.
- `ApplicationSetting` only for control-plane concerns; engine secrets move to a secret manager and trading policy activation becomes versioned.
- `SystemActivityService` for control-plane activity; engine events/telemetry handle trading operations.
- Laravel queue/scheduler only for Laravel work.

### 11.5 Test disposition

- Keep Laravel authentication, authorization, CSRF, route, UI rendering, Telegram, and BFF contract tests.
- Port provider/domain/race/reconciliation tests to TypeScript before deleting their PHP implementation.
- Keep shared JSON fixtures for PHP-client and TypeScript-server contract tests.
- Do not delete the current high-value race/failure tests merely because a happy-path engine E2E test exists.

## 12. Open decisions

The initial hosting direction, database ownership, repository, data-migration, mode, and initial custody decisions are settled above. These remaining decisions do not block the local Phase 1 scaffold unless explicitly noted:

1. **Hostinger production topology:** Validate a new disposable API slot without touching `validator.tandafrica.com`; confirm monorepo deployment, Node/Fastify runtime, resource/restart limits, health checks, egress, logs, secrets, and custom-domain behavior. Separately determine whether Hostinger supports persistent independent BullMQ worker and scheduler processes. If not, select affordable non-VPS managed hosting for those roles plus external PostgreSQL and Redis. Required before any production engine dependency, not before Phase 1.
2. **Service authentication:** Confirm Ed25519 JWT versus another short-lived assertion format, webhook HMAC rotation, clock-skew window, and key storage on HostGator/future host. Required before Laravel integration.
3. **Stable control-plane identity:** Use current Laravel integer IDs or introduce public UUIDs before cross-system user references become durable?
4. **Mobile wallet support matrix:** Which EVM/Solana wallets and OS/browser versions are launch requirements, and which Reown/MWA/deep-link combinations pass real-device tests?
5. **Wallet connection ownership:** Keep proof/linking in Laravel permanently, or move chain-specific verification behind an engine contract later?
6. **Unattended LIVE authorization:** Select EVM smart-account/session permissions versus a strategy vault, and select the Solana delegate/program/vault design. This blocks Phase 6, not Phase 1.
7. **Executor key isolation:** If a bounded delegated signer is approved, choose HSM/KMS/MPC storage, rotation, recovery, quorum, and compromise response. A plaintext server key is not an option.
8. **EVM address sharing:** May the same address link to multiple Laravel users? Define proof, visibility, and abuse policy.
9. **Network order:** Base before BNB remains recommended after Solana/Ethereum parity; provider coverage and target users may change it.
10. **Provider contracts:** Confirm permitted storage, redistribution, automated trading, rates, and production networks; define primary/fallback per capability.
11. **Security fail policy:** Define mandatory checks for PAPER, LIVE CONFIRM, and LIVE AUTO per network. Unknown security remains an explicit state.
12. **PAPER realism:** Select the first versioned simulator's quote, fee, latency, price-impact, and failure assumptions. No legacy PAPER result must be preserved.
13. **Strategy semantics:** Define partial take-profit percentages, protected floors, true trailing stops, precedence, gap behavior, and whether edits affect only new positions.
14. **Portfolio accounting:** Choose FIFO/LIFO/specific-lot, quote currency, gas allocation, fee-on-transfer/rebase behavior, and reporting expectations.
15. **Finality policy:** Set confirmations/finality per network and reorg behavior after provisional UI display.
16. **Read-model latency:** Set acceptable dashboard, notification, kill-switch acknowledgement, and history lag.
17. **Entitlements/subscriptions:** Define plans/limits and behavior when entitlement is revoked while positions remain open.
18. **Copy trading:** Confirm product scope and complete legal/compliance, consent, privacy, allocation, delay, and leader-failure design.
19. **Admin review model:** Decide how Ethereum bytecode/token eligibility review generalizes to Base/BNB and which roles may approve/reconsider.
20. **Retention/privacy/disaster recovery:** Set evidence/log retention, RPO/RTO, backup cadence, restore verification, Redis-loss behavior, and global halt criteria.
21. **Rollout governance:** Identify who enables each capability/network, required soak periods, amount/user caps, and rollback authority.

The first implementation milestone is still the local Phase 1 foundation in sections 10.3–10.6—not a scanner, trade, or Hostinger deployment. It proves independent packaging and process roles, dedicated PostgreSQL/Redis, authentication, idempotency, outbox delivery, observability, and restart behavior without putting assets, wallets, HostGator, Hostinger's existing validator, or the legacy SQLite database at risk.
