import type { Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { EngineConfig } from '../config/env.js';
import type { Database } from '../infrastructure/database/client.js';
import type { OutboxRepository } from '../infrastructure/database/repositories/outbox-repository.js';
import type { LaravelWebhookClient } from '../infrastructure/http/laravel-webhook-client.js';
import { newEngineId } from '../shared/ids/id.js';

export interface DispatchSummary {
  readonly didWork: boolean;
  readonly claimed: number;
  readonly published: number;
  readonly failed: number;
}

export class OutboxDispatcher {
  private readonly claimedBy = newEngineId();

  public constructor(
    private readonly config: EngineConfig,
    private readonly database: Kysely<Database>,
    private readonly outbox: OutboxRepository,
    private readonly webhook: LaravelWebhookClient,
    private readonly logger: Logger,
  ) {}

  public async dispatchBatch(): Promise<DispatchSummary> {
    const events = await this.outbox.claimBatch(
      this.database,
      this.claimedBy,
      this.config.outboxBatchSize,
      this.config.outboxClaimTtlMs,
    );
    let published = 0;
    let failed = 0;

    for (const event of events) {
      const result = await this.webhook.deliver(event.envelope);

      if (result.accepted && result.status !== null) {
        await this.outbox.markPublished(this.database, event, result.status);
        published += 1;
        this.logger.info(
          {
            eventId: event.id,
            correlationId: event.correlation_id,
            traceparent: event.traceparent,
          },
          'outbox event published',
        );
      } else {
        await this.outbox.markFailed(
          this.database,
          event,
          result.errorCode ?? 'WEBHOOK_REJECTED',
          result.status,
        );
        failed += 1;
        this.logger.warn(
          {
            eventId: event.id,
            status: result.status,
            errorCode: result.errorCode,
            correlationId: event.correlation_id,
            traceparent: event.traceparent,
          },
          'outbox event delivery failed',
        );
      }
    }

    return {
      didWork: events.length > 0,
      claimed: events.length,
      published,
      failed,
    };
  }

  public async shutdown(): Promise<void> {
    await this.outbox.releaseClaims(this.database, this.claimedBy);
  }
}
