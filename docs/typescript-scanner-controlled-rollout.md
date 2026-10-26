# TypeScript Scanner Controlled Rollout

This runbook covers the Laravel-to-TypeScript ownership cutover for dashboard new-token and momentum scans. It is an operator procedure, not authorization to deploy, migrate production, enable scanning, run a scan, or change a production secret.

The default state remains inactive:

- TypeScript: `TOKEN_SCANNING_ENABLED=false` and an empty scanner canary list.
- Laravel: `TRADING_ENGINE_SCANNER_INTEGRATION_ENABLED=false` and an empty scanner canary list.
- Legacy Laravel scanner commands and historical SQLite records remain available.
- LIVE trading remains outside this rollout.

## Ownership boundary

When both sides are enabled for the same explicit PAPER user canary, Laravel only authenticates the user, creates the presentation activity, durably queues the command, and projects authenticated engine events. TypeScript owns discovery, provider validation, qualification, opportunity creation, evaluation intake, and scan completion for that request.

Non-canary users continue through the existing Laravel scanner. A single dashboard request never invokes both paths. Laravel requires engine enablement, scanner integration, opportunity projection, PAPER mode, `trading_enabled`, and an explicit user allowlist match before routing to TypeScript. TypeScript independently requires scanner enablement and its own explicit user allowlist.

## Versioned contracts

Laravel submits `POST /v1/commands/token-scans` with service-auth scope `commands:token-scans:create`, an immutable idempotency key, and schema version 1. The body contains:

- Laravel activity identity and control-plane user identity;
- scanner `new-token` or `momentum`;
- canonical Solana-mainnet or Ethereum-mainnet network ID;
- immutable maximum-chase and momentum-provider-budget policy values.

A valid 202 acknowledgement contains the engine operation ID and scan execution ID. Timeout, malformed acknowledgement, retry exhaustion, and other indeterminate transport outcomes become `acceptance_unknown`; they are not rejection.

The engine emits authenticated, replay-protected `token.scan.completed.v1` or `token.scan.failed.v1` events. Terminal event evidence is authoritative and may reconcile an ambiguous HTTP outcome. The event reports discovered, examined, qualified, opportunity-created, opportunity-existing, skipped, and failed counts plus bounded provider error codes. It contains no provider response body, credential, or queued job payload.

Qualified opportunities use the existing version-1 opportunity command and `opportunity.recorded.v1` event, with source system `meme-scanner-trading-engine` and the scan execution ID. Laravel projects these as user-owned PAPER presentation records linked to the originating activity; it does not execute a legacy PAPER position from the projection.

## Database prerequisites

Apply only through the reviewed migration tooling while both scanner flags are disabled:

- PostgreSQL migration `010_token_discovery_scanning` creates `token_scan_executions` and `token_discoveries`, claim/lease indexes, user/source identity, immutable request evidence, bounded attempts, delayed retry scheduling, structured counters, and per-scan asset uniqueness.
- Laravel migration `2026_10_26_112431_create_trading_engine_scan_requests_table` creates the durable SQLite command-delivery and projection tracker.

Both migrations are additive in their up direction. Do not use their down directions as an application rollback after evidence exists.

PostgreSQL schema version 010 is required before TypeScript scanning can start. Startup verifies migration history, tables, constraints, indexes, the non-null `next_attempt_at` retry fence, and a non-empty scanner canary.

## Provider prerequisites

Before activation, verify through the approved secret/configuration process without printing values:

- `BIRDEYE_API_KEY` is present and authorized for the existing listing and overview endpoints.
- `SOLANA_RPC_URL` is HTTPS and has enough request capacity for holder-account inspection.
- Optional `GOPLUS_ACCESS_TOKEN` has the intended quota; absence is supported only where the provider supports unauthenticated access.
- Hostinger can reach Birdeye, DexScreener, GeckoTerminal, GoPlus, and the Solana RPC endpoint over HTTPS.
- `TOKEN_SCAN_PROVIDER_TIMEOUT_MS` is strictly shorter than `TOKEN_SCAN_LEASE_DURATION_MS`; the initial scanner batch size is exactly one so a claimed request is renewed between provider stages rather than waiting behind another leased request. PostgreSQL connections and provider quotas have reviewed budgets.
- TypeScript and Laravel scanner canary lists identify the same intended users.
- PAPER entry/evaluation/projection flags are deliberately coordinated; no scanner activation implicitly authorizes LIVE.

## HostGator/Hostinger deployment sequence

1. Pause dashboard scan submission and disable the cPanel entries that start `schedule:run` and bounded queue drains. Do not delete cron definitions.
2. Wait for current bounded queue workers to exit. Verify no old-code `queue:work`, `app:queue-drain`, `tokens:scan`, or momentum scanner process remains. Old and new queue-worker code must never consume Stage B/scanner jobs simultaneously.
3. With SQLite writers stopped, create a consistent operator backup outside the release directory. Use SQLite backup semantics or include validated WAL/SHM state, record size and SHA-256, and keep the backup out of source control.
4. Confirm both Laravel and TypeScript scanner flags remain false. Apply the additive Laravel migration and PostgreSQL migration 010 using the approved migration entry points.
5. Verify actual tables, columns, indexes, constraints, migration-history rows, and unchanged historical Laravel activity/opportunity/PAPER records. Stop on any history/catalog mismatch.
6. Deploy the reviewed TypeScript artifact and Laravel code while queue consumption remains paused. Do not start a second standalone API, worker, or scanner scheduler alongside the combined engine runtime.
7. Terminate every old Laravel queue process, issue the normal queue-restart signal, then enable only bounded workers running the new release. Observe the release identifier and one bounded drain.
8. Deploy the Vite assets built from the same reviewed Laravel commit.
9. With scanner flags still false, verify Laravel health, engine authenticated liveness/readiness, detected schema 010, one PostgreSQL workflow leader, no provider requests, no scan claims, and no new scanner rows.
10. Enable the TypeScript scanner first for one approved PAPER user. Restart the engine and confirm readiness now requires schema 010 and the configured canary.
11. Enable Laravel scanner integration and opportunity projection for the same single user, reload approved caches, and restart bounded workers. Resume scheduler and dashboard submission only after all new-code processes are confirmed.
12. A production canary scan is a separate mutating authorization. If approved later, trace exactly one activity, scan request, engine execution, terminal event, structured summary, and each opportunity correlation. Confirm no legacy scanner job or Laravel PAPER position was created for that request.

