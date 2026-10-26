import pino from 'pino';
import type { Kysely } from 'kysely';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import type { StartTokenScanCommand } from '../../src/application/commands/start-token-scan-command.js';
import { StartTokenScanCommandHandler } from '../../src/application/handlers/start-token-scan-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import {
  SOLANA_MAINNET_ID,
} from '../../src/contracts/http/opportunity-command.schema.js';
import type { TokenScanCommand } from '../../src/contracts/http/token-scan-command.schema.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OpportunityEvaluationRepository } from '../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { TokenScanRepository } from '../../src/infrastructure/database/repositories/token-scan-repository.js';
import { BirdeyeScannerProvider } from '../../src/infrastructure/scanning/birdeye-scanner-provider.js';
import { DexScreenerScannerProvider } from '../../src/infrastructure/scanning/dexscreener-scanner-provider.js';
import { GeckoTerminalScannerProvider } from '../../src/infrastructure/scanning/geckoterminal-scanner-provider.js';
import { GoPlusScannerProvider } from '../../src/infrastructure/scanning/goplus-scanner-provider.js';
import { ScannerHttpClient } from '../../src/infrastructure/scanning/scanner-http-client.js';
import { SolanaRpcScannerProvider } from '../../src/infrastructure/scanning/solana-rpc-scanner-provider.js';
import { TokenScanDispatcher } from '../../src/workers/token-scan-dispatcher.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const address = 'So11111111111111111111111111111111111111112';
const lowLiquidityAddress = '11111111111111111111111111111111';
const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

