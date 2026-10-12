# Phase 2.5 Production Deployment Readiness Audit

Date: 2026-10-11
Reviewed branch: `feature/live-wallet-connection`
Reviewed commit: `a62d700df3274b528cc0a9443d40213573b01a11`
Scope: read-only source and test audit; no production service was contacted.

## Executive summary

**Overall verdict: READY WITH CONDITIONS.**

The current TypeScript build can be deployed against production schema 006 with
`PAPER_ENTRY_ENABLED=false` and `PAPER_MARKET_MONITORING_ENABLED=false`. That
combination requires schema 006, performs read-only compatibility checks before
the API listens and before worker leadership starts, does not construct the
shadow monitor, and does not run migrations automatically.

The code is not ready to have its new capabilities enabled against the current
production schema:

- engine-owned PAPER entry is blocked until migration 007 is applied and its
  actual schema objects are verified;
- shadow monitoring is blocked until migrations 007 and 008 are applied, a
  non-empty user allowlist is configured, and the allowlisted task inventory is
  inspected;
- TypeScript financial monitoring authority is not implemented by the shadow
  path and remains blocked by design. Laravel must remain the authoritative
  market-observation producer.

There is no identified source-code blocker to deploying the disabled build.
Operational prerequisites remain: confirm the Supabase backup/restore posture,
verify schema 006 and production feature flags, use a direct or session-affine
PostgreSQL endpoint for migrations and leadership, and prove that the deployed
artifact is this reviewed commit.

The independent verification supplied for this audit passed 324/324 tests:
schema compatibility 3/3, PAPER entry integration 21/21, unit 270/270, contract
30/30, plus typecheck, ESLint, and production build. This audit did not rerun
those tests and did not connect to production.

## 1. Production database compatibility

### 1.1 Compatibility matrix

| Production schema | Feature state | Result | Evidence |
|---|---|---|---|
| 006 | PAPER entry off, shadow monitoring off | Compatible | `src/infrastructure/database/schema-compatibility.ts:55-67`; `tests/integration/schema-compatibility.test.ts:56-70` |
| 006 | PAPER entry on | Incompatible; startup fails closed | `schema-compatibility.ts:88-95`; `tests/unit/schema-compatibility.test.ts:66-81` |
| 007 | PAPER entry on, monitoring off | Compatible | `schema-compatibility.ts:88-97`; `tests/unit/schema-compatibility.test.ts:83-92` |
| 007 | Shadow monitoring on | Incompatible; startup fails closed | `schema-compatibility.ts:98-110`; `tests/unit/schema-compatibility.test.ts:94-112` |
| 008 | PAPER entry on, monitoring on, valid allowlist | Compatible | `tests/integration/schema-compatibility.test.ts:87-95` |
| 008 | Monitoring on, empty allowlist | Configuration load fails before startup; compatibility also fails closed | `src/config/env.ts:292-305`; `schema-compatibility.ts:107-109` |

The required version is feature-driven rather than simply “latest”: version 006
is the baseline, PAPER entry raises it to 007, and monitoring raises it to 008
(`schema-compatibility.ts:55-67`). Compatibility checks both Kysely migration
history and actual tables, columns, functions, indexes, constraints, and trigger
objects (`schema-compatibility.ts:120-139,174-323`). A migration-history row alone
therefore cannot make a damaged schema look compatible.

### 1.2 Prerequisites

The production database must already contain migrations 001 through 006 in
order. Specifically:

- migration 007 references `paper_positions(id)`, which is created by migration
  005, and assumes the schema-006 financial baseline;
- migration 008 alters `paper_position_monitoring_tasks`, so 007 must be applied
  first;
- the migration runner requires the `kysely_migration` history to agree with the
  ordered migration files;
- the connection role needs DDL permission to create/alter tables, constraints,
  indexes, a PL/pgSQL function, and a trigger;
- `DATABASE_URL` must use a direct or session-affine connection. The migration
  lock and workflow leadership are session advisory locks. Transaction-mode
  pooling is explicitly unsupported (`README.md:55-58`).

The dedicated database CLI reads only `DATABASE_URL`, `DATABASE_SSL`,
`DATABASE_CA_CERT_BASE64`, and optional `DATABASE_MAX_CONNECTIONS`
(`apps/migrate/src/main.ts:7-25`; `src/config/env.ts:389-397`). It does not need
Redis or application/authentication secrets.

### 1.3 Migration 007

`007_paper_market_monitoring`:

- creates `paper_position_monitoring_tasks` with one row per engine
  `paper_positions` row and an `ON DELETE RESTRICT` foreign key
  (`migrations/007_paper_market_monitoring.ts:3-35`);
- limits tasks to Solana mainnet, enforces pending/processing/completed state,
  ties processing state to lease fields, constrains safe error/status fields,
  and validates market values (`:37-60`);
- adds partial indexes for due pending tasks and expired processing leases
  (`:63-73`);
- contains no `INSERT`, `UPDATE`, backfill, or financial-table alteration.

It is additive in its `up` direction. Existing wallets, ledger evidence,
positions, lifecycle decisions, exits, settlements, outbox records, and
opportunities are not changed. The integration test preserves pre-existing
wallet/ledger evidence and confirms zero monitoring tasks after 006→007
(`tests/integration/schema-compatibility.test.ts:98-159`).

Migration 007 is a prerequisite to **enabling** new PAPER entry. It need not
precede deployment of the code if PAPER entry remains disabled, because schema
006 is explicitly compatible. It must precede the first enabled entry because
the entry handler creates the task in the same transaction as the financial
entry (`src/application/handlers/execute-paper-entry-command-handler.ts:168-186`).
Without 007, startup fails before serving requests; it should never be treated
as a recoverable per-command failure.

### 1.4 Migration 008

`008_paper_market_shadow_observations`:

- adds `next_attempt_sequence integer NOT NULL DEFAULT 1` and a positive-value
  check to each monitoring task (`migrations/008_paper_market_shadow_observations.ts:3-15`);
- creates append-only `paper_market_shadow_observations`, with position foreign
  key, request/timing/provider fields, bounded market/error fields, and a unique
  `(position_id, attempt_sequence)` fence (`:17-100`);
- adds three diagnostic indexes (`:102-118`);
- adds a function and trigger rejecting update/delete of shadow observations
  (`:120-135`);
- contains no financial DML and does not backfill observations.

