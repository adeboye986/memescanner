# Meme Scanner trading engine — Phase 1

This directory contains the local, non-trading TypeScript foundation approved in
`docs/typescript-trading-engine-migration.md`.

Phase 1 contains infrastructure only:

- a Fastify API;
- independent BullMQ worker and scheduler processes;
- dedicated PostgreSQL migrations and command/outbox persistence;
- externalizable Redis coordination;
- Ed25519 service-assertion verification;
- signed synthetic webhook delivery;
- structured redacted logs and trace/correlation propagation;
- unit, contract, and integration tests.

It does not contain scanners, provider polling, wallets, blockchain code,
balances, orders, fills, positions, portfolios, or trading execution. It never
opens Laravel's SQLite database.

## Runtime and process model

The workspace pins Node.js 24.21.0 and pnpm 12.6.0. The three entry points are
built and started independently:

| Role | Development command | Production-style command |
|---|---|---|
| API | `pnpm dev:api` | `pnpm start:api` |
| Worker | `pnpm dev:worker` | `pnpm start:worker` |
| Scheduler | `pnpm dev:scheduler` | `pnpm start:scheduler` |

All roles use URLs from the environment. Local Docker services can therefore be
replaced later with external PostgreSQL and Redis without changing domain code.
Hostinger is not used or modified by Phase 1.

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

Start all three process roles after the checks pass:

```bash
docker compose up --build api worker scheduler
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

## Operational behavior

- PostgreSQL is authoritative for command idempotency and outbox state.
- Redis/BullMQ coordinates dispatch work but is never financial truth.
- The scheduler creates deduplicated dispatch jobs.
- The worker claims outbox rows using PostgreSQL row locks and a claim lease.
- Failed deliveries are persisted and retried with bounded exponential delay.
- Expired claims are recoverable after a worker crash.
- SIGINT/SIGTERM stop intake, close BullMQ, release owned outbox claims, close
  clients, and shut down telemetry before the configured deadline.
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