describe('PostgreSQL token scanner workflow', () => {
  let environment: TestEnvironment;
  let database: Kysely<Database>;
  let cleanupEnvironment: TestEnvironment | undefined;
  let cleanupDatabase: Kysely<Database> | undefined;
  let scans: TokenScanRepository;
  let handler: StartTokenScanCommandHandler;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    cleanupEnvironment = environment;
    database = createDatabase(createTestIdentity(environment).config);
    cleanupDatabase = database;
    scans = new TokenScanRepository();
    handler = new StartTokenScanCommandHandler(
      database,
      new CommandInboxRepository(),
      scans,
      true,
      ['1'],
    );
  });

  beforeEach(async () => {
    await resetDatabase(database);
  });

  afterAll(async () => {
    if (cleanupDatabase !== undefined) {
      await cleanupDatabase.destroy();
    }
    if (cleanupEnvironment !== undefined) {
      await stopTestEnvironment(cleanupEnvironment);
    }
  });

  it('runs a qualified scan into opportunity evaluation and a terminal event exactly once', async () => {
    const command = startCommand();
    const accepted = await handler.execute(command);
    const duplicate = await handler.execute({ ...command, authJti: 'scan-jti-duplicate' });
    const scannerHttp = new ScannerHttpClient(providerFetch());
    const outbox = new OutboxRepository();
    const dispatcher = new TokenScanDispatcher({
      database,
      repository: scans,
      outbox,
      opportunities: new RecordOpportunityCommandHandler(
        database,
        new CommandInboxRepository(),
        new OpportunityRepository(),
        outbox,
      ),
      birdeye: new BirdeyeScannerProvider(
        scannerHttp,
        new URL('https://public-api.birdeye.so'),
        'test-key',
        1_000,
      ),
      dexscreener: new DexScreenerScannerProvider(scannerHttp, 1_000),
      geckoTerminal: new GeckoTerminalScannerProvider(scannerHttp, 1_000),
      goplus: new GoPlusScannerProvider(scannerHttp, 1_000),
      solanaRpc: new SolanaRpcScannerProvider(
        scannerHttp,
        new URL('https://rpc.example.test'),
        1_000,
      ),
      logger: pino({ level: 'silent' }),
      batchSize: 1,
      leaseDurationMs: 300_000,
    });

    expect(duplicate).toEqual({ ...accepted, duplicate: true });
    await expect(dispatcher.dispatchBatch()).resolves.toEqual({ didWork: true });
    await expect(dispatcher.dispatchBatch()).resolves.toEqual({ didWork: false });
    await expect(new OpportunityEvaluationRepository().discoverTasks(database)).resolves.toBe(1);

    const execution = await database
      .selectFrom('token_scan_executions')
      .selectAll()
      .executeTakeFirstOrThrow();
    const discovery = await database
      .selectFrom('token_discoveries')
      .selectAll()
      .where('asset_address', '=', address)
      .executeTakeFirstOrThrow();
    const skippedDiscovery = await database
      .selectFrom('token_discoveries')
      .selectAll()
      .where('asset_address', '=', lowLiquidityAddress)
      .executeTakeFirstOrThrow();
    const opportunity = await database
      .selectFrom('opportunities')
      .selectAll()
      .executeTakeFirstOrThrow();
    const events = await database
      .selectFrom('event_outbox')
      .select(['event_type', 'aggregate_id'])
      .orderBy('event_type')
      .execute();
    const evaluationTask = await database
      .selectFrom('opportunity_evaluation_tasks')
      .select(['opportunity_id', 'status'])
      .executeTakeFirstOrThrow();

    expect(execution).toMatchObject({
      id: accepted.scanExecutionId,
      state: 'succeeded',
      attempt_count: 1,
      discovered_count: 2,
      examined_count: 2,
      qualified_count: 1,
      opportunities_created_count: 1,
      opportunities_existing_count: 0,
      skipped_count: 1,
      failed_count: 0,
    });
    expect(discovery).toMatchObject({
      scan_execution_id: execution.id,
      asset_address: address,
      state: 'opportunity_created',
      opportunity_id: opportunity.id,
    });
    expect(skippedDiscovery).toMatchObject({
      scan_execution_id: execution.id,
      state: 'skipped',
      reason_code: 'DISCOVERY_LIQUIDITY_BELOW_MINIMUM',
      opportunity_id: null,
    });
    expect(opportunity).toMatchObject({
      control_plane_user_id: '1',
      scanner: 'new-token',
      network_id: SOLANA_MAINNET_ID,
      asset_address: address,
    });
    expect(evaluationTask).toEqual({
      opportunity_id: opportunity.id,
      status: 'pending',
    });
    expect(events).toEqual([
      { event_type: 'opportunity.recorded.v1', aggregate_id: opportunity.id },
      { event_type: 'token.scan.completed.v1', aggregate_id: execution.id },
    ]);
  });

  it('time-gates retries and fences a stale attempt after takeover', async () => {
    await handler.execute(startCommand());
    const first = (await scans.claimBatch(database, 'worker-one', 1, 60_000))[0];

    expect(first).toBeDefined();
    if (first === undefined) {
      throw new Error('The first scanner attempt was not claimed');
    }
    expect(await scans.renewLease(database, first, 60_000)).toBe(true);
    expect(await scans.retry(database, first, 'PROVIDER_RATE_LIMITED', 60_000)).toBe(true);
    expect(await scans.renewLease(database, first, 60_000)).toBe(false);
    expect(await scans.claimBatch(database, 'worker-two', 1, 60_000)).toEqual([]);

    await database
      .updateTable('token_scan_executions')
      .set({ next_attempt_at: new Date(Date.now() - 1_000) })
      .execute();
    const second = (await scans.claimBatch(database, 'worker-one', 1, 60_000))[0];

    expect(second?.attempt_count).toBe(2);
    if (second === undefined) {
      throw new Error('The second scanner attempt was not claimed');
    }
    expect(await scans.renewLease(database, second, 60_000)).toBe(true);
    expect(await scans.retry(database, first, 'STALE_CALLBACK', 1_000)).toBe(false);
    expect(await scans.complete(database, first, {
      discovered: 0,
      examined: 0,
      qualified: 0,
      opportunitiesCreated: 0,
      opportunitiesExisting: 0,
      skipped: 0,
      failed: 0,
    }, [])).toBe(false);
    await scans.releaseClaims(database, 'worker-one');
  });

  it('rejects a user outside the independent engine canary without durable intake', async () => {
    await expect(handler.execute({
      ...startCommand(),
      idempotencyKey: 'token-scan:laravel:2:v1',
      authJti: 'scan-jti-denied',
      body: {
        ...scanBody(),
        source: { system: 'meme-scanner-laravel', activity_id: '2' },
        subject: { control_plane_user_id: '2' },
      },
    })).rejects.toMatchObject({ code: 'SCANNER_SUBJECT_DENIED', statusCode: 403 });

    await expect(database
      .selectFrom('token_scan_executions')
      .selectAll()
      .execute()).resolves.toEqual([]);
  });
});