It is additive to financial data. If tasks already exist, PostgreSQL gives them
attempt sequence 1; therefore the safest rollout keeps PAPER entry disabled and
applies 007 and 008 together before any task can exist. The tested 006→007→008
upgrade leaves existing wallet/ledger evidence byte-for-byte equivalent and
leaves both monitoring tables empty (`tests/integration/schema-compatibility.test.ts:98-197`).

Migration 008 is mandatory before enabling shadow monitoring. The runtime checks
for the table, the sequence column, all expected columns, the unique constraint,
three indexes, trigger, and function (`schema-compatibility.ts:253-323`).

### 1.5 Locking and transaction risks

The repository runner:

1. reserves one database connection;
2. obtains session advisory lock `7421901`;
3. runs Kysely `migrateToLatest()` in filename order;
4. releases the advisory lock and destroys the CLI-owned pool
   (`src/infrastructure/database/migrate.ts:15-70`).

With the installed Kysely/PostgreSQL implementation, pending migrations run in a
transaction because PostgreSQL supports transactional DDL. From schema 006, the
approved CLI applies both 007 and 008 to latest; it has no “apply only 007”
option. A failure should roll back the migration transaction, but an operator
must still inspect migration history and actual objects after any connection
loss rather than assume the outcome.

DDL still takes locks:

- the new foreign keys briefly lock the referenced `paper_positions` table;
- migration 008's `ALTER TABLE` takes a strong lock on the new monitoring-task
  table;
- indexes are ordinary (not concurrent), but are created on new empty tables in
  the approved sequence.

These locks should be short if entry remains disabled and 007/008 are applied
together. Run during a low-activity window, stop on unexpected duration, and do
not enable entry between the two migrations. Transactional DDL also means locks
may be held until the complete migration transaction commits.

### 1.6 Data-integrity and rollback assessment

`up` for 007/008 does not mutate current financial records. Their `down`
directions are destructive: 008 drops all shadow samples and its sequence
column, while 007 drops every monitoring task (`007...ts:76-78`;
`008...ts:138-149`). They must not be used as an automatic application rollback.

The safe rollback for an application or activation problem is:

1. disable the affected feature flags;
2. restart/reload the affected runtime safely;
3. roll back application code if required;
4. leave the additive schema in place;
5. restore a database backup only for demonstrated database corruption that
   cannot be corrected forward.

### 1.7 Safe schema verification

Use both migration history and catalog objects. The exact application check is
read-only (`schema-compatibility.ts:120-139,174-323`). Before and after migration,
run the read-only SQL in section 9 and record:

- migration names and timestamps;
- `to_regclass` results for both new tables;
- expected columns, constraints, indexes, trigger, and function;
- row counts in all financial tables before and after;
- zero task/shadow rows immediately after migration while entry is disabled.

Do not manually insert Kysely migration-history rows or create individual schema
objects to force readiness green.

## 2. Supabase backup and recovery

### 2.1 Required backup set

Before production DDL, obtain two independent recovery layers where the current
Supabase plan permits:

1. **Managed recovery point:** confirm a current daily backup or PITR recovery
   point in Supabase Dashboard → Database → Backups. Availability and retention
   are plan-dependent. Supabase documents daily managed backups for paid plans
   and PITR as a separate option; restoration makes the project inaccessible for
   a period, so it is not a zero-downtime rollback. See
   [Supabase Database Backups](https://supabase.com/docs/guides/platform/backups).
2. **Operator-held logical export:** capture roles, schema, and data separately
   using `supabase db dump`. The CLI default schema dump does not contain data or
   custom roles, so one dump is not complete. See
   [Supabase CLI db dump](https://supabase.com/docs/reference/cli/v0/supabase-db-dump)
   and Supabase's
   [automated backup example](https://supabase.com/docs/guides/deployment/ci/backups).

For this engine, the logical backup must include:

- all user-created `public` tables and their data, including `kysely_migration`
  and `kysely_migration_lock`;
- functions, constraints, indexes, and triggers;
- custom roles/grants needed to restore access;
- every financial, lifecycle, command-inbox, evaluation, and outbox table—not
  only migrations 007/008.

If the same Supabase project stores Auth or Storage data, account for it
separately. Supabase states that database backups contain Storage metadata but
not the stored objects, and custom-role passwords are not present in daily
backup downloads. Those limitations do not change the engine migration but do
affect whole-project disaster recovery.

### 2.2 Backup completeness

Minimum evidence before migration:

- Dashboard shows a recovery point before the migration window and its retention
  covers the planned validation period;
- roles, schema, and data dump files exist, are non-empty, stored outside the
  deployment directory, and have recorded SHA-256 hashes;
- the schema dump contains `paper_wallets`, `paper_positions`, ledger tables,
  lifecycle/settlement tables, Kysely migration tables, financial functions,
  and triggers;
- the data dump contains representative `COPY`/data sections and expected table
  counts;
- the only strong completeness proof is a restore rehearsal into an isolated
  non-production database/project followed by catalog, count, and integrity
  checks. Merely producing a dump file is insufficient.

Restore rehearsal is a separate, explicitly authorized mutation of a
non-production target. It must never target production.

### 2.3 Recovery limitations

- Restoring a managed backup/PITR point causes downtime and can discard every
  legitimate transaction after the chosen recovery point.
- A physical/PITR restore is broad; it is not a table-level undo for 007/008.
- A logical restore requires roles, schema, and data in the correct order and
  careful privilege handling.
- Storage objects, external Hostinger/HostGator configuration, secrets, queues,
  and webhook delivery state are not restored merely by restoring PostgreSQL.
- Restoring PostgreSQL can put it behind Laravel's SQLite projections or external
  callbacks. Reconciliation is required before resuming entry/lifecycle work.
- Supabase notes that physical/PITR backups may not be directly downloadable;
  use CLI/`pg_dump` for an operator-held logical copy. See
  [logical backups with physical backups](https://supabase.com/docs/guides/troubleshooting/download-logical-backups).

### 2.4 What feature flags can roll back

Flags can stop new behavior without deleting evidence:

- Hostinger `PAPER_ENTRY_ENABLED=false` rejects new engine entry commands;
- Hostinger `PAPER_MARKET_MONITORING_ENABLED=false` prevents construction of the
  monitor and all new shadow claims/provider requests;
- Laravel `TRADING_ENGINE_PAPER_ENTRY_INTEGRATION_ENABLED=false` prevents new
  canary entry intents/submissions;
- Laravel `TRADING_ENGINE_PAPER_FINANCIAL_LIFECYCLE_ENABLED=false` stops new
  engine-financial observation submission/projection;
- existing Laravel lifecycle flags can return their own enrollment boundary to
  the configured canary list;
- every LIVE integration flag must remain false.

Flags do not erase already committed entry, wallet, ledger, position, task,
shadow, outbox, or projection rows. Do not reverse migrations to roll back a
runtime incident; keep the evidence for audit and recovery.

## 3. Hostinger deployment compatibility

### 3.1 Combined startup

The Hostinger entry loads environment/config synchronously, creates its logger,
then starts telemetry and the managed combined runtime without top-level await
(`apps/hostinger/src/main.ts:10-28`). The combined runtime starts:

1. API with `runMigrations: false`;
2. worker with `runMigrations: false`;
3. then reports combined startup
   (`apps/hostinger/src/runtime.ts:33-70`).

The API opens its pool, constructs handlers, optionally migrates only when
`runMigrations === true`, checks schema compatibility, and only then calls
`listen()` (`apps/api/src/runtime.ts:40-49,122-131`). The worker follows the same
opt-in migration rule, checks compatibility, and only then starts PostgreSQL
leadership (`apps/worker/src/runtime.ts:50-62,81-127`). Because API and worker
receive the same config, an enabled feature with missing schema is rejected by
the API check before it listens.

Standalone API and worker main files omit `runMigrations`, which now means false
(`apps/api/src/main.ts:16-21`; `apps/worker/src/main.ts:16-21`). Scheduler
explicitly passes false (`apps/scheduler/src/runtime.ts:18-26`). The only approved
migration path is `pnpm run migrate:production`, which invokes
`dist/apps/migrate/src/main.js` (`package.json:18-23`).

The README statement that deployment runs migrations before startup is an
operator sequencing requirement, not runtime automation (`README.md:46-49`).
Hostinger build/start alone never upgrades the database.

### 3.2 Disabled monitoring guarantee

`PAPER_MARKET_MONITORING_ENABLED` defaults to false (`src/config/env.ts:292-296`;
`.env.example:48-52`). When false, the worker returns the normal workflow poller
without calling the monitoring runtime constructor
(`apps/worker/src/runtime.ts:91-125`). Therefore a disabled deployment does not:

- create monitoring timers;
- claim or retire tasks;
- contact DexScreener;
- insert shadow observations.

Imports alone do not start the poller. The monitoring runtime also checks the
flag before capability checks or construction (`src/workers/paper-market-monitor-runtime.ts:57-74`).

### 3.3 Database, TLS, and Redis

The pool uses the configured maximum, application name, and either no TLS or TLS
with `rejectUnauthorized: true`; a configured CA is passed to node-postgres
(`src/infrastructure/database/client.ts:557-586`). Do not weaken TLS. The
production Supabase CA and `DATABASE_SSL` settings must remain correct.

`REDIS_URL` is still syntactically required during full config load
(`src/config/env.ts:317-318`), but the current API/workflow/monitoring production
path does not instantiate Redis and readiness reports `not_required`. Keep the
variable valid; do not interpret it as an active dependency.

### 3.4 Health behavior

- `/v1/health/live` is authenticated and only proves the process can respond
  (`src/interfaces/http/health-routes.ts:84-107`).
- `/v1/health/ready` concurrently pings PostgreSQL and performs read-only schema
  inspection (`:109-133`).
- It returns 503 if either the ping fails or schema is incompatible, preserves
  the JSON contract, reports Redis `not_required`, and exposes detected/required
  schema versions in response headers when inspection succeeds (`:135-179`).
- A successful ping with incompatible schema reports PostgreSQL `up` but overall
  `unavailable`. A connection failure reports PostgreSQL `down`.

Startup compatibility failure occurs before listening, so in that case logs—not
the endpoint—are the primary evidence.

### 3.5 Leadership and shutdown

The worker uses one dedicated PostgreSQL connection/session for a two-key
advisory lock, validates that the session is monitored, and starts pollers only
after acquisition (`src/workers/postgres-workflow-leader.ts:48-71,89-139,164-190`).
Connection loss races poller completion, stops the old poller, avoids lock queries
on a known-lost session, and returns to bounded acquisition attempts
(`:191-269`).

Monitoring shares the workflow leader. Standbys do not construct the monitor.
Before each monitoring cycle and before success or failure persistence, the
leader session is revalidated (`apps/worker/src/runtime.ts:91-125`;
`src/workers/paper-market-monitor.ts:117-129,147-190,225-248`). PostgreSQL lease
owner, database-time lease expiry, and attempt sequence provide a second fence.

Managed shutdown is idempotent and bounded. SIGINT/SIGTERM starts runtime close;
the configured deadline ultimately terminates a hung process
(`src/infrastructure/runtime/process-lifecycle.ts:62-126`). The monitor aborts an
active HTTP request, awaits the cycle, and then releases only its own leases
(`src/workers/paper-market-monitor-poller.ts:59-92`;
`paper-market-monitor.ts:207-219`). Runtime startup rejects unsafe provider
budgets where maximum request time is not less than both shutdown timeout and
lease duration (`paper-market-monitor-runtime.ts:200-209`).

Residual operational condition: the PostgreSQL endpoint must preserve session
affinity, and `DATABASE_MAX_CONNECTIONS` must leave capacity for the dedicated
leader session plus normal API/worker queries on every Hostinger instance.

## 4. Laravel integration

### 4.1 Relevant flags

All defaults are false/empty in `.env.example:112-137` and are mapped in
`config/services.php:168-189`:

- `TRADING_ENGINE_ENABLED`
- opportunity export/projection, evaluation consumption, and decision boundary
- `TRADING_ENGINE_PAPER_DECISION_INTEGRATION_ENABLED`
- `TRADING_ENGINE_PAPER_ENTRY_INTEGRATION_ENABLED`
- `TRADING_ENGINE_PAPER_ENTRY_CANARY_USER_IDS`
- `TRADING_ENGINE_PAPER_FINANCIAL_LIFECYCLE_ENABLED`
- lifecycle integration, authoritative mode, general rollout, and lifecycle
  canary IDs
- four LIVE integration flags.

### 4.2 Entry prerequisites and routing

Laravel routes an opportunity into engine financial entry only when the engine
and entry integration are enabled, the opportunity is Solana PAPER/AUTO, it has
a user, and that user is in the explicit entry canary list
(`app/Services/TradingEngine/TradingEnginePaperEntryIntegration.php:24-33,96-112`).
There is no entry general-rollout switch and an empty/invalid list routes nobody.

Before creating an entry intent, Laravel blocks an already-open legacy position
for the same user, chain, and token (`:44-67,86-94`). If that duplicate guard
blocks the engine-routed path, it returns without falling back to legacy
execution. Intent payload/hash/idempotency are persisted, then the queue job is
dispatched after commit (`:69-83`).

The job rechecks both engine and entry flags, validates the stored payload hash,
uses a stable unique job ID, retries with bounded backoff, and records stable
engine identifiers (`app/Jobs/SubmitTradingEnginePaperEntry.php:15-38,40-111`).
The engine route requires service auth scope and an idempotency key
(`trading-engine/src/interfaces/http/paper-entry-command-routes.ts:27-64`). The
handler validates source opportunity/evaluation identity, a passed policy,
immutable market snapshot, freshness, balance, and no open engine position, all
inside one transaction (`execute-paper-entry-command-handler.ts:39-186`).

### 4.3 Fallback and rolling-deployment behavior

After a TypeScript `WOULD_ENTER` decision:

- if the opportunity is inside the engine entry boundary, Laravel creates or
  reuses the engine entry intent and does **not** execute a legacy PAPER buy;
- otherwise, it follows the existing legacy `TradeExecutionManager::executePaper`
  path (`app/Services/TradingEngine/TradingEnginePaperDecisionIntegration.php:25-87`).

This preserves existing non-canary behavior but makes flag order important:

- TypeScript entry enabled while Laravel entry integration is off is inert; no
  Laravel entry commands are produced and eligible users continue through the
  existing legacy boundary.
- Laravel entry integration enabled while TypeScript entry is off is unsafe for
  the canary: Laravel routes away from legacy, the engine rejects the command,
  and the intent retries/fails rather than falling back.
- Laravel code that can project entry events and its SQLite projection migrations
  must be deployed before the first engine entry. Required migrations include
  `2026_10_07_180000_create_trading_engine_paper_entry_projections.php` and, for
  financial lifecycle, `2026_10_07_234711_create_trading_engine_paper_financial_lifecycle_projections.php`.
- Laravel queue processing is required for entry submission and webhook
  projection. The scheduled PAPER tracker remains required for authoritative
  financial observations while shadow mode is observational.

No new Laravel source change is required for disabled TypeScript deployment,
provided these already-reviewed Laravel migrations/code are actually deployed.
That is a production inspection condition, not something this audit can prove.

### 4.4 Entry projection and existing positions

`paper.entry.executed.v1` is accepted into Laravel's authenticated inbox and
queued for projection only when entry integration is enabled
(`app/Http/Controllers/TradingEngineWebhookController.php:24-83`). The projector
revalidates every source/user/evaluation/idempotency/hash relation, creates
separate engine wallet/position/state projections, marks the opportunity executed
with executor `trading-engine-paper`, and deliberately leaves the legacy
`paper_position_id` null
(`TradingEnginePaperEntryProjector.php:25-123,125-217`).

Existing Laravel `PaperPosition` rows are not converted. Legacy positions remain
in the Laravel tracker. Engine financial projections are tracked separately by
`TradingEnginePaperFinancialTracker`, which is invoked before legacy positions in
the existing scheduled tracker (`app/Console/Commands/TrackPaperPositions.php:118-145`;
`app/Services/TradingEngine/TradingEnginePaperFinancialTracker.php:22-89`).

Lifecycle general rollout does not bypass the independent PAPER entry canary.
It only enrolls newly created, owned, Solana legacy `PaperPosition` rows and still
requires engine/integration/authoritative gates and matching ownership
(`TradingEnginePaperLifecycleEnrollment.php:75-101`).

## 5. Shadow monitoring activation boundaries

### 5.1 Proven boundaries

- Default is disabled and the allowlist is empty (`trading-engine/.env.example:48-59`).
- Enabling with a missing, empty, duplicate, malformed, or over-limit allowlist
  fails config loading (`src/config/env.ts:127-146,292-305`).
- Claiming is filtered in PostgreSQL by joining tasks to open
  `paper_positions.control_plane_user_id`, uses `FOR UPDATE SKIP LOCKED`, and
  returns no work for an empty list
  (`paper-position-monitoring-repository.ts:180-235`). Closed-task retirement is
  filtered by the same allowlist (`:146-177`).
- Successful and failed samples are inserted and task rescheduling happens in
  one transaction. Persistence requires processing state, matching lease owner,
  lease expiry strictly after PostgreSQL `clock_timestamp()`, and matching
  attempt sequence (`:237-388,455-472`). The unique position/attempt constraint
  prevents duplicate committed samples.
- Position state is row-locked and rechecked before persistence; a closed
  position produces `discarded_closed`, not an observed sample
  (`:247-265,326-345`).
- Leadership is revalidated before persistence, while lease/attempt fencing
  protects against a stale former leader.
- The shadow repository writes only monitoring tasks and the append-only shadow
  table. It has no lifecycle, wallet, ledger, settlement, or outbox dependency.
- Provider failures become safe bounded outcomes/backoff. No response body,
  authorization material, or provider credential is stored. DexScreener requests
  are capped at 30 addresses and 4 MiB with connection/total timeouts
  (`dexscreener-paper-market-provider.ts:8-16,49-175,245-315`).
- Migration 007 performs no backfill. Tasks are created only by a **new**
  engine-owned entry in the same transaction (`execute-paper-entry-command-handler.ts:168-186`).

### 5.2 Coexistence with Laravel

Shadow monitoring can run alongside Laravel because the TypeScript cycle only
fetches provider data and persists diagnostics. It never calls either PAPER
observation command handler and never enqueues a lifecycle event. Laravel remains
the sole source of authoritative financial observations
(`README.md:288-316`). Independent provider calls may select data at different
times, so shadow-versus-Laravel price differences are measurements, not decision
conflicts.

### 5.3 Conditions blocking user-1 activation

Before setting `PAPER_MARKET_MONITORING_ENABLED=true`, all of these must pass:

1. migration 008 and every checked schema capability exist;
2. `PAPER_MARKET_MONITOR_CANARY_USER_IDS` contains exactly the intended positive
   user IDs, initially only `1`;
3. a read-only inventory shows which open user-1 tasks would become immediately
   due;
4. no unintended user ID appears in the claimable set;
5. the PostgreSQL URL is direct/session-affine and connection capacity is safe;
6. Hostinger can reach DexScreener over HTTPS;
7. timeout/request-budget/lease/shutdown values satisfy the runtime budget;
8. Laravel's authoritative tracker remains healthy;
9. operators can observe leadership acquisition, monitoring startup, provider
   failures, task state, and append-only samples.

The user canary is not position-scoped. Every open user-1 position with a task is
eligible. Positions created before migration 007 have no task and are not
backfilled; positions created after 007 by engine entry receive a task even while
monitoring is disabled. Therefore the pre-activation task inventory is a hard
operational stop condition.

Readiness proves config/schema compatibility, not DexScreener reachability or
that a monitoring cycle has produced a sample. Activation evidence must include
the unique startup log and PostgreSQL task/sample queries.

## 6. Environment and secrets checklist

Never print values for variables marked secret. Compare names/presence through
the hosting control panels and retrieve values only from the approved secret
manager.

### 6.1 Hostinger: required existing variables

| Category | Variables | Audit rule |
|---|---|---|
| Runtime | `NODE_ENV`, `SERVICE_NAME`, `SERVICE_VERSION`, `HOST`, `PORT`, `LOG_LEVEL`, `SHUTDOWN_TIMEOUT_MS` | `NODE_ENV=production`; preserve platform `PORT`; no hard-coded port |
| PostgreSQL | `DATABASE_URL`, `DATABASE_MAX_CONNECTIONS`, `DATABASE_SSL`, `DATABASE_CA_CERT_BASE64` | Secret URL/CA; TLS verification must remain enabled; direct/session-affine endpoint |
| Reserved Redis config | `REDIS_URL`, `REDIS_PREFIX` | Still required to parse config; current runtime does not connect |
| Service auth | `SERVICE_AUTH_ISSUER`, `SERVICE_AUTH_AUDIENCE`, `SERVICE_AUTH_PUBLIC_KEY_BASE64`, max-age and clock-tolerance settings | Public key is sensitive configuration even though not private; issuer/audience must match Laravel |
| Laravel webhook | `LARAVEL_WEBHOOK_URL`, `LARAVEL_WEBHOOK_HMAC_SECRET`, `LARAVEL_WEBHOOK_TIMEOUT_MS` | URL must target production HTTPS endpoint; HMAC secret must match Laravel and never be logged |
| Workflow | outbox/evaluation batch, poll, idle maximum, claim TTL, leader retry | Preserve verified production values unless separately approved |
| Telemetry | standard `OTEL_*` settings | Must not block startup/shutdown or leak secrets |

### 6.2 Hostinger: new PAPER/monitoring variables

- `PAPER_ENTRY_ENABLED=false` initially.
- `PAPER_OPENING_BALANCE_NATIVE`, `PAPER_ENTRY_NOTIONAL_NATIVE`, and
  `PAPER_ENTRY_INTENT_MAX_AGE_SECONDS` must match the approved PAPER policy before
  entry is enabled.
- `PAPER_FINANCIAL_LIFECYCLE_ENABLED` must retain its current deliberate state;
  do not change it as a side effect of deploying shadow code.
- `PAPER_OBSERVATION_MAX_AGE_SECONDS` must retain the tested value.
- `PAPER_MARKET_MONITORING_ENABLED=false` initially.
- `PAPER_MARKET_MONITOR_CANARY_USER_IDS=` initially; set to `1` only in the
  separately authorized activation stage.
- monitoring interval, batch, provider timeouts, request budget, maximum backoff,
  and lease duration should begin at reviewed defaults unless a separate capacity
  review approves changes (`.env.example:53-59`).

### 6.3 HostGator Laravel variables

Preserve the existing endpoint/auth/webhook variables and inspect only flag
values. Relevant rollout variables are listed in section 4. Initially:

- keep `TRADING_ENGINE_PAPER_ENTRY_INTEGRATION_ENABLED=false` through schema and
  disabled-code deployment;
- keep the entry canary list unchanged/empty until explicit entry authorization;
- do not change financial lifecycle or existing lifecycle flags incidentally;
- keep all LIVE flags false.

### 6.4 Dangerous combinations

| Combination | Result |
|---|---|
| Engine entry on + schema 006 | Hostinger startup fails closed |
| Monitoring on + schema below 008 | Hostinger startup fails closed |
| Monitoring on + empty/invalid allowlist | Config load fails before startup |
| Laravel entry on + engine entry off/unreachable | Canary is routed away from legacy; queued entry retries/fails |
| Engine entry on + Laravel entry off | No new engine entry commands; safe but inert |
| Financial lifecycle flags mismatched | Entry may exist without timely observation/projection; do not start a canary |
| Transaction-mode PostgreSQL pooler | Session advisory-lock semantics are unsafe/unsupported |
| Provider budget ≥ lease or shutdown budget | Monitor refuses to start, while API/workflow can remain healthy |
| Any LIVE flag true | Outside this rollout; immediate stop condition |

## 7. Staged deployment plan

No stage below is authorized by this document. Each requires an operator, change
record, and explicit approval. Do not combine stages E and F.

### Stage A — Pre-deployment inspection and backup

**Preconditions**

- reviewed commit is present on the deployment branch/artifact;
- independent tests/build evidence is attached to the change record;
- PAPER entry and shadow monitoring are disabled on both platforms;
- all LIVE flags are false;
- a quiet deployment window and operators for Hostinger, HostGator, and Supabase
  are available.

**Actions**

1. Record current Hostinger and Laravel release identifiers without printing
   secrets.
2. Run section 9 read-only schema/history/count queries.
3. Confirm managed backup/PITR availability and create the approved pre-change
   recovery point.
4. Produce roles/schema/data logical dumps and record hashes.
5. If policy requires it, complete an isolated restore rehearsal.

**Expected evidence**

- production is exactly schema 006;
- financial counts/balances and migration history are captured;
- backup timestamp precedes change and recovery procedure is assigned;
- flags and LIVE-off state are recorded.

**Stop conditions**

- missing/unknown migration, damaged schema object, unexpected 007/008 object;
- no recoverable backup or no restore authority;
- active LIVE flag; unknown release; unclean artifact; insufficient DB privileges;
- database URL is transaction-mode/non-session-affine.

**Recovery**: none required because inspection is read-only. Correct the
precondition and reschedule.

### Stage B — Production schema migration prerequisites

**Preconditions**

- Stage A complete;
- entry and monitoring still off;
- no in-progress engine deployment;
- backup evidence and low-activity window confirmed.

**Actions**

1. Build/verify the migration artifact for the reviewed commit.
2. From the secured production release environment, invoke the dedicated
   database CLI once. From schema 006 it applies 007 and 008 to latest.
3. Capture only migration names/status; never print environment variables.
4. Run the post-migration read-only catalog/count checks.

**Expected evidence**

- 007 and 008 have `Success` history rows;
- actual capabilities match schema 008;
- financial evidence counts/aggregates are unchanged;
- both new tables contain zero rows;
- flags remain off.

**Stop conditions**

- lock wait exceeds the approved window;
- migration error or connection loss/ambiguous result;
- unexpected task/shadow rows or changed financial evidence;
- history/object mismatch.

**Recovery**

- stop; do not edit history and do not run `down`;
- inspect transaction outcome and catalogs read-only;
- if rolled back, fix the cause and rerun the idempotent migration CLI only with
  approval;
- if committed but the app is still disabled, leave schema in place and diagnose;
- restore the database only for proven corruption and after accepting the
  restore-point data-loss window.

### Stage C — Deploy code with monitoring and entry disabled

**Preconditions**

- preferred: Stage B shows schema 008; alternatively schema 006 is supported only
  while both new engine flags remain false;
- Hostinger env diff reviewed without exposing values;
- build output contains `dist/apps/hostinger/src/main.js` and migration CLI.

**Actions**

1. Deploy the reviewed TypeScript release.
2. Keep `PAPER_ENTRY_ENABLED=false` and
   `PAPER_MARKET_MONITORING_ENABLED=false`.
3. Do not run standalone API/worker/scheduler in parallel with the combined
   runtime.

**Expected evidence**

- server listening, workflow worker started, leadership acquired by one process,
  combined runtime started;
- no shadow-monitor-start log, no DexScreener requests, no task claims;
- no migration output during application startup.

**Stop conditions**

- schema incompatibility/startup fatal; repeated leadership loss; unexpected
  monitoring log/provider traffic; readiness failure.

**Recovery**

- keep flags off, roll application release back, leave additive schema intact;
- if startup failed because flags accidentally enabled, restore the prior flag
  values and restart before considering code rollback.

### Stage D — Readiness and compatibility verification

**Preconditions**: Stage C process is stable; Laravel integration remains on its
pre-deployment settings.

**Actions**

1. Run Laravel `trading-engine:verify` without `--noop`.
2. Confirm liveness/readiness, PostgreSQL `UP`, Redis `NOT_REQUIRED`, service
   version, and Node version.
3. Inspect authenticated readiness headers for detected/required schema.
4. Inspect Hostinger logs and Supabase activity/lock state.

**Expected evidence**: readiness 200; detected schema matches actual catalog;
required version is 006 while both flags are off (even if detected is 008); one
workflow leader; no monitor startup.

**Stop conditions**: any 503, PostgreSQL down, required version unexpected,
multiple active leaders, monitoring startup, auth/webhook mismatch.

**Recovery**: keep features off; distinguish connectivity from schema using the
response/logs; roll back code only if the disabled release itself is defective.

### Stage E — Separately authorized PAPER entry verification

This stage creates financial PAPER evidence and is **mutating**.

**Preconditions**

- schema 007/008 verified;
- Laravel entry projection migrations and current code verified;
- HostGator scheduler and queue healthy;
- opportunity export/evaluation/decision path healthy;
- no open legacy or engine position for the test user's intended token;
- financial lifecycle flag states are deliberately coordinated;
- monitoring remains false;
- exact canary user and notional approved.

**Actions**

1. Enable Hostinger `PAPER_ENTRY_ENABLED` first and restart; verify readiness
   requires/accepts 007.
2. Confirm the engine endpoint is healthy while Laravel entry remains off.
3. Configure Laravel entry canary to only user 1, then enable Laravel entry
   integration and reload configuration/queue workers.
4. Execute one separately approved, bounded canary opportunity.
5. Trace intent, command idempotency, wallet/ledger/order/fill/position/task,
   outbox, Laravel inbox, and projections.

**Expected evidence**: exactly one entry, balanced ledger, one open engine
position, exactly one monitoring task, one entry outbox event/projection, no
legacy `PaperPosition`, and no LIVE action.

**Stop conditions**: duplicate/open legacy position, stale evaluation, queue
backlog, mismatched identifiers/hashes, more than one position/task, unexpected
wallet delta, missing projection, monitoring/provider activity.

**Recovery**: disable Laravel entry integration first, then engine entry; retain
all evidence; do not invent a fallback position or delete/replay with a new
idempotency key. Use existing recovery/reconciliation paths only after diagnosis.

### Stage F — Future, separately authorized user-1 shadow monitoring

**Preconditions**

- Stage E complete and stable;
- migration 008 capability verified;
- read-only query shows the complete set of claimable user-1 tasks and no
  unintended positions;
- authoritative Laravel tracker remains healthy;
- DexScreener egress and rate budget approved;
- logs/queries/dashboard are ready.

**Actions**

1. Set allowlist to user 1 only while monitoring is still false; validate config.
2. Enable monitoring in a separate Hostinger change and restart.
3. Observe one leader start, first task claim, first append-only sample, task
   reschedule, provider latency, and Laravel's independent observation.

**Expected evidence**: only user-1 task claims; unique attempt sequences; shadow
rows only; no lifecycle/outbox/financial table delta attributable to shadow;
Laravel remains authoritative.

**Stop conditions**: unexpected user/position claimed, monitor starts on standby,
lease/attempt fence errors, sustained provider failure, sample without task,
financial/lifecycle/outbox write, or Laravel tracker degradation.

**Recovery**: set monitoring false and restart. Existing tasks/samples remain
auditable; expired/owned leases recover safely. Do not delete shadow evidence or
reverse migrations.

## 8. Failure and rollback scenarios

| Scenario | Expected protection | Safest response |
|---|---|---|
| Migration failure | Advisory serialization and transactional DDL; runtime never auto-migrates | Stop; inspect history/catalog; keep flags off; do not edit history or run down; rerun only after cause is resolved |
| Hostinger startup failure | Fatal startup, cleanup of created resources, no implicit schema upgrade | Restore flags if accidentally enabled; inspect safe logs; roll back code while retaining additive schema |
| Readiness 503 | PostgreSQL and schema are checked separately; headers appear when schema inspection succeeds | If PostgreSQL down, restore connectivity. If up but incompatible, disable requiring feature or apply planned migration—never auto-upgrade during restart |
| Supabase connectivity failure | API readiness fails; worker cannot lead/poll; PostgreSQL remains source of truth | Disable new Laravel entry submission, preserve queues/idempotency keys, restore DB connectivity, then allow normal retries |
| Leadership loss | Dedicated-session loss stops pollers; standby retries; claims/leases recover | Observe takeover; do not start extra workers manually; investigate repeated loss/session pooling |
| Monitoring provider failure | Safe error codes, bounded timeout/body, exponential capped backoff; no financial effect | Leave Laravel authoritative; disable shadow monitoring if sustained; inspect task/sample diagnostics without replaying financial commands |
| Laravel-to-engine failure | Unique job/idempotency and retry; engine-routed entry does not fall back after intent creation | Stop new entry routing, preserve intent and key, restore connectivity, retry through existing job semantics only |
| Partial deployment: schema 008 + old code | Additive schema is ignored by old code | Keep flags off; finish or roll back application deployment; leave schema |
| Partial deployment: new code + schema 006 + flags off | Explicitly compatible | Complete staged rollout; do not enable entry/monitoring |
| Partial deployment: feature flag on before schema | Startup fails closed | Turn flag off and restart; schedule migration properly |
| Duplicate entry/event | Laravel intent uniqueness/hash checks, engine command inbox, open-asset constraint, unique financial identities, inbox/projector idempotency | Reuse the exact original idempotency key/payload; never generate a replacement or manually insert evidence |
| Shadow duplicate/takeover race | Advisory leadership plus task owner/expiry/attempt fence and unique `(position, attempt)` | Let transaction reject stale completion; inspect logs and leases; do not delete or renumber attempts |
| Future shadow data accidentally treated as authority | No current call path exists | Immediate stop and rollback; this would indicate unreviewed code/config beyond the audited commit |

## 9. Proposed verification commands

Nothing in this section was executed against production. Commands are grouped by
effect. Substitute approved paths only; never paste credentials into shell
history, logs, or tickets.

### 9.1 Read-only: operator Mac/repository

```bash
cd /path/to/memescanner
git branch --show-current
git rev-parse HEAD
git status --short
git show --stat --oneline HEAD
git ls-remote origin refs/heads/feature/live-wallet-connection

cd trading-engine
node --version
pnpm --version
test -f dist/apps/hostinger/src/main.js
test -f dist/apps/migrate/src/main.js
```

`git ls-remote` reads the remote ref without changing the local repository. The
`test -f` checks do not build or alter artifacts.

### 9.2 Read-only: Supabase SQL editor

Migration history:

```sql
select name, timestamp
from public.kysely_migration
order by name;
```

Core schema capabilities:

```sql
select
  to_regclass('public.paper_wallets') as paper_wallets,
  to_regclass('public.paper_positions') as paper_positions,
  to_regclass('public.paper_position_monitoring_tasks') as monitoring_tasks,
  to_regclass('public.paper_market_shadow_observations') as shadow_observations,
  to_regprocedure('public.assert_paper_ledger_transaction_balanced()') as ledger_balance_function,
  to_regprocedure('public.reject_paper_financial_evidence_mutation()') as financial_append_only_function,
  to_regprocedure('public.reject_paper_market_shadow_observation_mutation()') as shadow_append_only_function;
```

Migration-008 objects (safe before or after migration; absent objects return no
rows/null):

```sql
select table_name, column_name, data_type, is_nullable, column_default
from information_schema.columns
where table_schema = 'public'
  and table_name in (
    'paper_position_monitoring_tasks',
    'paper_market_shadow_observations'
  )
order by table_name, ordinal_position;

select conname, contype, pg_get_constraintdef(oid) as definition
from pg_constraint
where conrelid in (
  to_regclass('public.paper_position_monitoring_tasks'),
  to_regclass('public.paper_market_shadow_observations')
)
order by conname;

select tgname, pg_get_triggerdef(oid) as definition
from pg_trigger
where tgrelid = to_regclass('public.paper_market_shadow_observations')
  and not tgisinternal;
```

```sql
select indexname, indexdef
from pg_indexes
where schemaname = 'public'
  and (
    indexname like 'paper_position_monitoring_tasks%'
    or indexname like 'paper_market_shadow_observations%'
  )
order by indexname;
```

Financial evidence snapshot (safe both before and after migration):

```sql
select 'paper_wallets' as relation, count(*) from public.paper_wallets
union all select 'paper_ledger_transactions', count(*) from public.paper_ledger_transactions
union all select 'paper_ledger_entries', count(*) from public.paper_ledger_entries
union all select 'paper_positions', count(*) from public.paper_positions;

select account, direction, count(*) as entries, sum(amount_native) as total_native
from public.paper_ledger_entries
group by account, direction
order by account, direction;

select state, count(*) as positions
from public.paper_positions
group by state
order by state;
```

New-table counts and allowlisted task inventory (run only after 007/008 exist):

```sql
select 'paper_position_monitoring_tasks' as relation, count(*)
from public.paper_position_monitoring_tasks
union all
select 'paper_market_shadow_observations', count(*)
from public.paper_market_shadow_observations;

select
  p.control_plane_user_id,
  p.id as position_id,
  p.asset_address,
  p.state as position_state,
  t.monitoring_state,
  t.next_observation_due_at,
  t.lease_owner,
  t.lease_expires_at,
  t.next_attempt_sequence,
  t.consecutive_failure_count,
  t.last_error_code
from public.paper_position_monitoring_tasks t
join public.paper_positions p on p.id = t.position_id
where p.control_plane_user_id = '1'
order by t.next_observation_due_at, p.id;
```

Leadership/activity diagnostics:

```sql
select pid, application_name, client_addr, backend_start, state,
       query_start, wait_event_type, wait_event
from pg_stat_activity
where application_name like 'meme-scanner-trading-engine%'
order by backend_start, pid;

select pid, locktype, classid, objid, objsubid, granted
from pg_locks
where locktype = 'advisory'
  and classid = 1297302527::oid
  and objid = 1::oid;
```

Access to `client_addr` or other sessions may be limited by the Supabase role.
That is an observability limitation, not permission to elevate privileges.

### 9.3 Read-only: HostGator Laravel

```bash
cd /home1/newconci/memescanner
git rev-parse HEAD
git status --short
/opt/cpanel/ea-php84/root/usr/bin/php artisan trading-engine:verify
/opt/cpanel/ea-php84/root/usr/bin/php artisan app:health
/opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:list
```

Inspect only non-secret flag values with non-interactive Tinker:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php artisan tinker --execute='dump(collect(config("services.trading_engine"))->only(["enabled", "paper_decision_integration_enabled", "paper_entry_integration_enabled", "paper_entry_canary_user_ids", "paper_financial_lifecycle_enabled", "paper_lifecycle_integration_enabled", "paper_lifecycle_authoritative_enabled", "paper_lifecycle_general_rollout_enabled", "paper_lifecycle_canary_user_ids", "live_decision_integration_enabled", "live_preparation_enabled", "live_recovery_enabled", "solana_live_integration_enabled"])->all());'
```

Do not use `config:show services.trading_engine`; it may expose endpoint or key
configuration. `trading-engine:verify` without `--noop` performs authenticated
health/version reads only. `--noop` creates durable command/outbox evidence and
is therefore mutating.

### 9.4 Read-only: Hostinger

Hostinger may not expose a terminal for this Web App. Use:

- deployment/runtime logs;
- the authenticated health/version requests through Laravel
  `trading-engine:verify`;
- the readiness schema-version headers through an approved authenticated client;
- Supabase read-only SQL above.

If an SSH shell is actually available, these are local/deployment inspections:

```bash
cd /path/to/deployed/trading-engine
node --version
pnpm --version
test -f dist/apps/hostinger/src/main.js
test -f dist/apps/migrate/src/main.js
```

Do not run `env`, `printenv`, or commands that echo database/auth/webhook values.

### 9.5 Backup actions: read production, write operator backup files

These commands are not ordinary read-only inspection: they read the production
database and create sensitive local files. Run only in the approved backup stage
with `SUPABASE_DB_URL` supplied through a secure mechanism.

```bash
supabase db dump --db-url "$SUPABASE_DB_URL" -f roles.sql --role-only
supabase db dump --db-url "$SUPABASE_DB_URL" -f schema.sql
supabase db dump --db-url "$SUPABASE_DB_URL" -f data.sql --data-only --use-copy
shasum -a 256 roles.sql schema.sql data.sql
```

Store these outside the repository with restricted permissions. Do not commit or
attach them to logs/tickets.

### 9.6 Mutating production commands—listed, not authorized

Database migration:

```bash
cd /path/to/deployed/trading-engine
pnpm run migrate:production
```

This applies every pending migration through 008 and is **mutating**. The CLI has
no dry-run mode. Feature-flag edits, Hostinger restarts/redeploys, Laravel
`config:cache`, `queue:restart`, scans, no-op commands, PAPER entries, and backup
restores are also mutating operational actions. None was executed or authorized
by this audit.

## 10. Go/no-go assessment

| Area | Assessment | Conditions/blockers |
|---|---|---|
| Deploy reviewed code with PAPER entry and monitoring disabled | **READY WITH CONDITIONS** | Verify artifact/remote SHA, schema 006+, production flags, backup posture, TLS/session-affine DB, and disabled startup evidence |
| Apply migrations 007/008 while features are disabled | **READY WITH CONDITIONS** | Current backup/recovery point, low-activity window, DDL privileges, direct/session-affine connection, pre/post catalog and financial evidence checks |
| Existing API/workflow behavior on schema 006 | **READY** | Both new engine flags remain false; baseline 006 objects intact |
| Engine-owned PAPER entry | **READY WITH CONDITIONS** | 007 objects applied; coordinated Hostinger-first/Laravel-second flags; user-1 canary; Laravel projection schema/queues; duplicate-position precheck; monitoring still off |
| User-1 shadow monitoring | **READY WITH CONDITIONS** | 008 objects, valid allowlist, inspected user-1 task inventory, provider egress, safe budgets, one leader, Laravel tracker healthy, operational observation |
| Existing legacy PAPER positions | **READY** | No migration backfill; Laravel tracker and lifecycle behavior remain deployed and healthy |
| LIVE trading | **BLOCKED / OUT OF SCOPE** | All LIVE flags must remain false; no activation is authorized |
| TypeScript authoritative market monitoring and financial decisions | **BLOCKED** | Shadow path intentionally cannot call lifecycle/financial/outbox code; authority-transfer design, implementation, tests, and rollout are not part of Phase 2.5 |

## Final disposition

### 1. Overall readiness verdict

**READY WITH CONDITIONS** for a disabled-code deployment and additive schema
upgrade. **Not authorized** for feature activation by this report.

### 2. Critical blockers

There is no identified code blocker to deploying with both new engine flags off.
The following are hard blockers to later actions:

- **PAPER entry:** production migration 007 is not yet applied; Laravel projection
  schema/queue health and coordinated canary flags must be verified.
- **Shadow monitoring:** production migration 008 is not yet applied; a valid
  allowlist and exact user-1 task inventory are mandatory.
- **Any activation:** production backup/restore readiness, direct/session-affine
  PostgreSQL transport, actual production flag state, and deployed artifact SHA
  require operator confirmation.
- **Future financial monitoring authority:** no authority-transfer path exists;
  shadow monitoring must remain observational.
- **LIVE:** remains disabled and outside scope.

### 3. Recommended safest deployment sequence

1. Inspect schema/flags/releases and establish managed plus logical recovery
   evidence.
2. Keep entry/monitoring/LIVE off and apply 007+008 once with the dedicated CLI.
3. Prove actual catalog objects, zero new rows, and unchanged financial evidence.
4. Deploy the reviewed TypeScript artifact with both new flags off.
5. Verify startup, authenticated liveness/readiness, schema headers, one workflow
   leader, and absence of monitoring activity.
6. Under separate authorization, enable engine entry Hostinger-first and Laravel
   second for one user-1 canary.
7. Under another separate authorization, inventory user-1 tasks and activate
   shadow monitoring only for user 1. Keep Laravel authoritative.

### 4. Remaining uncertainties requiring production inspection

- exact Supabase plan, managed-backup/PITR status, latest recovery point, and
  tested restore capability;
- actual schema-006 catalog integrity and production row counts;
- whether the production database URL is direct/session-affine rather than
  transaction-mode pooled;
- available PostgreSQL connection headroom across Hostinger instances;
- exact deployed Hostinger and HostGator commits and actual flag values;
- Laravel projection migrations, queue drains, scheduler, and PAPER tracker
  health in production;
- DexScreener egress/rate behavior from Hostinger;
- exact set of open user-1 tasks that would be claimed at activation;
- whether Hostinger starts more than one app process (correctness is fenced, but
  connection/load expectations depend on topology).

### 5. Exact files created or modified by this audit

- Created: `trading-engine/docs/PHASE-2.5-DEPLOYMENT-READINESS.md`
- No application, migration, test, configuration, secret, or generated file was
  modified.

### 6. Git status

Expected immediately after this report is created:

```text
?? trading-engine/docs/PHASE-2.5-DEPLOYMENT-READINESS.md
```

No commit, push, deployment, production connection, migration, environment
change, PAPER action, or LIVE action was performed.
