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
- Copy trading is absent. Subscription/billing code is also absent, although subscriptions are an appropriate long-term Laravel responsibility.
- `app/Services/TokenScannerService.php` is empty. Actual scanning lives in `ScanNewTokens`, `ScanMomentumTokens`, `FollowUpTokens`, and `EthereumScannerService`.

The project context states that current users and PAPER positions are test data and that no LIVE transaction has completed in production. That materially lowers data-migration risk: the preferred cutover is a fresh, redesigned trading schema plus deterministic test fixtures, unless the team explicitly chooses to retain selected PAPER data for comparison. Existing behavior still needs characterization so useful safeguards are not discarded.

The recommended destination is a **strangler migration**, not a rewrite cutover:

1. Keep Laravel as the control plane: identity, sessions, onboarding, future subscriptions, settings/admin UX, wallet-connection UX, notifications, and browser-facing BFF endpoints.
2. Make one TypeScript service the sole writer for market observations, opportunities, strategy decisions, PAPER ledgers, orders, transaction attempts, receipts, positions, and trading audit events.
3. Begin with read-only shadow scanning, then move PAPER, then move the existing manual LIVE paths, and only then enable opportunity-driven LIVE chain by chain.
4. Preserve browser/non-custodial signing. The engine may construct and verify an exact transaction intent, but it must not receive seed phrases or private keys.
5. Use PostgreSQL constraints, append-only ledgers, explicit idempotency keys, transactional outbox/inbox tables, and receipt-driven finality. Redis/BullMQ coordinates work but is never the financial source of truth.

