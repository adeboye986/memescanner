import { createHash } from 'node:crypto';

import { sql, type Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { RecordOpportunityCommandHandler } from '../application/handlers/record-opportunity-command-handler.js';
import type { EventEnvelope } from '../contracts/events/event-envelope.schema.js';
import {
  ETHEREUM_MAINNET_ID,
  SOLANA_MAINNET_ID,
  type OpportunityCommand,
} from '../contracts/http/opportunity-command.schema.js';
import type { TokenScanCommand } from '../contracts/http/token-scan-command.schema.js';
import {
  calculateDexDiscoveryRank,
  calculatePreBirdeyeScore,
  qualifiesEthereum,
  qualifiesMomentumDiscovery,
  qualifyNewToken,
} from '../domain/scanning/scanner-qualification.js';
import type { Database, JsonValue } from '../infrastructure/database/client.js';
import type { OutboxRepository } from '../infrastructure/database/repositories/outbox-repository.js';
import {
  type TokenDiscoveryInput,
  type TokenScanCounts,
  type TokenScanExecutionRecord,
  type TokenScanRepository,
} from '../infrastructure/database/repositories/token-scan-repository.js';
import type { BirdeyeScannerProvider } from '../infrastructure/scanning/birdeye-scanner-provider.js';
import type {
  DexMarket,
  DexScreenerScannerProvider,
} from '../infrastructure/scanning/dexscreener-scanner-provider.js';
import type {
  GoPlusScannerProvider,
  ScannerSecurityResult,
} from '../infrastructure/scanning/goplus-scanner-provider.js';
import type { GeckoTerminalScannerProvider } from '../infrastructure/scanning/geckoterminal-scanner-provider.js';
import { ScannerProviderError } from '../infrastructure/scanning/scanner-http-client.js';
import type {
  HolderRisk,
  SolanaRpcScannerProvider,
} from '../infrastructure/scanning/solana-rpc-scanner-provider.js';
import { canonicalizeDatabaseDecimal } from '../shared/amount/canonical-decimal.js';
import { ApplicationError } from '../shared/errors/application-error.js';
import { newEngineId } from '../shared/ids/id.js';
import { hashCanonicalJson } from '../shared/json/canonical-json.js';
import type { WorkflowDispatchResult, WorkflowDispatcher } from './workflow-poller.js';

const maximumAttempts = 5;

export interface TokenScanDispatcherOptions {
  readonly database: Kysely<Database>;
  readonly repository: TokenScanRepository;
  readonly outbox: OutboxRepository;
  readonly opportunities: RecordOpportunityCommandHandler;
  readonly birdeye: BirdeyeScannerProvider;
  readonly dexscreener: DexScreenerScannerProvider;
  readonly geckoTerminal: GeckoTerminalScannerProvider;
  readonly goplus: GoPlusScannerProvider;
  readonly solanaRpc: SolanaRpcScannerProvider;
  readonly logger: Logger;
  readonly batchSize: number;
  readonly leaseDurationMs: number;
}

interface ScanResult {
  readonly counts: TokenScanCounts;
  readonly providerFailures: readonly string[];
}

interface MutableCounts {
  discovered: number;
  examined: number;
  qualified: number;
  opportunitiesCreated: number;
  opportunitiesExisting: number;
  skipped: number;
  failed: number;
}

export class TokenScanDispatcher implements WorkflowDispatcher {
  private readonly claimedBy = newEngineId();

  public constructor(private readonly options: TokenScanDispatcherOptions) {}

  public async dispatchBatch(): Promise<WorkflowDispatchResult> {
    await this.failExhausted();
    const executions = await this.options.repository.claimBatch(
      this.options.database,
      this.claimedBy,
      this.options.batchSize,
      this.options.leaseDurationMs,
    );

    for (const execution of executions) {
      try {
        const command = execution.request as unknown as TokenScanCommand;
        const result = command.network.id === ETHEREUM_MAINNET_ID
          ? await this.scanEthereum(execution, command)
          : command.scanner === 'new-token'
            ? await this.scanSolanaNewTokens(execution, command)
            : await this.scanSolanaMomentum(execution, command);
        await this.complete(execution, command, result);
      } catch (error) {
        const errorCode = safeErrorCode(error);

        if (error instanceof ApplicationError && error.code === 'SCANNER_CLAIM_LOST') {
          this.options.logger.warn(
            { scanExecutionId: execution.id, attemptCount: execution.attempt_count },
            'token scan execution claim was superseded',
          );
          continue;
        }

        if (isRetryable(error) && execution.attempt_count < maximumAttempts) {
          const delayMs = retryDelayMs(execution.attempt_count);
          const retained = await this.options.repository.retry(
            this.options.database,
            execution,
            errorCode,
            delayMs,
          );
          this.options.logger.warn(
            { scanExecutionId: execution.id, errorCode, delayMs, retained },
            'token scan execution scheduled for retry',
          );
        } else {
          await this.fail(execution, errorCode);
          this.options.logger.error(
            { scanExecutionId: execution.id, errorCode },
            'token scan execution failed',
          );
        }
      }
    }

    return { didWork: executions.length > 0 };
  }

  public async shutdown(): Promise<void> {
    await this.options.repository.releaseClaims(this.options.database, this.claimedBy);
  }

  private async renewLease(execution: TokenScanExecutionRecord): Promise<void> {
    const renewed = await this.options.repository.renewLease(
      this.options.database,
      execution,
      this.options.leaseDurationMs,
    );

    if (!renewed) {
      throw new ApplicationError(
        'SCANNER_CLAIM_LOST',
        'The token scan execution claim is no longer owned',
        409,
        true,
      );
    }
  }

  private async scanSolanaNewTokens(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
  ): Promise<ScanResult> {
    const failures = new Set<string>();
    const counts = emptyCounts();
    await this.renewLease(execution);
    const listings = await this.options.birdeye.newListings(20);
    counts.discovered = listings.length;
    const seen = new Set<string>();

    for (const [index, listing] of listings.entries()) {
      counts.examined += 1;

      if (seen.has(listing.address)) {
        counts.skipped += 1;
        continue;
      }

      seen.add(listing.address);
      const discoveryId = await this.discoveryId(execution, listing.address);

      if (listing.liquidity < 100) {
        counts.skipped += 1;
        await this.persistDiscovery(execution, {
          id: discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: listing.address,
          symbol: listing.symbol,
          name: listing.name,
          provider: 'birdeye',
          discoveryIndex: index,
          state: 'skipped',
          reasonCode: 'DISCOVERY_LIQUIDITY_BELOW_MINIMUM',
          marketSnapshot: listing as unknown as JsonValue,
          securitySnapshot: null,
          qualificationSnapshot: null,
          opportunityId: null,
        });
        continue;
      }

      try {
        await this.renewLease(execution);
        const initial = await this.options.birdeye.overview(listing.address);
        const basicQualification = qualifyNewToken(initial, true, true);

        if ([
          'MARKET_CAP_MISSING',
          'MARKET_CAP_OUTSIDE_RANGE',
          'LIQUIDITY_BELOW_MINIMUM',
          'HOLDERS_BELOW_MINIMUM',
        ].includes(basicQualification.reasonCode)) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: listing.address,
            symbol: initial.symbol ?? listing.symbol,
            name: initial.name ?? listing.name,
            provider: 'birdeye',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: basicQualification.reasonCode,
            marketSnapshot: initial as unknown as JsonValue,
            securitySnapshot: null,
            qualificationSnapshot: basicQualification as unknown as JsonValue,
            opportunityId: null,
          });
          continue;
        }

        let security: ScannerSecurityResult;

        try {
          await this.renewLease(execution);
          security = await this.options.goplus.solana(listing.address);
        } catch (error) {
          securityFailure(failures, error, 'SECURITY_PROVIDER_UNAVAILABLE');
          security = unavailableSecurity();
        }

        let selected = initial;
        const initialScore = qualifyNewToken(initial, security.available, security.passed).score;

        if (initialScore >= 40) {
          await this.renewLease(execution);
          selected = await this.options.birdeye.overview(listing.address);
        }

        const qualification = qualifyNewToken(selected, security.available, security.passed);

        if (!qualification.qualified) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: listing.address,
            symbol: selected.symbol ?? listing.symbol,
            name: selected.name ?? listing.name,
            provider: 'birdeye',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: qualification.reasonCode,
            marketSnapshot: selected as unknown as JsonValue,
            securitySnapshot: security as unknown as JsonValue,
            qualificationSnapshot: qualification as unknown as JsonValue,
            opportunityId: null,
          });
          continue;
        }

        await this.renewLease(execution);
        const dex = await this.optionalDex(listing.address, 'solana', failures);
        const entryMarketCap = dex?.available === true && dex.requestedTokenIsBase
          ? dex.marketCap
          : selected.marketCap;
        const move = movement(selected.marketCap, entryMarketCap);
        const maximumChase = Number(command.policy.max_chase_percent);

        if ((dex?.available === true && !dex.requestedTokenIsBase)
          || entryMarketCap < 2_000 || entryMarketCap > 20_000
          || (move !== null && (move <= -30 || move > maximumChase))) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: listing.address,
            symbol: selected.symbol ?? listing.symbol,
            name: selected.name ?? listing.name,
            provider: 'birdeye',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: dex?.available === true && !dex.requestedTokenIsBase
              ? 'DEX_REQUESTED_TOKEN_NOT_BASE'
              : move !== null && move <= -30 ? 'COLLAPSE_PROTECTION'
                : move !== null && move > maximumChase ? 'CHASE_PROTECTION' : 'ENTRY_MARKET_CAP_OUTSIDE_RANGE',
            marketSnapshot: selected as unknown as JsonValue,
            securitySnapshot: security as unknown as JsonValue,
            qualificationSnapshot: qualification as unknown as JsonValue,
            opportunityId: null,
          });
          continue;
        }

        counts.qualified += 1;
        await this.persistDiscovery(execution, {
          id: discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: listing.address,
          symbol: selected.symbol ?? listing.symbol,
          name: selected.name ?? listing.name,
          provider: 'birdeye',
          discoveryIndex: index,
          state: 'qualified',
          reasonCode: null,
          marketSnapshot: selected as unknown as JsonValue,
          securitySnapshot: security as unknown as JsonValue,
          qualificationSnapshot: qualification as unknown as JsonValue,
          opportunityId: null,
        });
        const created = await this.recordOpportunity(execution, command, discoveryId, {
          address: listing.address,
          symbol: selected.symbol ?? listing.symbol,
          name: selected.name ?? listing.name,
          discoveryMarketCap: selected.marketCap,
          entryMarketCap,
          price: dex?.available === true ? dex.priceUsd : selected.price,
          liquidity: dex?.available === true ? dex.liquidityUsd : selected.liquidity,
          volume: selected.volume1m,
          volumeWindow: '1m',
          marketProvider: dex?.available === true ? 'dexscreener' : 'birdeye',
          volumeProvider: 'birdeye',
          pair: dex?.available === true ? dex : null,
          move,
          classification: qualification.classification,
          security,
          holderRisk: null,
        });
        counts[created.duplicate ? 'opportunitiesExisting' : 'opportunitiesCreated'] += 1;
        await this.persistDiscovery(execution, {
          id: discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: listing.address,
          symbol: selected.symbol ?? listing.symbol,
          name: selected.name ?? listing.name,
          provider: 'birdeye',
          discoveryIndex: index,
          state: created.duplicate ? 'opportunity_existing' : 'opportunity_created',
          reasonCode: null,
          marketSnapshot: selected as unknown as JsonValue,
          securitySnapshot: security as unknown as JsonValue,
          qualificationSnapshot: qualification as unknown as JsonValue,
          opportunityId: created.opportunityId,
        });
      } catch (error) {
        securityFailure(failures, error);
        counts.failed += 1;
        await this.persistCandidateFailure(execution, command, discoveryId, listing.address, index, 'birdeye', error);
      }
    }

    return { counts, providerFailures: [...failures] };
  }

  private async scanSolanaMomentum(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
  ): Promise<ScanResult> {
    const failures = new Set<string>();
    const counts = emptyCounts();
    await this.renewLease(execution);
    const profiles = await this.options.dexscreener.latestProfiles('solana', 30);
    counts.discovered = profiles.length;
    const candidates: {
      readonly index: number;
      readonly discoveryId: string;
      readonly market: DexMarket;
      readonly holderRisk: HolderRisk;
      readonly score: number;
    }[] = [];

    for (const [index, profile] of profiles.entries()) {
      counts.examined += 1;
      const discoveryId = await this.discoveryId(execution, profile.address);

      try {
        await this.renewLease(execution);
        const market = await this.options.dexscreener.market(profile.address, 'solana');

        if (!qualifiesMomentumDiscovery(market)) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: profile.address,
            symbol: market.symbol,
            name: market.name,
            provider: 'dexscreener',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: 'MOMENTUM_DISCOVERY_FILTER_FAILED',
            marketSnapshot: market as unknown as JsonValue,
            securitySnapshot: null,
            qualificationSnapshot: null,
            opportunityId: null,
          });
          continue;
        }

        let holderRisk: HolderRisk;

        try {
          await this.renewLease(execution);
          holderRisk = await this.options.solanaRpc.holderRisk(profile.address);
        } catch (error) {
          securityFailure(failures, error, 'HOLDER_PROVIDER_UNAVAILABLE');
          holderRisk = unavailableHolderRisk();
        }

        if (holderRisk.passed === false) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: profile.address,
            symbol: market.symbol,
            name: market.name,
            provider: 'dexscreener',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: 'HOLDER_CONCENTRATION_FAILED',
            marketSnapshot: market as unknown as JsonValue,
            securitySnapshot: holderRisk as unknown as JsonValue,
            qualificationSnapshot: null,
            opportunityId: null,
          });
          continue;
        }

        const score = calculatePreBirdeyeScore(market, holderRisk);

        if (score < 65) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: profile.address,
            symbol: market.symbol,
            name: market.name,
            provider: 'dexscreener',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: 'PRE_BIRDEYE_SCORE_BELOW_MINIMUM',
            marketSnapshot: market as unknown as JsonValue,
            securitySnapshot: holderRisk as unknown as JsonValue,
            qualificationSnapshot: { pre_birdeye_score: score },
            opportunityId: null,
          });
          continue;
        }

        candidates.push({ index, discoveryId, market, holderRisk, score });
      } catch (error) {
        securityFailure(failures, error);
        counts.failed += 1;
        await this.persistCandidateFailure(execution, command, discoveryId, profile.address, index, 'dexscreener', error);
      }
    }

    candidates.sort((left, right) => right.score - left.score
      || calculateDexDiscoveryRank(right.market) - calculateDexDiscoveryRank(left.market)
      || right.market.volume5m - left.market.volume5m);
    const selected = candidates.slice(0, command.policy.momentum_birdeye_budget);
    const deferred = candidates.slice(command.policy.momentum_birdeye_budget);

    for (const candidate of deferred) {
      counts.skipped += 1;
      await this.persistDiscovery(execution, {
        id: candidate.discoveryId,
        scanExecutionId: execution.id,
        networkId: command.network.id,
        assetAddress: candidate.market.address,
        symbol: candidate.market.symbol,
        name: candidate.market.name,
        provider: 'dexscreener',
        discoveryIndex: candidate.index,
        state: 'skipped',
        reasonCode: 'MOMENTUM_PROVIDER_BUDGET_EXHAUSTED',
        marketSnapshot: candidate.market as unknown as JsonValue,
        securitySnapshot: candidate.holderRisk as unknown as JsonValue,
        qualificationSnapshot: { pre_birdeye_score: candidate.score },
        opportunityId: null,
      });
    }

    for (const candidate of selected) {
      await this.renewLease(execution);
      const market = await this.options.dexscreener.market(candidate.market.address, 'solana');
      const move = movement(candidate.market.marketCap, market.marketCap);
      const maximumChase = Number(command.policy.max_chase_percent);

      if (!qualifiesMomentumDiscovery(market)
        || (move !== null && (move <= -30 || move > maximumChase))) {
        counts.skipped += 1;
        await this.persistDiscovery(execution, {
          id: candidate.discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: market.address,
          symbol: market.symbol,
          name: market.name,
          provider: 'dexscreener',
          discoveryIndex: candidate.index,
          state: 'skipped',
          reasonCode: move !== null && move <= -30 ? 'COLLAPSE_PROTECTION'
            : move !== null && move > maximumChase ? 'CHASE_PROTECTION' : 'MOMENTUM_CONFIRMATION_FAILED',
          marketSnapshot: market as unknown as JsonValue,
          securitySnapshot: candidate.holderRisk as unknown as JsonValue,
          qualificationSnapshot: { pre_birdeye_score: candidate.score },
          opportunityId: null,
        });
        continue;
      }

      counts.qualified += 1;
      await this.persistDiscovery(execution, {
        id: candidate.discoveryId,
        scanExecutionId: execution.id,
        networkId: command.network.id,
        assetAddress: market.address,
        symbol: market.symbol,
        name: market.name,
        provider: 'dexscreener',
        discoveryIndex: candidate.index,
        state: 'qualified',
        reasonCode: null,
        marketSnapshot: market as unknown as JsonValue,
        securitySnapshot: candidate.holderRisk as unknown as JsonValue,
        qualificationSnapshot: { pre_birdeye_score: candidate.score },
        opportunityId: null,
      });
      const created = await this.recordOpportunity(execution, command, candidate.discoveryId, {
        address: market.address,
        symbol: market.symbol,
        name: market.name,
        discoveryMarketCap: candidate.market.marketCap,
        entryMarketCap: market.marketCap,
        price: market.priceUsd,
        liquidity: market.liquidityUsd,
        volume: market.volume5m,
        volumeWindow: '5m',
        marketProvider: 'dexscreener',
        volumeProvider: 'dexscreener',
        pair: market,
        move,
        classification: null,
        security: null,
        holderRisk: candidate.holderRisk,
      });
      counts[created.duplicate ? 'opportunitiesExisting' : 'opportunitiesCreated'] += 1;
      await this.persistDiscovery(execution, {
        id: candidate.discoveryId,
        scanExecutionId: execution.id,
        networkId: command.network.id,
        assetAddress: market.address,
        symbol: market.symbol,
        name: market.name,
        provider: 'dexscreener',
        discoveryIndex: candidate.index,
        state: created.duplicate ? 'opportunity_existing' : 'opportunity_created',
        reasonCode: null,
        marketSnapshot: market as unknown as JsonValue,
        securitySnapshot: candidate.holderRisk as unknown as JsonValue,
        qualificationSnapshot: { pre_birdeye_score: candidate.score },
        opportunityId: created.opportunityId,
      });
    }

    return { counts, providerFailures: [...failures] };
  }

  private async scanEthereum(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
  ): Promise<ScanResult> {
    const failures = new Set<string>();
    const counts = emptyCounts();
    await this.renewLease(execution);
    const profiles = await this.options.geckoTerminal.latestEthereumTokens(30);
    counts.discovered = profiles.length;

    for (const [index, profile] of profiles.entries()) {
      counts.examined += 1;
      const discoveryId = await this.discoveryId(execution, profile.address);

      try {
        await this.renewLease(execution);
        const market = await this.options.dexscreener.market(profile.address, 'ethereum');

        if (!qualifiesEthereum(command.scanner, market)) {
          counts.skipped += 1;
          await this.persistDiscovery(execution, {
            id: discoveryId,
            scanExecutionId: execution.id,
            networkId: command.network.id,
            assetAddress: profile.address,
            symbol: market.symbol,
            name: market.name,
            provider: 'geckoterminal',
            discoveryIndex: index,
            state: 'skipped',
            reasonCode: 'ETHEREUM_QUALIFICATION_FILTER_FAILED',
            marketSnapshot: market as unknown as JsonValue,
            securitySnapshot: null,
            qualificationSnapshot: { scanner: command.scanner },
            opportunityId: null,
          });
          continue;
        }

        let security: ScannerSecurityResult;

        try {
          await this.renewLease(execution);
          security = await this.options.goplus.ethereum(profile.address);
        } catch (error) {
          securityFailure(failures, error, 'SECURITY_PROVIDER_UNAVAILABLE');
          security = unavailableSecurity();
        }

        counts.qualified += 1;
        await this.persistDiscovery(execution, {
          id: discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: market.address,
          symbol: market.symbol,
          name: market.name,
          provider: 'geckoterminal',
          discoveryIndex: index,
          state: 'qualified',
          reasonCode: null,
          marketSnapshot: market as unknown as JsonValue,
          securitySnapshot: security as unknown as JsonValue,
          qualificationSnapshot: { scanner: command.scanner },
          opportunityId: null,
        });
        const created = await this.recordOpportunity(execution, command, discoveryId, {
          address: market.address,
          symbol: market.symbol,
          name: market.name,
          discoveryMarketCap: market.marketCap,
          entryMarketCap: market.marketCap,
          price: market.priceUsd,
          liquidity: market.liquidityUsd,
          volume: market.volume5m,
          volumeWindow: '5m',
          marketProvider: 'dexscreener',
          volumeProvider: 'dexscreener',
          pair: market,
          move: 0,
          classification: null,
          security,
          holderRisk: null,
        });
        counts[created.duplicate ? 'opportunitiesExisting' : 'opportunitiesCreated'] += 1;
        await this.persistDiscovery(execution, {
          id: discoveryId,
          scanExecutionId: execution.id,
          networkId: command.network.id,
          assetAddress: market.address,
          symbol: market.symbol,
          name: market.name,
          provider: 'geckoterminal',
          discoveryIndex: index,
          state: created.duplicate ? 'opportunity_existing' : 'opportunity_created',
          reasonCode: null,
          marketSnapshot: market as unknown as JsonValue,
          securitySnapshot: security as unknown as JsonValue,
          qualificationSnapshot: { scanner: command.scanner },
          opportunityId: created.opportunityId,
        });
      } catch (error) {
        securityFailure(failures, error);
        counts.failed += 1;
        await this.persistCandidateFailure(execution, command, discoveryId, profile.address, index, 'geckoterminal', error);
      }
    }

    return { counts, providerFailures: [...failures] };
  }

  private async recordOpportunity(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
    discoveryId: string,
    candidate: {
      readonly address: string;
      readonly symbol: string | null;
      readonly name: string | null;
      readonly discoveryMarketCap: number;
      readonly entryMarketCap: number;
      readonly price: number | null;
      readonly liquidity: number | null;
      readonly volume: number;
      readonly volumeWindow: '1m' | '5m';
      readonly marketProvider: 'birdeye' | 'dexscreener';
      readonly volumeProvider: 'birdeye' | 'dexscreener';
      readonly pair: DexMarket | null;
      readonly move: number | null;
      readonly classification: string | null;
      readonly security: ScannerSecurityResult | null;
      readonly holderRisk: HolderRisk | null;
    },
  ): Promise<{ readonly opportunityId: string; readonly duplicate: boolean }> {
    const body = opportunityCommand(execution, command, discoveryId, candidate);
    const createdByThisScan = await this.options.database
      .selectFrom('opportunities')
      .select('id')
      .where('source_system', '=', 'meme-scanner-trading-engine')
      .where('source_opportunity_id', '=', discoveryId)
      .executeTakeFirst();

    if (createdByThisScan !== undefined) {
      return { opportunityId: createdByThisScan.id, duplicate: false };
    }

    const existingDiscovery = await this.findExistingOpportunity(
      command.subject.control_plane_user_id,
      body.source.discovery_key,
    );

    if (existingDiscovery !== undefined) {
      return { opportunityId: existingDiscovery.id, duplicate: true };
    }

    try {
      const response = await this.options.opportunities.execute({
        idempotencyKey: `opportunity:record:scanner:${discoveryId}:v1`,
        authJti: `scanner:${execution.id}:${discoveryId}`,
        subject: 'meme-scanner-trading-engine-scanner',
        correlationId: execution.correlation_id,
        traceparent: execution.traceparent,
        body,
      });

      return {
        opportunityId: response.opportunityId,
        duplicate: false,
      };
    } catch (error) {
      if (error instanceof ApplicationError && error.code === 'OPPORTUNITY_DISCOVERY_CONFLICT') {
        const concurrent = await this.findExistingOpportunity(
          command.subject.control_plane_user_id,
          body.source.discovery_key,
        );

        if (concurrent !== undefined) {
          return { opportunityId: concurrent.id, duplicate: true };
        }
      }

      throw error;
    }
  }

  private findExistingOpportunity(
    controlPlaneUserId: string,
    discoveryKey: string,
  ): Promise<{ readonly id: string } | undefined> {
    return this.options.database
      .selectFrom('opportunities')
      .select('id')
      .where('control_plane_user_id', '=', controlPlaneUserId)
      .where('discovery_key', '=', discoveryKey)
      .executeTakeFirst();
  }

  private async discoveryId(execution: TokenScanExecutionRecord, address: string): Promise<string> {
    const existing = await this.options.database
      .selectFrom('token_discoveries')
      .select('id')
      .where('scan_execution_id', '=', execution.id)
      .where('network_id', '=', execution.network_id)
      .where('asset_address', '=', address)
      .executeTakeFirst();

    return existing?.id ?? newEngineId();
  }

  private persistDiscovery(
    execution: TokenScanExecutionRecord,
    input: TokenDiscoveryInput,
  ): Promise<void> {
    return this.options.repository.recordDiscovery(this.options.database, execution, input);
  }

  private async persistCandidateFailure(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
    discoveryId: string,
    address: string,
    index: number,
    provider: 'birdeye' | 'dexscreener' | 'geckoterminal',
    error: unknown,
  ): Promise<void> {
    const existing = await this.options.database
      .selectFrom('token_discoveries')
      .selectAll()
      .where('scan_execution_id', '=', execution.id)
      .where('network_id', '=', command.network.id)
      .where('asset_address', '=', address)
      .executeTakeFirst();
    const qualified = existing?.state === 'qualified';

    await this.persistDiscovery(execution, {
      id: discoveryId,
      scanExecutionId: execution.id,
      networkId: command.network.id,
      assetAddress: address,
      symbol: existing?.symbol ?? null,
      name: existing?.name ?? null,
      provider,
      discoveryIndex: index,
      state: qualified ? 'qualified_downstream_failed' : 'failed',
      reasonCode: safeErrorCode(error),
      marketSnapshot: existing?.market_snapshot ?? null,
      securitySnapshot: existing?.security_snapshot ?? null,
      qualificationSnapshot: existing?.qualification_snapshot ?? null,
      opportunityId: null,
    });
  }

  private async optionalDex(
    address: string,
    chain: 'solana' | 'ethereum',
    failures: Set<string>,
  ): Promise<DexMarket | null> {
    try {
      return await this.options.dexscreener.market(address, chain);
    } catch (error) {
      securityFailure(failures, error, 'MARKET_PROVIDER_UNAVAILABLE');
      return null;
    }
  }

  private async complete(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
    result: ScanResult,
  ): Promise<void> {
    await this.persistTerminalEvent(execution, command, result, null);
  }

  private async fail(execution: TokenScanExecutionRecord, errorCode: string): Promise<void> {
    const command = execution.request as unknown as TokenScanCommand;
    await this.persistTerminalEvent(execution, command, { counts: emptyCounts(), providerFailures: [] }, errorCode);
  }

  private async persistTerminalEvent(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
    result: ScanResult,
    errorCode: string | null,
    allowExpiredLease = false,
  ): Promise<void> {
    await this.options.database.transaction().execute(async (transaction) => {
      const eventId = newEngineId();
      const completedAt = new Date();
      const outcome = errorCode !== null
        ? 'failed'
        : result.providerFailures.length > 0 || result.counts.failed > 0
          ? 'succeeded_with_warnings'
          : 'succeeded';
      const update = await transaction
        .updateTable('token_scan_executions')
        .set({
          state: outcome,
          lease_owner: null,
          lease_expires_at: null,
          discovered_count: result.counts.discovered,
          examined_count: result.counts.examined,
          qualified_count: result.counts.qualified,
          opportunities_created_count: result.counts.opportunitiesCreated,
          opportunities_existing_count: result.counts.opportunitiesExisting,
          skipped_count: result.counts.skipped,
          failed_count: result.counts.failed,
          provider_failures: result.providerFailures,
          last_error_code: errorCode,
          completed_at: completedAt,
          updated_at: completedAt,
        })
        .where('id', '=', execution.id)
        .where('state', '=', 'processing')
        .where('attempt_count', '=', execution.attempt_count)
        .where('lease_owner', '=', execution.lease_owner)
        .where((expression) => allowExpiredLease
          ? expression('lease_expires_at', '<=', sql<Date>`clock_timestamp()`)
          : expression('lease_expires_at', '>', sql<Date>`clock_timestamp()`))
        .executeTakeFirst();

      if (update.numUpdatedRows !== 1n) {
        throw new Error('Token scan terminal transition is no longer owned');
      }

      const payload = {
        scan_execution_id: execution.id,
        source: command.source,
        subject: command.subject,
        scanner: command.scanner,
        network: command.network,
        outcome,
        counts: {
          discovered: result.counts.discovered,
          examined: result.counts.examined,
          qualified: result.counts.qualified,
          opportunities_created: result.counts.opportunitiesCreated,
          opportunities_existing: result.counts.opportunitiesExisting,
          skipped: result.counts.skipped,
          failed: result.counts.failed,
        },
        provider_failures: result.providerFailures,
        error_code: errorCode,
        completed_at: completedAt.toISOString(),
      };
      const envelope: EventEnvelope = {
        event_id: eventId,
        event_type: errorCode === null ? 'token.scan.completed.v1' : 'token.scan.failed.v1',
        schema_version: 1,
        occurred_at: completedAt.toISOString(),
        producer: 'trading-engine',
        aggregate_type: 'token_scan_execution',
        aggregate_id: execution.id,
        aggregate_version: execution.attempt_count,
        correlation_id: execution.correlation_id,
        causation_id: execution.id,
        idempotency_key: `token-scan:${execution.id}:terminal:v1`,
        traceparent: execution.traceparent,
        payload,
        payload_sha256: hashCanonicalJson(payload),
      };
      await this.options.outbox.enqueue(transaction, envelope);
    });
  }

  private async failExhausted(): Promise<void> {
    const exhausted = await this.options.database
      .selectFrom('token_scan_executions')
      .selectAll()
      .where('state', '=', 'processing')
      .where('attempt_count', '>=', maximumAttempts)
      .where('lease_expires_at', '<=', sql<Date>`clock_timestamp()`)
      .limit(this.options.batchSize)
      .execute();

    for (const execution of exhausted) {
      const command = execution.request as unknown as TokenScanCommand;
      await this.persistTerminalEvent(
        execution,
        command,
        { counts: emptyCounts(), providerFailures: [] },
        'SCANNER_ATTEMPTS_EXHAUSTED',
        true,
      );
    }
  }
}