function startCommand(): StartTokenScanCommand {
  return {
    idempotencyKey: 'token-scan:laravel:1:v1',
    authJti: 'scan-jti-1',
    subject: 'meme-scanner-laravel',
    correlationId: 'scanner-integration-test',
    traceparent,
    body: scanBody(),
  };
}

function scanBody(): TokenScanCommand {
  return {
    schema_version: 1,
    source: { system: 'meme-scanner-laravel', activity_id: '1' },
    subject: { control_plane_user_id: '1' },
    scanner: 'new-token',
    network: { id: SOLANA_MAINNET_ID },
    policy: {
      max_chase_percent: '100',
      momentum_birdeye_budget: 1,
    },
  };
}

function providerResponse(input: Parameters<typeof fetch>[0]): Response {
  const url = input instanceof URL
    ? input
    : input instanceof Request
      ? new URL(input.url)
      : new URL(input);

  if (url.hostname === 'public-api.birdeye.so'
    && url.pathname === '/defi/v2/tokens/new_listing') {
    return Response.json({
      data: {
        items: [{
          address,
          symbol: 'SCAN',
          name: 'Scanner token',
          liquidity: 6_000,
        }, {
          address: lowLiquidityAddress,
          symbol: 'LOW',
          name: 'Low liquidity token',
          liquidity: 99,
        }],
      },
    });
  }

  if (url.hostname === 'public-api.birdeye.so'
    && url.pathname === '/defi/token_overview') {
    return Response.json({
      data: {
        symbol: 'SCAN',
        name: 'Scanner token',
        price: 0.001,
        marketCap: 10_000,
        liquidity: 6_000,
        holder: 50,
        v1m: 1_000,
        buy1m: 70,
        sell1m: 30,
        uniqueWallet5m: 30,
        priceChange5mPercent: 10,
      },
    });
  }

  if (url.hostname === 'api.gopluslabs.io') {
    const safe = {
      mintable: { status: '0' },
      freezable: { status: '0' },
      non_transferable: { status: '0' },
      transfer_hook: { status: '0' },
      balance_mutable_authority: { status: '0' },
      transfer_fee: { status: '0' },
      transfer_fee_upgradable: { status: '0' },
      transfer_hook_upgradable: { status: '0' },
      closable: { status: '0' },
      metadata_mutable: { status: '0' },
    };

    return Response.json({ result: { [address]: safe } });
  }

  if (url.hostname === 'api.dexscreener.com') {
    return Response.json([{
      baseToken: { address, symbol: 'SCAN', name: 'Scanner token' },
      priceUsd: '0.0011',
      marketCap: 11_000,
      liquidity: { usd: 6_500 },
      volume: { m5: 1_100 },
      txns: { m5: { buys: 75, sells: 25 } },
      priceChange: { m5: 10 },
      pairAddress: 'pair-address',
      dexId: 'test-dex',
    }]);
  }

  throw new Error('Unexpected scanner provider request');
}

function providerFetch(): typeof fetch {
  return (input): Promise<Response> => Promise.resolve(providerResponse(input));
}
