# Meme Scanner trading engine — Phase 1

This directory contains the local, non-trading TypeScript foundation approved in
`docs/typescript-trading-engine-migration.md`.

Phase 1 contains infrastructure only:

- a Fastify API;
- independent PostgreSQL workflow worker and scheduler-compatible entry points;
- dedicated PostgreSQL migrations and command/outbox persistence;
- externalizable Redis readiness and reserved coordination infrastructure;
- Ed25519 service-assertion verification;
- signed synthetic webhook delivery;
- structured redacted logs and trace/correlation propagation;
- unit, contract, and integration tests.

It does not contain scanners, provider polling, wallets, blockchain code,
balances, orders, fills, positions, portfolios, or trading execution. It never
opens Laravel's SQLite database.

## Runtime and process model

The workspace pins Node.js 24.21.0 and pnpm 12.6.0. The standalone entry points are
built and started independently:

| Role | Development command | Production-style command |
|---|---|---|
| API | `pnpm dev:api` | `pnpm start:api` |
| Worker | `pnpm dev:worker` | `pnpm start:worker` |
| Scheduler | `pnpm dev:scheduler` | `pnpm start:scheduler` |

All roles use URLs from the environment. Local Docker services can therefore be
replaced later with external PostgreSQL and Redis without changing domain code.
Hostinger is not used or modified by Phase 1.

### Combined Hostinger process

Hostinger Web App hosting can run the API and workflow poller in one Node
process after a production build:

```bash
pnpm build
pnpm start:hostinger
```

The Hostinger application entry file is
`dist/apps/hostinger/src/main.js`. Deployment runs database migrations before
application startup; the process starts the API before the PostgreSQL workflow
worker, owns one OpenTelemetry SDK, and coordinates one shutdown path. The
standalone commands remain available for deployments that supervise roles
separately. All workflow-capable roles compete for one session-scoped PostgreSQL
advisory lock, so only the elected leader polls. Avoid deliberately running
redundant roles because standbys still perform bounded leadership probes.

The workflow advisory lock is session-scoped. `DATABASE_URL` must therefore use a
direct PostgreSQL connection or a session-affine pooler endpoint; transaction-mode
pooling is not a supported workflow-leadership transport. The leader reserves one
connection from its configured PostgreSQL pool for the duration of leadership.

Hostinger must provide the same runtime configuration used by the standalone
roles, including `NODE_ENV`, `HOST`, `PORT`, `DATABASE_URL`, `REDIS_URL`,
`SERVICE_AUTH_ISSUER`, `SERVICE_AUTH_AUDIENCE`,
`SERVICE_AUTH_PUBLIC_KEY_BASE64`, `LARAVEL_WEBHOOK_URL`, and
`LARAVEL_WEBHOOK_HMAC_SECRET`. The optional tuning variables documented in
`.env.example` remain supported. PostgreSQL and Redis are external managed
services; the combined process does not install or embed either dependency.
Never add actual credentials to the repository or Hostinger build logs.

## Docker Desktop or Colima on macOS

Both options expose the Docker API required by Compose and Testcontainers.

- **Colima:** recommended when low idle resource use and a command-line workflow
  are preferred. It runs a small configurable Linux VM. Start with 4 CPUs, 6 GiB
  RAM, and a 40 GiB disk; reduce only after the full suite is stable.
- **Docker Desktop:** convenient GUI, automatic updates, and integrated logs. It
  normally consumes more idle memory and is subject to Docker's licensing terms.

Do not install both for this project unless you deliberately manage Docker
contexts. Confirm the active context before starting services.

## macOS setup — Colima

From a terminal:

```bash
xcode-select --install
brew update
brew install colima docker docker-compose openssl@3
colima start --cpu 4 --memory 6 --disk 40
docker context use colima
docker version
docker compose version
```

If Homebrew does not expose the Compose plugin automatically, add this to
`~/.docker/config.json` while preserving any existing keys:

```json
{
  "cliPluginsExtraDirs": [
    "/opt/homebrew/lib/docker/cli-plugins",
    "/usr/local/lib/docker/cli-plugins"
  ]
}
```

Use the path that exists on the Mac, restart the terminal, and rerun
`docker compose version`.

## macOS setup — Docker Desktop

```bash
xcode-select --install
brew update
brew install --cask docker
brew install openssl@3
open -a Docker
docker context use desktop-linux
docker version
docker compose version
```

Wait until Docker Desktop reports that the engine is running before continuing.

## Node.js and pnpm

Use one Node version manager. Volta is shown because it pins exact tool versions:

```bash
curl https://get.volta.sh | bash
volta install node@24.21.0
corepack enable
corepack prepare pnpm@12.6.0 --activate
node --version
pnpm --version
```

Expected versions are `v24.21.0` and `12.6.0`.

## Local configuration

```bash
cd /path/to/memescanner/trading-engine
cp .env.example .env
mkdir -p .keys
openssl genpkey -algorithm ED25519 -out .keys/laravel-service-private.pem
openssl pkey \
  -in .keys/laravel-service-private.pem \
  -pubout \
  -out .keys/laravel-service-public.pem
openssl pkey \
  -pubin \
  -in .keys/laravel-service-public.pem \
  -outform DER \
  | openssl base64 -A
openssl rand -hex 32
```

Copy the first printed value into `SERVICE_AUTH_PUBLIC_KEY_BASE64` in `.env`.
Copy the random value into `LARAVEL_WEBHOOK_HMAC_SECRET`. The private key is a
local stand-in for Laravel's future service signer and must remain under the
ignored `.keys/` directory. Never commit either private material or `.env`.

The checked-in example credentials for PostgreSQL are local-only. Replace them
for every shared environment.

## Install, start dependencies, migrate, and verify

```bash
cd /path/to/memescanner/trading-engine
pnpm install --frozen-lockfile
docker compose up -d postgres redis
docker compose ps
pnpm migrate
pnpm lint
pnpm typecheck
pnpm build
pnpm test
```

The integration tests use `DATABASE_URL` and `REDIS_URL` unchanged when both are
defined, so external test services do not require Docker. Supplying only one URL
fails instead of silently falling back to containers. Destructive resets refuse
to run unless PostgreSQL targets the database named `trading_engine_test` and
the Redis URL explicitly selects database `/15`.

When neither URL is defined, the tests start disposable PostgreSQL and Redis
containers through Testcontainers. The fallback uses the same dedicated
PostgreSQL database name and Redis database index; Docker is required only for
this fallback.

Start the API and workflow worker after the checks pass:

```bash
docker compose up --build api worker
```

Stop the stack without deleting data:

```bash
docker compose down
```

Delete only the local engine volumes when a fresh local database is explicitly
wanted:

```bash
docker compose down --volumes
```

This command affects only volumes declared in `trading-engine/compose.yaml`; it
does not access Laravel SQLite.

## Calling authenticated endpoints locally

Create a short-lived assertion using the local private key:

```bash
TOKEN="$(
  node --input-type=module -e '
    import { readFileSync } from "node:fs";
    import { importPKCS8, SignJWT } from "jose";
    const pem = readFileSync(".keys/laravel-service-private.pem", "utf8");
    const key = await importPKCS8(pem, "EdDSA");
    const now = Math.floor(Date.now() / 1000);
    process.stdout.write(await new SignJWT({ scope: "health:read commands:noop" })
      .setProtectedHeader({ alg: "EdDSA", typ: "JWT" })
      .setIssuer("meme-scanner-laravel")
      .setAudience("meme-scanner-trading-engine")
      .setSubject("local-laravel")
      .setJti(crypto.randomUUID())
      .setIssuedAt(now)
      .setExpirationTime(now + 30)
      .sign(key));
  '
)"
curl --fail-with-body \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Correlation-Id: local-health-1" \
  http://127.0.0.1:3100/v1/health/live
```

Mint a new assertion for a mutation. A command assertion `jti` is one-use:

```bash
curl --fail-with-body \
  -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: local-noop-1" \
  -H "Content-Type: application/json" \
  -d '{"message":"local synthetic event"}' \
  http://127.0.0.1:3100/v1/commands/noop
```

The API endpoints are:

- `GET /v1/health/live` — authenticated process liveness.
- `GET /v1/health/ready` — authenticated PostgreSQL/Redis readiness.
- `GET /v1/version` — authenticated build/runtime metadata.
- `POST /v1/commands/noop` — authenticated, idempotent synthetic command only.

## Authentication and delivery contracts

Inbound service JWTs must:

- use EdDSA;
- match the configured issuer and audience;
- contain `sub`, `jti`, `iat`, `exp`, and a space-delimited `scope`;
- be within the configured short maximum age;
- grant `health:read` or `commands:noop` for the requested endpoint.

A mutation `jti` cannot authorize a different command. Repeating the same
idempotency key and request returns the existing operation without another
outbox event.

Outbound webhook signatures are:

```text
v1=hex(HMAC-SHA256(secret, timestamp + ".POST." + path + "." + raw_body))
```

