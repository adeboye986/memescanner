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
import {
  ScannerProviderError,
  type ScannerProviderName,
} from '../infrastructure/scanning/scanner-http-client.js';
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

type ScannerDiagnosticProvider = ScannerProviderName | 'postgresql' | null;

export interface ScannerErrorDiagnostic {
  readonly workflowStage: string;
  readonly provider: ScannerDiagnosticProvider;
  readonly classification: string;
  readonly errorCode: string;
  readonly sanitizedMessage: string;
  readonly retryable: boolean;
  readonly httpStatus: number | null;
  readonly timeout: boolean;
  readonly databaseCategory: string | null;
  readonly diagnosticStack: string | null;
}

class ScannerWorkflowError extends Error {
  public constructor(
    public readonly workflowStage: string,
    public readonly provider: ScannerDiagnosticProvider,
    public override readonly cause: unknown,
  ) {
    super('Scanner workflow stage failed', { cause });
    this.name = 'ScannerWorkflowError';
  }
}

export class TokenScanDispatcher implements WorkflowDispatcher {
  private readonly claimedBy = newEngineId();

  public constructor(private readonly options: TokenScanDispatcherOptions) {}

  public async dispatchBatch(): Promise<WorkflowDispatchResult> {
    try {
      return await this.dispatchBatchInternal();
    } catch (error) {
      return this.throwSafeBoundary(
        error,
        'token scan workflow failed outside an owned execution',
      );
    }
  }

  private async dispatchBatchInternal(): Promise<WorkflowDispatchResult> {
    await this.failExhausted();
    const executions = await this.options.repository.claimBatch(
      this.options.database,
      this.claimedBy,
      this.options.batchSize,
      this.options.leaseDurationMs,
    );

    for (const execution of executions) {
      const engineOperationId = await this.operationIdFor(execution);
      this.options.logger.info(
        {
          scanExecutionId: execution.id,
          engineOperationId,
          workflowStage: 'execution',
          attemptNumber: execution.attempt_count,
        },
        'token scan execution started',
      );

      try {
        const command = execution.request as unknown as TokenScanCommand;
        const result = command.network.id === ETHEREUM_MAINNET_ID
          ? await this.scanEthereum(execution, command)
          : command.scanner === 'new-token'
            ? await this.scanSolanaNewTokens(execution, command)
            : await this.scanSolanaMomentum(execution, command);
        await this.complete(execution, command, result);
        this.options.logger.info(
          {
            scanExecutionId: execution.id,
            engineOperationId,
            workflowStage: 'terminal_persistence',
            attemptNumber: execution.attempt_count,
            terminalOutcome: result.providerFailures.length > 0 || result.counts.failed > 0
              ? 'succeeded_with_warnings'
              : 'succeeded',
            counts: result.counts,
            providerFailures: result.providerFailures,
          },
          'token scan execution completed',
        );
      } catch (error) {
        const diagnostic = scannerErrorDiagnostic(error);
        const logContext = {
          scanExecutionId: execution.id,
          engineOperationId,
          attemptNumber: execution.attempt_count,
          ...diagnosticLogFields(diagnostic),
        };

        if (diagnostic.errorCode === 'SCANNER_CLAIM_LOST') {
          this.options.logger.warn(
            logContext,
            'token scan execution claim was superseded',
          );
          continue;
        }

        const progress = await this.durableProgress(execution, diagnostic);

        if (diagnostic.retryable && execution.attempt_count < maximumAttempts) {
          const delayMs = retryDelayMs(execution.attempt_count);
          let retained: boolean;

          try {
            retained = await this.options.repository.retry(
              this.options.database,
              execution,
              diagnostic.errorCode,
              delayMs,
            );
          } catch (persistenceError) {
            const persistenceDiagnostic = scannerErrorDiagnostic(
              new ScannerWorkflowError('retry_persistence', 'postgresql', persistenceError),
            );
            this.options.logger.error(
              {
                ...logContext,
                ...diagnosticLogFields(persistenceDiagnostic),
              },
              'token scan retry state could not be persisted',
            );
            throw persistenceError;
          }

          this.options.logger.warn(
            {
              ...logContext,
              retryDelayMs: delayMs,
              transitionRetained: retained,
              counts: progress.counts,
              providerFailures: progress.providerFailures,
            },
            'token scan execution scheduled for retry',
          );
        } else {
          await this.fail(execution, diagnostic.errorCode, progress);
          this.options.logger.error(
            {
              ...logContext,
              terminalOutcome: 'failed',
              counts: progress.counts,
              providerFailures: progress.providerFailures,
            },
            'token scan execution failed',
          );
        }
      }
    }

    return { didWork: executions.length > 0 };
  }

