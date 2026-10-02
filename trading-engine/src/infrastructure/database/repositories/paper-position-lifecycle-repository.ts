import type { Insertable, Selectable, Transaction } from 'kysely';

import type { PaperPositionRegistration } from '../../../contracts/http/paper-position-command.schema.js';
import type {
  Database,
  PaperPositionLifecycleDecisionTable,
  PaperPositionLifecycleTable,
} from '../client.js';
import type { PaperLifecycleResult } from '../../../domain/paper/paper-position-lifecycle.js';

export type PaperPositionLifecycleRecord = Selectable<PaperPositionLifecycleTable>;
export type PaperPositionLifecycleDecisionRecord = Selectable<PaperPositionLifecycleDecisionTable>;

export class PaperPositionLifecycleRepository {
  public async findBySourcePosition(
    transaction: Transaction<Database>,
    sourcePositionId: string,
  ): Promise<PaperPositionLifecycleRecord | undefined> {
    return transaction
      .selectFrom('paper_position_lifecycles')
      .selectAll()
      .where('source_position_id', '=', sourcePositionId)
      .executeTakeFirst();
  }

  public async insert(
    transaction: Transaction<Database>,
    id: string,
    command: PaperPositionRegistration,
    strategySha256: string,
  ): Promise<PaperPositionLifecycleRecord> {
    const values: Insertable<PaperPositionLifecycleTable> = {
      id,
      source_position_id: command.source.paper_position_id,
      source_opportunity_id: command.source.trade_opportunity_id,
      opportunity_id: command.source.engine_opportunity_id,
      control_plane_user_id: command.subject.control_plane_user_id,
      network_id: command.network.id,
      asset_address: command.asset.address,
      symbol: command.asset.symbol ?? null,
      initial_investment_native: command.entry.initial_investment_native,
      entry_market_cap_usd: command.entry.market_cap_usd,
      entry_price_usd: command.entry.price_usd ?? null,
      entry_liquidity_usd: command.entry.liquidity_usd ?? null,
      strategy_snapshot: command.strategy,
      strategy_sha256: strategySha256,
      policy_key: 'laravel-paper-protection',
      policy_version: 1,
      lifecycle_version: 0,
      last_observation_id: null,
      last_observation_sequence: 0,
      last_market_cap_usd: null,
      last_price_usd: null,
      last_liquidity_usd: null,
      peak_market_cap_usd: command.entry.market_cap_usd,
      peak_multiple: '1',
      max_drawdown_percent: '0',
      protection_state: 'none',
      state: 'open',
      registered_at: command.entry.entered_at,
      last_observed_at: null,
      terminal_at: null,
    };

    return transaction
      .insertInto('paper_position_lifecycles')
      .values(values)
      .returningAll()
      .executeTakeFirstOrThrow();
  }

  public async lock(
    transaction: Transaction<Database>,
    id: string,
  ): Promise<PaperPositionLifecycleRecord | undefined> {
    return transaction
      .selectFrom('paper_position_lifecycles')
      .selectAll()
      .where('id', '=', id)
      .forUpdate()
      .executeTakeFirst();
  }

  public async findDecision(
    transaction: Transaction<Database>,
    positionId: string,
    observationId: string,
    sequence: number,
  ): Promise<PaperPositionLifecycleDecisionRecord | undefined> {
    return transaction
      .selectFrom('paper_position_lifecycle_decisions')
      .selectAll()
      .where('position_id', '=', positionId)
      .where((expression) => expression.or([
        expression('observation_id', '=', observationId),
        expression('observation_sequence', '=', sequence),
      ]))
      .executeTakeFirst();
  }

  public async persistDecision(
    transaction: Transaction<Database>,
    input: {
      readonly decisionId: string;
      readonly evaluatedEventId: string;
      readonly exitEventId: string | null;
      readonly position: PaperPositionLifecycleRecord;
      readonly observationId: string;
      readonly sequence: number;
      readonly requestSha256: string;
      readonly resultSha256: string;
      readonly result: PaperLifecycleResult;
      readonly marketCap: string;
      readonly price: string | null;
      readonly liquidity: string | null;
      readonly observedAt: string;
      readonly correlationId: string;
      readonly traceparent: string;
      readonly evidence: PaperPositionLifecycleDecisionTable['evidence'];
    },
  ): Promise<void> {
    const version = input.position.lifecycle_version + 1;
    const nextMaxDrawdown = compareSigned(
      input.result.drawdownPercent,
      input.position.max_drawdown_percent,
    ) < 0
      ? input.result.drawdownPercent
      : input.position.max_drawdown_percent;

    await transaction.insertInto('paper_position_lifecycle_decisions').values({
      id: input.decisionId,
      position_id: input.position.id,
      observation_id: input.observationId,
      observation_sequence: input.sequence,
      lifecycle_version: version,
      request_sha256: input.requestSha256,
      result_sha256: input.resultSha256,
      evaluated_event_id: input.evaluatedEventId,
      exit_event_id: input.exitEventId,
      decision: input.result.decision,
      exit_type: input.result.exitType,
      observed_market_cap_usd: input.marketCap,
      observed_price_usd: input.price,
      observed_liquidity_usd: input.liquidity,
      observed_multiple: input.result.observedMultiple,
      trigger_multiple: input.result.triggerMultiple,
      peak_market_cap_usd: input.result.peakMarketCap,
      peak_multiple: input.result.peakMultiple,
      drawdown_percent: input.result.drawdownPercent,
      protection_before: input.result.protectionBefore,
      protection_after: input.result.protectionAfter,
      transitions: JSON.stringify(input.result.transitions),
      evidence: input.evidence,
      correlation_id: input.correlationId,
      traceparent: input.traceparent,
      observed_at: input.observedAt,
    }).execute();

    await transaction
      .updateTable('paper_position_lifecycles')
      .set({
        lifecycle_version: version,
        last_observation_id: input.observationId,
        last_observation_sequence: input.sequence,
        last_market_cap_usd: input.marketCap,
        last_price_usd: input.price,
        last_liquidity_usd: input.liquidity,
        peak_market_cap_usd: input.result.peakMarketCap,
        peak_multiple: input.result.peakMultiple,
        max_drawdown_percent: nextMaxDrawdown,
        protection_state: input.result.protectionAfter,
        state: input.result.decision === 'EXIT' ? 'terminal' : 'open',
        last_observed_at: input.observedAt,
        terminal_at: input.result.decision === 'EXIT' ? input.observedAt : null,
        updated_at: new Date(),
      })
      .where('id', '=', input.position.id)
      .executeTakeFirstOrThrow();
  }
}

function compareSigned(left: string, right: string): number {
  const leftValue = decimalTuple(left);
  const rightValue = decimalTuple(right);
  const scale = Math.max(leftValue.scale, rightValue.scale);
  const normalizedLeft = leftValue.value * (10n ** BigInt(scale - leftValue.scale));
  const normalizedRight = rightValue.value * (10n ** BigInt(scale - rightValue.scale));

  return normalizedLeft < normalizedRight ? -1 : normalizedLeft > normalizedRight ? 1 : 0;
}

function decimalTuple(value: string): { readonly value: bigint; readonly scale: number } {
  const negative = value.startsWith('-');
  const unsigned = negative ? value.slice(1) : value;
  const [integer = '0', fraction = ''] = unsigned.split('.', 2);
  const magnitude = BigInt(integer + fraction);

  return { value: negative ? -magnitude : magnitude, scale: fraction.length };
}