## Recovery and fencing guarantees

- Laravel persists the scan tracker and database-queue job atomically on the same SQLite connection.
- Command delivery is fenced by attempt number. Stale job callbacks cannot overwrite newer acknowledgement or projection.
- Ambiguous HTTP outcomes retain the original payload, opportunity/scan identities, and idempotency key for safe replay.
- Laravel recovery inspects at most 100 stale requests per scheduled run by default, has a hard maximum of 500, and never retries beyond the configured attempt/recovery bounds.
- TypeScript claims only pending-due or expired-lease executions under the existing PostgreSQL workflow leader. It never holds a database transaction open during provider HTTP.
- TypeScript completion and retry transitions require the exact attempt and lease owner. Retry delays use capped exponential backoff; attempt five is terminal.
- Duplicate API commands, queue delivery, terminal webhooks, and opportunity events are idempotent.
- Provider bodies and credentials are neither persisted nor returned to the browser; only bounded safe codes are projected.

## Failure diagnostics and terminal-event recovery

This diagnostics correction is schema-compatible with the existing PostgreSQL migration 010 and Laravel scanner-request migration. It adds no database migration. Deploy the TypeScript and Laravel changes with scanner submission paused and all old queue/engine workers stopped, then restart only bounded workers from the same reviewed release.

Scanner logs now correlate scan execution and engine operation IDs with the workflow stage, provider, attempt number, safe error classification/code, retryability, delay, HTTP status, timeout flag, database category, terminal outcome, durable counts, and bounded provider-failure codes. Unexpected messages and stack frames are sanitized before logging. Webhooks and dashboard projections still receive only stable codes and structured counts; they never receive exception text, provider bodies, request payloads, credentials, or database URLs.

Permanent authentication, configuration, malformed-response, invalid-data, and unexpected-application failures are not retried automatically. Rate limits, timeouts, service/transport failures, and retryable PostgreSQL connection/transaction/lock/resource failures remain bounded by the existing five-attempt fence. Expired fifth attempts publish one terminal event using durable discovery rows rather than replacing partial progress with zeroes.

### Existing stored scanner event 5479

Recovery of inbox row 5479 is a targeted Laravel projection operation, not a scanner replay. It requires separate production authorization after both codebases and new-code queue workers are deployed. Do not generate another scan, edit SQLite manually, or invent an event identifier.

First resolve the public engine event identifier with a read-only SQLite query against the approved database path:

```bash
sqlite3 -readonly /approved/path/database.sqlite \
  "select id, event_id, event_type, handling_status, handling_attempts, handling_error_code, next_handling_at from trading_engine_event_inbox where id = 5479;"
```

Stop unless the row is token.scan.failed.v1, remains recoverable (stored or due retryable), and its event_id is the expected 26-character identifier. With TRADING_ENGINE_ENABLED=true and scanner integration still deliberately enabled, dispatch only that public identifier:

```bash
php artisan trading-engine:retry-projection 01XXXXXXXXXXXXXXXXXXXXXXXX
```

Replace the placeholder with the read-only query result, not numeric row ID 5479. Let only a bounded new-release queue worker consume the job. If an active lease blocks the command, wait for the configured lease to expire; do not clear it manually. Then verify read-only that the inbox row is projected with one handling attempt, the scanner request is projected, activity 65630 is failed, and the dashboard no longer reports that scan as running. Repeating projection is idempotent, but a second manual dispatch is unnecessary.

## Stop conditions

Stop with scanner flags off if any of the following occurs:

- an old Laravel worker remains while new workers start;
- application and database-queue connections do not resolve to the same SQLite database;
- SQLite backup consistency cannot be established;
- PostgreSQL is below schema 010 or schema capability checks fail;
- TypeScript and Laravel canary boundaries differ;
- more than one workflow leader appears;
- a routed request also starts a Laravel scanner;
- a terminal event belongs to another user, activity, scanner, or chain;
- structured opportunity counts do not match durable correlations;
- raw provider content, secrets, payloads, or internal exception text reaches the browser;
- unexpected PAPER financial or any LIVE mutation occurs.

## Rollback

Disable Laravel scanner routing first so no new TypeScript commands are produced, then disable TypeScript scanning and restart the affected runtimes. Pause queue consumption while changing releases.

Leave the additive PostgreSQL and SQLite tracking tables in place. They contain reconciliation evidence and are safe for old code to ignore. Do not run migration down, delete ambiguous rows, generate new idempotency keys, or replay an engine-owned request through the legacy scanner.

Prefer a forward fix with new-code workers. If application code must roll back, prove the queue contains no new scanner or Stage B job payloads before allowing old code to consume. Existing accepted, ambiguous, or projected engine requests remain evidence requiring reconciliation. Restore SQLite or PostgreSQL only for demonstrated corruption and only after accepting the recovery-point data-loss window.