  public async shutdown(): Promise<void> {
    try {
      await this.options.repository.releaseClaims(this.options.database, this.claimedBy);
    } catch (error) {
      return this.throwSafeBoundary(
        new ScannerWorkflowError('shutdown_release', 'postgresql', error),
        'token scan claims could not be released during shutdown',
      );
    }
  }

  private throwSafeBoundary(error: unknown, message: string): never {
    const diagnostic = scannerErrorDiagnostic(error);
    this.options.logger.error(diagnosticLogFields(diagnostic), message);

    throw new Error(`Token scan workflow failed with safe code ${diagnostic.errorCode}`);
  }

  private async operationIdFor(execution: TokenScanExecutionRecord): Promise<string | null> {
    try {
      const operation = await this.options.database
        .selectFrom('command_inbox')
        .select('id')
        .where('idempotency_key', '=', execution.idempotency_key)
        .executeTakeFirst();

      return operation?.id ?? null;
    } catch (error) {
      const diagnostic = scannerErrorDiagnostic(
        new ScannerWorkflowError('diagnostic_lookup', 'postgresql', error),
      );
      this.options.logger.warn(
        {
          scanExecutionId: execution.id,
          attemptNumber: execution.attempt_count,
          ...diagnosticLogFields(diagnostic),
        },
        'token scan operation diagnostic lookup failed',
      );

      return null;
    }
  }

  private async durableProgress(
    execution: TokenScanExecutionRecord,
    rootDiagnostic?: ScannerErrorDiagnostic,
  ): Promise<ScanResult> {
    try {
      const discoveries = await this.options.database
        .selectFrom('token_discoveries')
        .select(['state', 'reason_code'])
        .where('scan_execution_id', '=', execution.id)
        .execute();
      const qualifiedStates = new Set([
        'qualified',
        'qualified_downstream_failed',
        'opportunity_created',
        'opportunity_existing',
      ]);
      const providerFailures = new Set(
        discoveries
          .map((discovery) => discovery.reason_code)
          .filter((code): code is string => code !== null && providerFailureCode(code)),
      );

      if (rootDiagnostic !== undefined && providerFailureCode(rootDiagnostic.errorCode)) {
        providerFailures.add(rootDiagnostic.errorCode);
      }

      return {
        counts: {
          discovered: discoveries.length,
          examined: discoveries.length,
          qualified: discoveries.filter((discovery) => qualifiedStates.has(discovery.state)).length,
          opportunitiesCreated: discoveries.filter(
            (discovery) => discovery.state === 'opportunity_created',
          ).length,
          opportunitiesExisting: discoveries.filter(
            (discovery) => discovery.state === 'opportunity_existing',
          ).length,
          skipped: discoveries.filter((discovery) => discovery.state === 'skipped').length,
          failed: discoveries.filter(
            (discovery) => ['failed', 'qualified_downstream_failed'].includes(discovery.state),
          ).length,
        },
        providerFailures: [...providerFailures].slice(0, 32),
      };
    } catch (error) {
      const diagnostic = scannerErrorDiagnostic(
        new ScannerWorkflowError('diagnostic_persistence', 'postgresql', error),
      );
      this.options.logger.error(
        {
          scanExecutionId: execution.id,
          attemptNumber: execution.attempt_count,
          ...diagnosticLogFields(diagnostic),
        },
        'token scan durable progress could not be read',
      );

      return {
        counts: emptyCounts(),
        providerFailures: rootDiagnostic !== undefined
          && providerFailureCode(rootDiagnostic.errorCode)
          ? [rootDiagnostic.errorCode]
          : [],
      };
    }
  }