The largest migration risk is not TypeScript itself. It is accidentally creating two writers or treating a quote, prepared transaction, browser broadcast, or queue acknowledgement as a fill. The cutover must maintain a single writer per aggregate and retain the repository's existing rule that only authoritative chain evidence can confirm LIVE execution.

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
| Wallet connection UX and proof | Own initially | Consume immutable wallet account ID; may later own chain-proof verification | Connection proof never authorizes a transaction. |
| Browser transaction signing | Serve client/UI | Construct intent and verify exact signed/broadcast transaction | Neither server stores wallet private keys. |
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
Browser/wallet -> Laravel session + CSRF -> Laravel BFF
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
| Runtime | Node.js 24 LTS, pinned to an exact patched image | Node 24 is the current LTS line; production should remain on an LTS runtime. See the official [Node.js release schedule](https://nodejs.org/en/about/previous-releases). |
| Language | TypeScript 6.x, strict ESM | Stable, broadly compatible compiler API/tooling during the TypeScript 7 transition. Re-evaluate TypeScript 7 after all lint/test/schema tools support its native compiler/API. See the official [TypeScript 7 transition notes](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/). |
| HTTP API | Fastify with TypeBox/JSON Schema request and response contracts | Fastify natively supports schema validation/serialization and TypeScript type providers. Schemas are trusted source code, never accepted from users. See [Fastify validation](https://fastify.dev/docs/latest/Reference/Validation-and-Serialization/). |
| Database | PostgreSQL; Kysely plus `pg` | PostgreSQL supplies transactions, row locks, constraints, advisory locks, JSONB, and reliable numeric types. Kysely stays close to explicit SQL, supports strict typing and transactions, and does not hide lock-sensitive behavior. See [Kysely getting started](https://kysely.dev/docs/getting-started). |
| Queue/schedules | Redis + BullMQ | Supports delayed/repeatable jobs, concurrency, recovery, and horizontal workers. Its worst case is at-least-once, so database idempotency remains mandatory. See [BullMQ semantics](https://docs.bullmq.io/) and [idempotent jobs](https://docs.bullmq.io/patterns/idempotent-jobs). |
| Runtime schemas | TypeBox/JSON Schema for transport; explicit domain constructors for invariants | One schema can drive Fastify validation, OpenAPI, and generated Laravel/client types. Transport validation is not a substitute for domain validation. |
| Logging | Pino structured JSON with secret redaction | Fastify integration, low overhead, stable correlation fields, machine-queryable logs. |
| Telemetry | OpenTelemetry traces and metrics; export via OTLP | Propagates scan/order/receipt correlation across Laravel, engine, Redis, PostgreSQL, RPC, and provider calls. JavaScript traces and metrics are stable according to [OpenTelemetry JS](https://opentelemetry.io/docs/languages/js/). |
| Tests | Vitest, Testcontainers PostgreSQL/Redis, HTTP/provider contract fixtures, property-based tests where valuable | Unit tests alone cannot validate row locks, constraints, outbox claims, or duplicate delivery. |
| Packaging | `pnpm` workspace with a committed lockfile | Separates deployable apps and shared contracts without creating unrelated repositories initially. |
| Deployment | Separate API, worker, and scheduler/dispatcher process roles from one immutable image | Allows independent scaling and least-privilege DB credentials while shipping one versioned artifact. |

Do not use Redis balances, in-memory position state, JavaScript floating-point values, or BullMQ job completion as financial truth.

### 4.2 Suggested repository layout

Place the engine beside Laravel in the same repository initially so contracts and characterization fixtures can change atomically:

```text
trading-engine/
  package.json
  pnpm-lock.yaml
  tsconfig.json
  eslint.config.js
  vitest.config.ts
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

Domain code must not import Fastify, BullMQ, a provider SDK, or concrete database classes. Chain and provider implementations satisfy application ports. This keeps qualification/risk/strategy state machines testable with deterministic clocks and recorded fixtures.

### 4.3 Core domain aggregates

Use explicit aggregates and state machines rather than one generic JSON status record:

- `MarketAsset` and `MarketPair`: canonical chain/network/address identity, decimals, provenance.
- `ScanRun` and `MarketObservation`: provider calls, observed-at/received-at, freshness, raw-payload digest, normalized facts.
- `SecurityAssessment`: provider, network, policy version, findings, freshness, fail-open/fail-closed applicability.
- `Opportunity`: qualification snapshot, user/strategy/policy version, state transitions.
- `ExecutionIntent`: PAPER or LIVE, actor, amount, limits, confirmation mode, idempotency key.
- `Order` and `ExecutionAttempt`: quote, reservation, signing, broadcast, receipt, terminal failure.
- `Fill`: simulated or chain-proven quantity, price, fee, provenance.
- `Position`: inventory lots and lifecycle derived from fills, not merely a status flag.
- `LedgerAccount`, `LedgerTransaction`, and `LedgerEntry`: append-only double-entry balances.
- `StrategyInstance`: immutable strategy parameters attached to a position/order.
- `RiskDecision`: policy version, input digest, decision, reason codes, expiry.

PAPER and LIVE share the same opportunity, strategy, risk, order, fill, and position vocabulary. They use different execution adapters and clearly discriminated evidence. A `SimulatedFill` can never satisfy a LIVE accounting transition.

## 5. Database ownership and integration

### 5.1 Recommended topology

Use one PostgreSQL cluster initially, with separate schemas and credentials:

- `control.*`: Laravel-owned identity, settings, future subscriptions, Telegram connections, and UI audit.
- `trading.*`: engine-owned market, opportunity, order, ledger, position, risk, transaction, and outbox data.
- `read_model.*`: projections written by the engine or a dedicated projector and readable by Laravel.

Laravel migrations own only `control.*`; engine migrations own only `trading.*` and its projections. Database roles enforce this boundary:

- Laravel web role: read/write `control.*`, read-only approved `read_model.*`, no write access to `trading.*`.
- Engine API/worker role: read required identity/config views, write `trading.*`, no mutation of Laravel identity/subscription tables.
- Migration roles: DDL rights only for their owned schema.

This delivers low operational overhead without shared writes. Separate physical databases remain a later option once event contracts and projections make the split safe.

### 5.2 Source-of-truth mapping

| Current Laravel tables | Target owner | Migration disposition |
|---|---|---|
| `users`, auth/session tables | Laravel | Keep. Publish only stable user/tenant IDs and entitlement facts. |
| Future subscription/billing tables | Laravel | Keep in control schema; engine consumes versioned entitlements. |
| `application_settings`, `setting_audits` | Laravel for intent; engine for active policy snapshot | Keep UI/audit. Engine stores the exact activated risk/provider config version. |
| `user_trading_preferences`, `paper_strategy_settings` | Laravel for editable preference; engine for applied snapshot | Version changes and synchronize via idempotent commands/events. |
| `connected_wallets`, `wallet_connection_challenges` | Laravel initially | Keep connection proof. Engine stores an immutable reference plus canonical network/account snapshot for each order. |
| `token_scans`, `token_scan_histories` | Engine | Backfill normalized observations and retain legacy ID mapping. |
| `trade_opportunities`, `trade_opportunity_events` | Engine | Backfill aggregate and ordered events; engine becomes sole writer at cutover. |
| `paper_wallets`, `paper_positions`, `paper_position_snapshots` | Engine | Convert balances to ledger entries and positions/fills; reconcile totals before cutover. |
| `solana_swap_attempts`, `ethereum_swap_attempts` | Engine | Preserve encrypted intent/evidence and state history; do not re-submit migrated attempts. |
| `live_positions` and Ethereum accounting tables | Engine | Preserve immutable chain evidence, review identity, code hashes, and eligibility versions. |
| `system_activities` | Split | Control-plane activity remains Laravel; trading operations become structured engine events/telemetry. |
| Laravel `jobs`/`cache` | Per service | Laravel keeps its queues. Engine gets separate Redis namespaces and database idempotency records. |

### 5.3 Trading schema requirements

- Use UUIDv7/ULID identifiers generated by the authoritative service; retain `legacy_source` and `legacy_id` during migration.
- Use `timestamptz` in UTC and store both `observed_at` and `received_at` for provider facts.
- Store token/native quantities as base-unit integers in `numeric(78,0)` (or a justified tighter bound) plus decimals. Never use binary floating point for money, amounts, prices, fees, balances, or P&L.
- Store prices/rates as fixed-point numerics with explicit scale and quote currency.
- Enforce unique idempotency keys for commands, orders, provider submissions, transaction hashes by network, and consumer events.
- Enforce legal states with check constraints plus transition code. Add an optimistic `version` column to aggregates.
- Use `SELECT ... FOR UPDATE` or serializable transactions for reservation and ledger settlement. Do provider/RPC calls outside long database transactions, then re-lock and revalidate version/lease before publication.
- Make ledger entries append-only and balanced. Corrections are compensating transactions, never updates to historical entries.
- Preserve raw provider/chain evidence by encrypted object storage or compressed JSONB with a digest, retention policy, and access audit. Normalized columns remain queryable.
- Replace ambiguous SOL-specific column names with chain-neutral base-unit names during conversion, not via silent reinterpretation.

### 5.4 Outbox, inbox, and consistency

Every state transition that must notify another process inserts an outbox row in the same PostgreSQL transaction. A dispatcher claims unpublished rows with `FOR UPDATE SKIP LOCKED`, publishes them, and records delivery metadata. Consumers insert `event_id` into an inbox table in the same transaction as their projection/update; a duplicate event becomes a no-op.

Redis/BullMQ delivery is at-least-once in failure scenarios. Therefore:

- BullMQ `jobId`/deduplication reduces duplicate work but is not the idempotency guarantee.
- Business idempotency is enforced by PostgreSQL unique keys and current aggregate state.
- Outbox events remain replayable independently of queue retention.
- Failed events enter an observable retry/dead-letter workflow with manual replay that preserves the original event ID.

### 5.5 Backfill and cutover method

Because existing users/PAPER positions are test data and there are no completed production LIVE trades, the recommended default is to create a fresh normalized engine schema, import only reusable deterministic fixtures, and retain a read-only snapshot of the Laravel test dataset for comparison. If the team elects to carry any existing records forward, use this cutover procedure for each aggregate family:

1. Define canonical mapping and invariants.
2. Backfill into engine tables with legacy IDs and source checksums.
3. Reconcile row counts, balances, open positions, terminal statuses, and evidence hashes.
4. Shadow-read and compare API projections.
5. Pause only that workflow's Laravel writer.
6. Apply a final high-water-mark delta.
7. Enable the engine writer and Laravel read model.
8. Retain legacy tables read-only for an agreed rollback/audit window.

Never dual-write PAPER balances, order states, or positions from Laravel and the engine. A rollback switches the whole aggregate writer, not individual records.

## 6. APIs and events between Laravel and the engine

### 6.1 API style and authentication

Use versioned JSON REST for commands/queries and versioned asynchronous events for facts. Publish OpenAPI for HTTP and JSON Schema/AsyncAPI-compatible definitions for events. Generate PHP DTOs or validate responses in Laravel; do not pass untyped provider payloads through the boundary.

The engine should be on a private network. Authenticate Laravel using mTLS where available plus a rotating service credential. User-scoped calls carry a short-lived Laravel-signed assertion with:

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

### 6.4 Webhook alternative

If Laravel cannot consume the engine queue directly, the engine may deliver outbox events to a Laravel webhook. Sign `timestamp + method + path + raw_body` with a rotating HMAC key, reject stale timestamps, store event IDs before processing, and retry with backoff. HTTPS is mandatory. A successful HTTP response acknowledges delivery only; it does not redefine the trading result.

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

### 8.1 Shared decision pipeline

```text
observation -> security assessment -> qualification -> opportunity
-> user/strategy policy -> risk decision -> execution intent
-> order -> attempt -> fill -> position -> ledger/projection
```

The common pipeline ensures SIGNAL, CONFIRM, and AUTO use the same qualification and risk vocabulary. The executor is selected only after an authorized intent exists.

### 8.2 PAPER semantics

PAPER must remain incapable of broadcasting a chain transaction. Its adapter should:

- Obtain an executable quote when possible, including route, depth, slippage, provider timestamp, and quote expiry.
- Apply configurable simulated latency, gas/network fees, protocol fees, price impact, and failure/rejection rules.
- Produce a `SimulatedFill` with explicit provenance and confidence; fall back to mark-based valuation only for display, not a pretend fill.
- Post balanced virtual ledger entries and update lots/positions atomically.
- Support full and partial exits so take-profit tiers can sell a defined quantity rather than only arm a floor.
- Keep stop loss, take profit, protected floor, and trailing stop as separate typed rules with a deterministic precedence policy.

Migrate the current behavior exactly first: stop loss, protection level 1, protection level 2, later full exit. Introduce partial take-profit and true trailing stops only as separately reviewed behavior changes after parity.

### 8.3 LIVE semantics

LIVE should use a monotonic state machine similar to:

```text
requested
  -> risk_reserved
  -> quote_prepared
  -> signing_claimed
  -> signing_armed
  -> submitted
  -> confirmed
  -> inventory_reconciled

terminal alternatives:
rejected | expired | cancelled_before_broadcast | failed_on_chain | unsupported
```

Rules:

- Quote/preparation is not a fill.
- Wallet handoff is not a broadcast.
- A transaction hash is not success.
- A successful receipt at the configured finality is the earliest execution confirmation.
- Inventory evidence determines acquired/sold quantity; the quote does not.
- An armed/uncertain signing claim is never automatically reissued. Reconcile first.
- A submitted transaction cannot be cancelled in the database as if it never existed.
- Every provider call has timeout, retry classification, circuit-breaker/budget controls, and redacted evidence.
- LIVE entry cannot launch until the matching LIVE exit, inventory, and emergency-control path exists for that network/token class.

### 8.4 Strategy and risk controls

Required hierarchy:

- Platform kill switch.
- Network kill switch.
- Execution-mode kill switch.
- User kill switch.
- Strategy kill switch.
- Provider degradation block.

Risk limits should include per-order amount, daily notional, concurrent exposure, per-asset exposure, per-network exposure, minimum liquidity, maximum price impact/slippage, observation/security freshness, gas ceiling, token eligibility, and cooldown. Decisions store the effective values and policy version.

The kill switch prevents new exposure and new signing payloads. It cannot undo an already broadcast chain transaction, so reconciliation and protective close policy must continue while entry is disabled.

### 8.5 Copy trading

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

### 9.2 Transaction and key safety

- Maintain non-custodial browser signing unless a separately approved custody project changes the threat model.
- Never request, transmit, store, or log seed phrases/private keys.
- Encrypt prepared transaction payloads and sensitive provider evidence with envelope encryption; separate encryption keys from application data.
- Display network, asset, amount, recipient/router, maximum slippage, fee/gas bound, and expiry before wallet handoff.
- Re-verify the signed/broadcast transaction against the server-owned intent. Do not rely only on wallet UI.
- Use Content Security Policy, secure cookies, CSRF protection on Laravel BFF routes, dependency scanning, and restricted third-party scripts on signing pages.

### 9.3 Service and data security

- Private engine ingress; deny public access by default.
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

## 10. Migration phases and acceptance criteria

### Phase 0 — Baseline, contracts, and safety freeze

Work:

- Document current state transitions from PHP and convert existing PHPUnit/browser tests into behavior tables and provider fixtures.
- Define network IDs, amount types, error codes, HTTP/event schemas, idempotency semantics, and ownership matrix.
- Add explicit capability reporting so the UI distinguishes PAPER, manual LIVE buy, opportunity LIVE buy, LIVE sell, and accounting support.
- Decide PostgreSQL deployment and migrate production off SQLite if SQLite is used beyond local/test environments.

Acceptance:

- Every current trading table and writer has an owner and cutover plan.
- Golden fixtures cover Solana/Ethereum qualification, PAPER exits, exact transaction binding, receipts, and Ethereum inventory evidence.
- No UI or API can imply that generic/automatic LIVE is enabled.
- Kill-switch behavior and incident contacts/runbooks are documented and exercised in a non-production environment.

### Phase 1 — Engine foundation, no trading authority

Work:

- Scaffold TypeScript API/worker/scheduler, PostgreSQL schema, Kysely migrations, Redis/BullMQ, telemetry, secrets, and CI.
- Implement command inbox, outbox dispatcher, event inbox, structured errors, leases, and health endpoints.
- Connect Laravel BFF with service authentication and contract tests.

Acceptance:

- Duplicate commands/events produce one state change in forced crash/retry tests.
- PostgreSQL integration tests exercise locks, constraints, ledger balance, outbox recovery, and competing workers.
- Trace/correlation IDs cross Laravel, engine, queue, database, and mocked providers.
- Engine has no provider credentials or route capable of creating a trade in production.

### Phase 2 — Shadow market scanning and qualification

Work:

- Port provider clients and normalization, starting with Solana new-token scanning, then momentum, then Ethereum.
- Add per-provider budgets, recorded fixtures, freshness, provenance, and security policy.
- Run the engine read-only beside PHP and compare normalized observations/opportunities without exposing engine decisions to customers.

Acceptance:

- Agreed parity for discovered/qualified/rejected assets over a representative observation window; every difference has a reason code.
- Ethereum security coverage is explicit, tested, and fails closed for prospective LIVE opportunities.
- Provider 429/timeout/malformed/wrong-asset/stale-data tests pass.
- Engine scanning cannot debit a PAPER wallet or create LIVE signing material.

### Phase 3 — PAPER engine cutover

Work:

- Implement immutable strategy snapshots, simulated orders/fills, double-entry virtual ledger, positions, valuations, and current stop/protection behavior.
- Prefer fresh engine test wallets/positions. If selected PAPER test data is retained, backfill it with legacy mapping and reconciled balances.
- Switch Laravel dashboard/history/close/approve paths to engine APIs/projections for a pilot cohort, then all users.

Acceptance:

- Opening balances + ledger entries reconcile exactly to closing balances for every user/network.
- Existing PHP strategy/reliability/close test vectors pass in TypeScript, including duplicate delivery, lock loss, stale data, and cross-user races.
- No user can have duplicate funded open inventory for the same strategy/network/asset unless the new model explicitly permits lots.
- Rollback can switch the entire PAPER writer without merging divergent balances.
- PHP PAPER scheduler/writers are disabled only after the engine is authoritative.

### Phase 4 — Manual LIVE parity

Work:

- Port Solana Jupiter and Ethereum 0x preparation, exact intent verification, expiry, browser handoff, submission reporting, and receipt reconciliation.
- Preserve encrypted payloads, uncertain-broadcast recovery, and Ethereum accounting evidence.
- Add complete inventory and sell/close workflows before broad availability.

Acceptance:

- On test networks/forked environments, altered signer/fee payer/recipient/value/calldata/network/message is rejected.
- Repeated prepare/submit/report/reconcile requests never broadcast twice or create duplicate fills.
- Prepared/submitted attempts never appear as successful trades.
- Reorg, replaced/dropped/expired transaction, provider outage, and process-crash scenarios converge to a documented state.
- Entry, inventory, user-visible position, sell/close, fees, and final ledger reconcile end to end per network.
- Production rollout begins allowlisted, amount-capped, confirm-only, and with network kill switches tested.

### Phase 5 — Opportunity-driven LIVE

Work:

- Generalize the Ethereum reservation/revalidation/signing-claim design to engine aggregates.
- Add Solana opportunity linkage only after Solana inventory/exit support exists.
- Add automated protective monitoring, but retain CONFIRM as the only LIVE entry mode until an explicit risk review approves AUTO.

Acceptance:

- LIVE rechecks entitlement, preference, kill switch, market/security freshness, limits, wallet, and opportunity version before signing publication.
- The same opportunity cannot reserve or execute twice under concurrent approvals.
- Every confirmed fill has receipt/finality/inventory evidence and balanced ledger entries.
- Protective exits are tested against gaps, illiquidity, provider outage, and partial fills; the UI states that on-chain stops cannot guarantee price.
- Emergency halt blocks new signing material within the agreed SLO while continuing reconciliation of already submitted transactions.

### Phase 6 — Base and BNB expansion

Work:

- Add network configuration and providers one capability at a time.
- Run discovery and PAPER before LIVE.
- Establish independent token eligibility, finality, router/allowance, gas, and inventory policies.

Acceptance per network:

- Canonical identity, decimals, native/wrapped asset, RPC failover, finality, explorer, and provider support tests pass.
- Discovery/PAPER runs for an agreed soak period with no cross-network address/cache collisions.
- LIVE buy and sell quotes, exact intent validation, receipts, inventory, ledger, and reorg recovery pass.
- Security/risk owner explicitly signs off; enabling one EVM network cannot enable another.

### Phase 7 — Laravel retirement and hardening

Work:

- Remove disabled writers/schedules and provider secrets from Laravel.
- Replace legacy trading models with typed engine clients/read projections.
- Archive or remove legacy tables after audit/rollback retention expires.
- Load, chaos, restoration, incident, key-rotation, and disaster-recovery exercises.

Acceptance:

- Database permissions prove Laravel cannot mutate engine-owned trading data.
- No Laravel scheduler/job/controller/service can call a market/execution provider for a retired workflow.
- Backup restore reproduces ledger/positions and resumes outbox/inbox safely.
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

Keep `resources/js/solana-wallet.js`, `ethereum-wallet.js`, and `ethereum-opportunity.js` initially, adapted to Laravel BFF contracts. Browser wallet integration belongs in the web client; transaction construction, authoritative validation, and state transitions belong in the engine.

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

These decisions materially affect design or risk and should be resolved before their referenced phase:

1. **Production database today:** Is production using SQLite or another connection despite the default configuration? PostgreSQL is required before multi-instance financial writers.
2. **Deployment platform:** Separate containers, VMs/systemd, or a managed orchestrator? The answer determines service discovery, mTLS, scaling, and secret delivery, but not domain boundaries.
3. **Repository shape:** Keep `trading-engine/` in this monorepo initially (recommended) or create a separate repository with a versioned contracts package?
4. **Wallet connection ownership:** Keep proof/linking in Laravel permanently, or move chain-specific proof verification to the engine after the first cutover?
5. **EVM address sharing:** May the same address be linked to multiple users? If not, enforce by `(network,address)`; if yes, define proof, visibility, and abuse policy explicitly.
6. **Custody:** Confirm the platform will remain non-custodial. Any server-side key custody is a separate security/compliance architecture, not an implementation detail.
7. **LIVE product boundary:** Is manual wallet execution the target, or should confirm-first opportunity execution become the standard? Is LIVE AUTO intentionally out of scope?
8. **Network order:** Base before BNB is recommended after Ethereum/Solana parity. Provider availability, target users, and liquidity/security coverage may change that order.
9. **Provider contracts:** Which providers permit the intended storage, redistribution, automated trading, call rate, and production networks? Define primary/fallback per capability.
10. **Security fail policy:** Which checks are mandatory for PAPER, confirmed LIVE, and any future automatic LIVE on each network? Unknown security should remain a reasoned state, not a boolean default.
11. **PAPER realism:** Preserve mark-based legacy results, or adopt executable-quote/fees/latency immediately? Recommendation: migrate legacy behavior first, then introduce a versioned simulator.
12. **Strategy semantics:** Define partial take-profit percentages, protection-floor rules, true trailing stops, precedence, gap behavior, and whether strategy edits affect only new positions.
13. **Portfolio accounting:** FIFO, LIFO, or specific-lot; realized/unrealized P&L quote currency; gas allocation; fee-on-transfer/rebase handling; and tax/reporting expectations.
14. **Finality policy:** Required confirmations/finality per Solana, Ethereum, Base, and BNB; handling for reorgs after provisional UI display.
15. **Read-model latency:** What lag is acceptable for dashboard, Telegram notifications, kill-switch acknowledgement, and trade history?
16. **Entitlements/subscriptions:** Plans and limits do not exist today. Define which engine commands require which entitlement and how revocation affects open positions.
17. **Copy trading:** Is it actually in product scope? If yes, complete legal/compliance review and define consent, privacy, allocation, delay, and leader-failure behavior before schema design.
18. **Admin review model:** Can Ethereum bytecode/token eligibility review generalize to other EVM networks, and which roles may approve/reconsider it?
19. **Retention and privacy:** Define retention for raw provider payloads, wallet addresses, signed transaction material, RPC evidence, logs, and audit events.
20. **Disaster recovery:** Set RPO/RTO, backup cadence, restore verification, Redis-loss behavior, RPC/provider outage posture, and criteria for globally halting entry.
21. **Rollout governance:** Identify the owner who can enable each capability/network, required soak period, amount/user caps, and rollback authority.

The safest first implementation milestone is therefore not a LIVE trade. It is a read-only TypeScript foundation that reproduces existing Solana/Ethereum scan decisions from recorded and shadow data, with durable contracts, idempotency, and observability. That milestone reduces architectural uncertainty without putting balances or chain transactions at risk.