Laravel's future receiver must reject stale timestamps, validate the raw body
before parsing, and record `event_id` before applying a projection. The Phase 1
fake receiver implements those rules and acknowledges duplicate event IDs as a
no-op.

## PAPER market monitoring foundation (Phase 1)

The monitoring foundation creates one durable `paper_position_monitoring_tasks`
row in the same
PostgreSQL transaction as every new engine-owned PAPER position. Exact command
replay reuses the position and task, while a rolled-back entry leaves neither.
The task records only scheduling, lease, provider-attempt, safe failure-code,
and last-observed market diagnostics. It cannot invoke lifecycle evaluation,
create HOLD/EXIT decisions, settle a wallet, write ledger entries, or emit a
lifecycle decision event.

`PAPER_MARKET_MONITORING_ENABLED` defaults to `false`. While disabled, no
API, worker, scheduler, or combined Hostinger runtime constructs the shadow
poller, claims monitoring tasks, schedules timers, or calls the provider.
When explicitly enabled, the PostgreSQL workflow leader starts the independent,
non-overlapping shadow poller only after migrations 007 and 008 are verified.
Standby processes do not construct it.

Laravel remains the authoritative lifecycle observation producer. Shadow
observations never invoke lifecycle evaluation, financial settlement, or
outbox delivery.

The provider adapter uses DexScreener's Solana token endpoint. It sends no API
credential, preserves the requested base-token identity, chooses the valid pair
with the greatest reported USD liquidity, and never substitutes FDV or another
asset for missing market-cap data. Requests contain at most 30 addresses and
have independent connection and total timeouts. Missing/malformed markets,
HTTP failures, rate limiting, and transport failures become bounded retries
with safe codes; response bodies and secrets are never persisted as errors.

Operational diagnosis is read-only: inspect due time, state, lease expiry,
failure count, safe error/status, and last successful fetch fields in
`paper_position_monitoring_tasks`, joined to the authoritative
`paper_positions` row. An expired processing lease is claimable after a crash,
and tasks for closed positions are completed without fetching. Provider
failures back off exponentially up to
`PAPER_MARKET_MONITOR_MAXIMUM_BACKOFF_MS`, honoring a smaller configured cap
even when `Retry-After` is longer.

No existing open position is silently enrolled by migration 007. A later
backfill, if approved, must be an explicit, auditable operation that selects
only open engine-owned Solana positions, verifies their immutable network and
asset identity, inserts with `ON CONFLICT (position_id) DO NOTHING`, and is
tested on a dedicated database before production use. Phase 1 does not provide
or execute that operation.

## Operational behavior

- PostgreSQL is authoritative for command idempotency and outbox state.
- The workflow worker polls PostgreSQL directly and processes outbox delivery
  followed by opportunity evaluation in non-overlapping cycles. Idle cycles back
  off exponentially from `OUTBOX_POLL_INTERVAL_MS` to
  `WORKFLOW_IDLE_MAX_INTERVAL_MS`; any discovered or claimed work resets the
  active interval.
- Redis remains configured as reserved infrastructure for future capabilities,
  but the current runtime does not instantiate it and reports it as
  `not_required`; these workflows do not create or consume BullMQ jobs.
- Dispatchers claim work using PostgreSQL row locks and claim leases.
- Failed deliveries are persisted and retried with bounded exponential delay.
- Expired claims are recoverable after a worker crash.
- SIGINT/SIGTERM stop new polling cycles, await an active cycle, release owned
  claims, close clients, and shut down telemetry before the configured deadline.
- Logs redact authorization, cookies, passwords, secrets, private keys, and seed
  phrases.
- OpenTelemetry exporters default to `none` locally so a missing collector cannot block shutdown. Set the standard `OTEL_*_EXPORTER` and endpoint variables to use console or OTLP exporters later.
- Correlation IDs and W3C `traceparent` values are propagated to outbox events
  and webhook headers.

## Troubleshooting

- `Cannot connect to the Docker daemon`: start Colima or Docker Desktop and
  verify `docker context show`.
- Port `5433`, `6380`, or `3100` is occupied: override
  `POSTGRES_PORT`, `REDIS_PORT`, or `PORT` in `.env`.
- Testcontainers cannot start Ryuk: confirm Docker socket access and that the
  active Docker context works.
- Readiness returns 503: inspect `docker compose ps`, then PostgreSQL/Redis
  logs with `docker compose logs postgres redis`.
- Authentication returns 401: mint a fresh token and confirm issuer, audience,
  public key, and the 30-second expiry.
- Authentication returns 403: include the endpoint's required scope.