function opportunityCommand(
  execution: TokenScanExecutionRecord,
  command: TokenScanCommand,
  discoveryId: string,
  candidate: Parameters<TokenScanDispatcher['recordOpportunity']>[3],
): OpportunityCommand {
  const security = candidate.holderRisk === null
    ? candidate.security === null
      ? undefined
      : candidate.security.available
        ? {
            status: candidate.security.passed === true ? 'passed' as const : 'failed' as const,
            provider: 'goplus' as const,
            passed: candidate.security.passed ?? false,
            ...(candidate.security.score === null ? {} : { score: candidate.security.score }),
            risks: [...candidate.security.risks],
            coverage: 'GoPlus token-security evaluation.',
            ...(candidate.pair === null ? {} : {
              market_validation: {
                provider: 'dexscreener' as const,
                requested_token_is_base: candidate.pair.requestedTokenIsBase,
                pair_available: candidate.pair.available,
              },
            }),
          }
        : {
            status: 'unavailable' as const,
            coverage: 'GoPlus token-security data was unavailable.',
            ...(candidate.pair === null ? {} : {
              market_validation: {
                provider: 'dexscreener' as const,
                requested_token_is_base: candidate.pair.requestedTokenIsBase,
                pair_available: candidate.pair.available,
              },
            }),
          }
    : {
        status: candidate.holderRisk.passed === true ? 'passed' as const : 'unavailable' as const,
        provider: 'solana_rpc_holder_analysis' as const,
        ...(candidate.holderRisk.passed === null ? {} : { passed: candidate.holderRisk.passed }),
        ...(candidate.holderRisk.score === null ? {} : { score: candidate.holderRisk.score }),
        risks: [...candidate.holderRisk.reasons],
        coverage: 'Solana RPC holder concentration at qualification.',
        holder_concentration: {
          ...(candidate.holderRisk.largestHolderPercentage === null ? {} : {
            largest_holder_percent: decimal(candidate.holderRisk.largestHolderPercentage),
          }),
          ...(candidate.holderRisk.top5Percentage === null ? {} : {
            top_5_percent: decimal(candidate.holderRisk.top5Percentage),
          }),
          ...(candidate.holderRisk.top10Percentage === null ? {} : {
            top_10_percent: decimal(candidate.holderRisk.top10Percentage),
          }),
          risk_level: candidate.holderRisk.level,
        },
        market_validation: {
          provider: 'dexscreener' as const,
          requested_token_is_base: candidate.pair?.requestedTokenIsBase ?? false,
          pair_available: candidate.pair?.available ?? false,
        },
      };
  const discoveryKey = createHash('sha256')
    .update(`${networkName(command.network.id)}|${command.scanner}|${candidate.address.toLowerCase()}|${decimal(candidate.discoveryMarketCap)}`)
    .digest('hex');

  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-trading-engine',
      opportunity_id: discoveryId,
      discovery_key: discoveryKey,
      scanner: command.scanner,
      scan_execution_id: execution.id,
    },
    subject: command.subject,
    network: command.network,
    asset: {
      address: candidate.address,
      ...(candidate.symbol === null ? {} : { symbol: candidate.symbol }),
      ...(candidate.name === null ? {} : { name: candidate.name }),
    },
    market_snapshot: {
      ...(candidate.price === null ? {} : {
        price_usd: { value: decimal(candidate.price), provider: candidate.marketProvider },
      }),
      market_cap_usd: { value: decimal(candidate.entryMarketCap), provider: candidate.marketProvider },
      ...(candidate.liquidity === null ? {} : {
        liquidity_usd: { value: decimal(candidate.liquidity), provider: candidate.marketProvider },
      }),
      volume_usd: {
        value: decimal(candidate.volume),
        provider: candidate.volumeProvider,
        window: candidate.volumeWindow,
      },
      ...(candidate.pair?.pairAddress === null || candidate.pair === null ? {} : {
        pair: {
          address: candidate.pair.pairAddress,
          ...(candidate.pair.dex === null ? {} : { dex: candidate.pair.dex }),
          provider: 'dexscreener',
        },
      }),
    },
    qualification: {
      qualified_at: new Date().toISOString(),
      discovery_market_cap_usd: decimal(candidate.discoveryMarketCap),
      ...(candidate.move === null ? {} : { move_since_discovery_percent: decimal(candidate.move) }),
      ...(candidate.classification === null ? {} : { classification: candidate.classification }),
    },
    ...(security === undefined ? {} : { security }),
  };
}

