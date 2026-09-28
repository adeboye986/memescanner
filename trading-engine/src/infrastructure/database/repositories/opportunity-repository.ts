import type { Insertable, Kysely, Selectable, Transaction } from 'kysely';

import type { OpportunityCommand } from '../../../contracts/http/opportunity-command.schema.js';
import type { Database, JsonValue, OpportunityTable } from '../client.js';

type Connection = Kysely<Database> | Transaction<Database>;
export type OpportunityRecord = Selectable<OpportunityTable>;

export class OpportunityRepository {
  public async insert(
    connection: Connection,
    id: string,
    command: OpportunityCommand,
    requestSha256: string,
  ): Promise<OpportunityRecord> {
    const market = command.market_snapshot;
    const pair = market.pair;
    const values: Insertable<OpportunityTable> = {
      id,
      aggregate_version: 1,
      state: 'recorded',
      source_system: command.source.system,
      source_opportunity_id: command.source.opportunity_id,
      control_plane_user_id: command.subject.control_plane_user_id,
      discovery_key: command.source.discovery_key,
      scanner: command.source.scanner,
      network_id: command.network.id,
      asset_address: command.asset.address,
      symbol: command.asset.symbol ?? null,
      name: command.asset.name ?? null,
      price_usd: market.price_usd?.value ?? null,
      price_provider: market.price_usd?.provider ?? null,
      market_cap_usd: market.market_cap_usd?.value ?? null,
      market_cap_provider: market.market_cap_usd?.provider ?? null,
      liquidity_usd: market.liquidity_usd?.value ?? null,
      liquidity_provider: market.liquidity_usd?.provider ?? null,
      volume_usd: market.volume_usd?.value ?? null,
      volume_provider: market.volume_usd?.provider ?? null,
      volume_window: market.volume_usd?.window ?? null,
      pair_address: pair?.address ?? null,
      dex: pair?.dex ?? null,
      pair_provider: pair?.provider ?? null,
      qualified_at: new Date(command.qualification.qualified_at),
      discovery_market_cap_usd: command.qualification.discovery_market_cap_usd ?? null,
      move_since_discovery_percent:
        command.qualification.move_since_discovery_percent ?? null,
      classification: command.qualification.classification ?? null,
      security: (command.security as JsonValue | undefined) ?? null,
      accepted_request: command,
      request_sha256: requestSha256,
    };

    return connection
      .insertInto('opportunities')
      .values(values)
      .returningAll()
      .executeTakeFirstOrThrow();
  }
}