  private async logNonTerminalFailure(
    execution: TokenScanExecutionRecord,
    error: unknown,
    message: string,
  ): Promise<void> {
    const diagnostic = scannerErrorDiagnostic(error);
    this.options.logger.warn(
      {
        scanExecutionId: execution.id,
        engineOperationId: await this.operationIdFor(execution),
        attemptNumber: execution.attempt_count,
        ...diagnosticLogFields(diagnostic),
      },
      message,
    );
  }

  private async renewLease(execution: TokenScanExecutionRecord): Promise<void> {
    const renewed = await this.runStage(
      'lease_renewal',
      'postgresql',
      () => this.options.repository.renewLease(
        this.options.database,
        execution,
        this.options.leaseDurationMs,
      ),
    );

    if (!renewed) {
      throw new ScannerWorkflowError(
        'lease_renewal',
        'postgresql',
        new ApplicationError(
          'SCANNER_CLAIM_LOST',
          'The token scan execution claim is no longer owned',
          409,
          true,
        ),
      );
    }
  }

  private async runStage<T>(
    workflowStage: string,
    provider: ScannerDiagnosticProvider,
    operation: () => Promise<T>,
  ): Promise<T> {
    try {
      return await operation();
    } catch (error) {
      if (error instanceof ScannerWorkflowError) {
        throw error;
      }

      throw new ScannerWorkflowError(workflowStage, provider, error);
    }
  }

  private async scanSolanaNewTokens(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
  ): Promise<ScanResult> {
    const failures = new Set<string>();
    const counts = emptyCounts();
    await this.renewLease(execution);
    const listings = await this.runStage(
      'token_discovery',
      'birdeye',
      () => this.options.birdeye.newListings(20),
    );
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
        const initial = await this.runStage(
          'provider_enrichment',
          'birdeye',
          () => this.options.birdeye.overview(listing.address),
        );
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
          security = await this.runStage(
            'security_check',
            'goplus',
            () => this.options.goplus.solana(listing.address),
          );
        } catch (error) {
          securityFailure(failures, error, 'SECURITY_PROVIDER_UNAVAILABLE');
          await this.logNonTerminalFailure(execution, error, 'token scan security provider failed');
          security = unavailableSecurity();
        }

        let selected = initial;
        const initialScore = qualifyNewToken(initial, security.available, security.passed).score;