function emptyCounts(): MutableCounts {
  return {
    discovered: 0,
    examined: 0,
    qualified: 0,
    opportunitiesCreated: 0,
    opportunitiesExisting: 0,
    skipped: 0,
    failed: 0,
  };
}

function movement(discovery: number, current: number): number | null {
  return discovery > 0 && current > 0 ? ((current - discovery) / discovery) * 100 : null;
}

function decimal(value: number): string {
  if (!Number.isFinite(value)) {
    throw new ApplicationError('SCANNER_VALUE_INVALID', 'Scanner market data is invalid', 422, false);
  }

  return canonicalizeDatabaseDecimal(value.toFixed(29));
}

function networkName(networkId: string): string {
  return networkId === SOLANA_MAINNET_ID ? 'solana' : 'ethereum';
}

function unavailableHolderRisk(): HolderRisk {
  return {
    available: false,
    passed: null,
    score: null,
    level: 'unverified',
    reasons: ['Holder analysis unavailable.'],
    largestHolderPercentage: null,
    top5Percentage: null,
    top10Percentage: null,
  };
}

function unavailableSecurity(): ScannerSecurityResult {
  return { available: false, passed: null, score: null, risks: [], checks: {} };
}

function safeErrorCode(error: unknown): string {
  if (error instanceof ScannerProviderError) {
    return error.code;
  }

  if (error instanceof ApplicationError) {
    return error.code;
  }

  return 'SCANNER_EXECUTION_FAILED';
}

function isRetryable(error: unknown): boolean {
  if (error instanceof ScannerProviderError || error instanceof ApplicationError) {
    return error.retryable;
  }

  return true;
}

function retryDelayMs(attemptCount: number): number {
  return Math.min(300_000, 5_000 * (2 ** Math.max(0, attemptCount - 1)));
}

function securityFailure(
  failures: Set<string>,
  error: unknown,
  fallback = 'PROVIDER_UNAVAILABLE',
): void {
  failures.add(error instanceof ScannerProviderError ? error.code : fallback);
}