        if (initialScore >= 40) {
          await this.renewLease(execution);
          selected = await this.runStage(
            'provider_enrichment',
            'birdeye',
            () => this.options.birdeye.overview(listing.address),
          );
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
        const dex = await this.optionalDex(execution, listing.address, 'solana', failures);
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
        await this.logNonTerminalFailure(execution, error, 'token scan candidate failed');
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
    const profiles = await this.runStage(
      'token_discovery',
      'dexscreener',
      () => this.options.dexscreener.latestProfiles('solana', 30),
    );
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
        const market = await this.runStage(
          'provider_enrichment',
          'dexscreener',
          () => this.options.dexscreener.market(profile.address, 'solana'),
        );

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
          holderRisk = await this.runStage(
            'security_check',
            'solana_rpc',
            () => this.options.solanaRpc.holderRisk(profile.address),
          );
        } catch (error) {
          securityFailure(failures, error, 'HOLDER_PROVIDER_UNAVAILABLE');
          await this.logNonTerminalFailure(execution, error, 'token scan holder analysis failed');
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
        await this.logNonTerminalFailure(execution, error, 'token scan candidate failed');
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
      const market = await this.runStage(
        'provider_enrichment',
        'dexscreener',
        () => this.options.dexscreener.market(candidate.market.address, 'solana'),
      );
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
    const profiles = await this.runStage(
      'token_discovery',
      'geckoterminal',
      () => this.options.geckoTerminal.latestEthereumTokens(30),
    );
    counts.discovered = profiles.length;

    for (const [index, profile] of profiles.entries()) {
      counts.examined += 1;
      const discoveryId = await this.discoveryId(execution, profile.address);

      try {
        await this.renewLease(execution);
        const market = await this.runStage(
          'provider_enrichment',
          'dexscreener',
          () => this.options.dexscreener.market(profile.address, 'ethereum'),
        );

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
          security = await this.runStage(
            'security_check',
            'goplus',
            () => this.options.goplus.ethereum(profile.address),
          );
        } catch (error) {
          securityFailure(failures, error, 'SECURITY_PROVIDER_UNAVAILABLE');
          await this.logNonTerminalFailure(execution, error, 'token scan security provider failed');
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
        await this.logNonTerminalFailure(execution, error, 'token scan candidate failed');
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
    return this.runStage('opportunity_creation', null, async () => {
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
    });
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
    return this.runStage('discovery_persistence', 'postgresql', async () => {
      const existing = await this.options.database
        .selectFrom('token_discoveries')
        .select('id')
        .where('scan_execution_id', '=', execution.id)
        .where('network_id', '=', execution.network_id)
        .where('asset_address', '=', address)
        .executeTakeFirst();

      return existing?.id ?? newEngineId();
    });
  }

  private persistDiscovery(
    execution: TokenScanExecutionRecord,
    input: TokenDiscoveryInput,
  ): Promise<void> {
    return this.runStage(
      'discovery_persistence',
      'postgresql',
      () => this.options.repository.recordDiscovery(this.options.database, execution, input),
    );
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
    const existing = await this.runStage(
      'discovery_persistence',
      'postgresql',
      () => this.options.database
        .selectFrom('token_discoveries')
        .selectAll()
        .where('scan_execution_id', '=', execution.id)
        .where('network_id', '=', command.network.id)
        .where('asset_address', '=', address)
        .executeTakeFirst(),
    );
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
    execution: TokenScanExecutionRecord,
    address: string,
    chain: 'solana' | 'ethereum',
    failures: Set<string>,
  ): Promise<DexMarket | null> {
    try {
      return await this.runStage(
        'provider_enrichment',
        'dexscreener',
        () => this.options.dexscreener.market(address, chain),
      );
    } catch (error) {
      securityFailure(failures, error, 'MARKET_PROVIDER_UNAVAILABLE');
      await this.logNonTerminalFailure(execution, error, 'token scan optional market provider failed');
      return null;
    }
  }

  private async complete(
    execution: TokenScanExecutionRecord,
    command: TokenScanCommand,
    result: ScanResult,
  ): Promise<void> {
    await this.runStage(
      'terminal_persistence',
      'postgresql',
      () => this.persistTerminalEvent(execution, command, result, null),
    );
  }

  private async fail(
    execution: TokenScanExecutionRecord,
    errorCode: string,
    result: ScanResult,
  ): Promise<void> {
    const command = execution.request as unknown as TokenScanCommand;
    await this.runStage(
      'terminal_persistence',
      'postgresql',
      () => this.persistTerminalEvent(execution, command, result, errorCode),
    );
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
          provider_failures: JSON.stringify(result.providerFailures),
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
        throw new ApplicationError(
          'SCANNER_CLAIM_LOST',
          'The token scan terminal transition is no longer owned',
          409,
          true,
        );
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
      const result = await this.durableProgress(execution);
      const providerFailures = new Set(result.providerFailures);

      if (execution.last_error_code !== null && providerFailureCode(execution.last_error_code)) {
        providerFailures.add(execution.last_error_code);
      }

      await this.runStage(
        'terminal_persistence',
        'postgresql',
        () => this.persistTerminalEvent(
          execution,
          command,
          { counts: result.counts, providerFailures: [...providerFailures].slice(0, 32) },
          'SCANNER_ATTEMPTS_EXHAUSTED',
          true,
        ),
      );
      this.options.logger.error(
        {
          scanExecutionId: execution.id,
          engineOperationId: await this.operationIdFor(execution),
          workflowStage: 'terminal_persistence',
          attemptNumber: execution.attempt_count,
          errorClassification: 'attempts_exhausted',
          errorCode: 'SCANNER_ATTEMPTS_EXHAUSTED',
          priorErrorCode: execution.last_error_code,
          retryable: false,
          terminalOutcome: 'failed',
          counts: result.counts,
          providerFailures: [...providerFailures].slice(0, 32),
        },
        'token scan execution exhausted retry attempts',
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

export function scannerErrorDiagnostic(error: unknown): ScannerErrorDiagnostic {
  const workflow = error instanceof ScannerWorkflowError ? error : undefined;
  const cause = workflow?.cause ?? error;
  const workflowStage = workflow?.workflowStage ?? 'execution';
  const contextualProvider = workflow?.provider ?? null;

  if (cause instanceof ScannerProviderError) {
    return {
      workflowStage,
      provider: cause.provider ?? contextualProvider,
      classification: providerClassification(cause.code),
      errorCode: cause.code,
      sanitizedMessage: cause.message,
      retryable: cause.retryable,
      httpStatus: cause.statusCode,
      timeout: cause.code === 'PROVIDER_TIMEOUT',
      databaseCategory: null,
      diagnosticStack: safeDiagnosticStack(cause),
    };
  }

  if (cause instanceof ApplicationError) {
    return {
      workflowStage,
      provider: contextualProvider,
      classification: cause.code === 'SCANNER_CLAIM_LOST'
        ? 'lease_ownership'
        : cause.code === 'SCANNER_VALUE_INVALID'
          ? 'invalid_scanner_data'
          : 'application',
      errorCode: cause.code,
      sanitizedMessage: sanitizeScannerDiagnostic(cause.message),
      retryable: cause.retryable,
      httpStatus: null,
      timeout: false,
      databaseCategory: null,
      diagnosticStack: safeDiagnosticStack(cause),
    };
  }

  if (contextualProvider === 'postgresql' || postgresErrorCode(cause) !== null) {
    const databaseCategory = databaseErrorCategory(cause);
    const retryable = databaseErrorIsRetryable(cause);

    return {
      workflowStage,
      provider: 'postgresql',
      classification: 'database',
      errorCode: retryable ? 'SCANNER_DATABASE_UNAVAILABLE' : 'SCANNER_DATABASE_FAILED',
      sanitizedMessage: 'PostgreSQL scanner persistence failed',
      retryable,
      httpStatus: null,
      timeout: databaseCategory === 'timeout',
      databaseCategory,
      diagnosticStack: safeDiagnosticStack(cause),
    };
  }

  const message = cause instanceof Error ? cause.message : 'Unknown scanner exception';

  return {
    workflowStage,
    provider: contextualProvider,
    classification: 'unexpected_application',
    errorCode: 'SCANNER_UNEXPECTED_ERROR',
    sanitizedMessage: sanitizeScannerDiagnostic(message),
    retryable: false,
    httpStatus: null,
    timeout: false,
    databaseCategory: null,
    diagnosticStack: safeDiagnosticStack(cause),
  };
}

export function sanitizeScannerDiagnostic(value: string): string {
  return value
    .replace(/\r|\n|\t/g, ' ')
    .replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
    .replace(/\b(authorization|api[_ -]?key|token|secret|password|credential)\b\s*[:=]\s*[^\s,;]+/gi, '$1=[redacted]')
    .replace(/([a-z][a-z0-9+.-]*:\/\/)[^\s/@]+:[^\s@]+@/gi, '$1[redacted]@')
    .replace(/(https?:\/\/[^\s?]+)\?[^\s]+/gi, '$1?[redacted]')
    .replace(/[^\x20-\x7E]/g, '?')
    .slice(0, 512);
}

function safeErrorCode(error: unknown): string {
  return scannerErrorDiagnostic(error).errorCode;
}

function diagnosticLogFields(diagnostic: ScannerErrorDiagnostic): Readonly<Record<string, unknown>> {
  return {
    workflowStage: diagnostic.workflowStage,
    provider: diagnostic.provider,
    errorClassification: diagnostic.classification,
    errorCode: diagnostic.errorCode,
    sanitizedErrorMessage: diagnostic.sanitizedMessage,
    retryable: diagnostic.retryable,
    httpStatus: diagnostic.httpStatus,
    timeout: diagnostic.timeout,
    databaseErrorCategory: diagnostic.databaseCategory,
    diagnosticStack: diagnostic.diagnosticStack,
  };
}

function providerFailureCode(code: string): boolean {
  return code.startsWith('PROVIDER_')
    || code.endsWith('_PROVIDER_UNAVAILABLE')
    || code === 'MARKET_PROVIDER_UNAVAILABLE';
}

function providerClassification(code: string): string {
  if (code === 'PROVIDER_AUTHENTICATION_FAILED') {
    return 'provider_authentication';
  }

  if (code === 'PROVIDER_RATE_LIMITED') {
    return 'provider_rate_limit';
  }

  if (code === 'PROVIDER_TIMEOUT') {
    return 'provider_timeout';
  }

  if (code === 'PROVIDER_SERVICE_FAILED' || code === 'PROVIDER_TRANSPORT_FAILED') {
    return 'provider_service';
  }

  if (code === 'PROVIDER_RESPONSE_MALFORMED' || code === 'PROVIDER_RESPONSE_TOO_LARGE') {
    return 'provider_malformed_response';
  }

  return code === 'PROVIDER_URL_INVALID' ? 'provider_configuration' : 'provider';
}

function postgresErrorCode(error: unknown): string | null {
  if (typeof error !== 'object' || error === null || !('code' in error)) {
    return null;
  }

  const code = (error as { readonly code?: unknown }).code;

  if (typeof code !== 'string') {
    return null;
  }

  return [
    'ETIMEDOUT',
    'ESOCKETTIMEDOUT',
    'ECONNREFUSED',
    'ECONNRESET',
    'EPIPE',
    'ENOTFOUND',
  ].includes(code) || /^[0-9A-Z]{5}$/.test(code)
    ? code
    : null;
}

function databaseErrorCategory(error: unknown): string {
  const code = postgresErrorCode(error);

  if (code === null) {
    return 'unknown';
  }

  if (['ETIMEDOUT', 'ESOCKETTIMEDOUT'].includes(code)) {
    return 'timeout';
  }

  if (['ECONNREFUSED', 'ECONNRESET', 'EPIPE', 'ENOTFOUND'].includes(code) || code.startsWith('08')) {
    return 'connection';
  }

  if (code.startsWith('40')) {
    return 'transaction';
  }

  if (code === '55P03') {
    return 'lock';
  }

  if (code.startsWith('53') || code.startsWith('57') || code.startsWith('58')) {
    return 'resource';
  }

  if (code.startsWith('23')) {
    return 'integrity';
  }

  return 'other';
}

function databaseErrorIsRetryable(error: unknown): boolean {
  return ['connection', 'lock', 'resource', 'timeout', 'transaction']
    .includes(databaseErrorCategory(error));
}

function safeDiagnosticStack(error: unknown): string | null {
  if (!(error instanceof Error) || error.stack === undefined) {
    return null;
  }

  const frames = error.stack.split('\n')
    .slice(1, 9)
    .filter((line) => /^\s*at /.test(line))
    .map((line) => sanitizeScannerDiagnostic(line));

  return frames.length === 0 ? null : frames.join('\n');
}

function retryDelayMs(attemptCount: number): number {
  return Math.min(300_000, 5_000 * (2 ** Math.max(0, attemptCount - 1)));
}

function securityFailure(
  failures: Set<string>,
  error: unknown,
  fallback = 'PROVIDER_UNAVAILABLE',
): void {
  const cause = error instanceof ScannerWorkflowError ? error.cause : error;
  failures.add(cause instanceof ScannerProviderError ? cause.code : fallback);
}
