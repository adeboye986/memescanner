import type { Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { EngineConfig } from '../config/env.js';
import {
  evaluateOpportunitySnapshot,
  type OpportunityEvaluationResult,
} from '../domain/opportunities/evaluate-opportunity.js';
import type { Database } from '../infrastructure/database/client.js';
import type {
  OpportunityEvaluationContext,
  OpportunityEvaluationRepository,
} from '../infrastructure/database/repositories/opportunity-evaluation-repository.js';
import type { OutboxRepository } from '../infrastructure/database/repositories/outbox-repository.js';
import { newEngineId } from '../shared/ids/id.js';

export interface OpportunityEvaluationDispatchSummary {
  readonly discovered: number;
  readonly claimed: number;
  readonly evaluated: number;
  readonly duplicates: number;
  readonly failed: number;
}

export class OpportunityEvaluationDispatcher {
  private readonly claimedBy = newEngineId();

  public constructor(
    private readonly config: EngineConfig,
    private readonly database: Kysely<Database>,
    private readonly evaluations: OpportunityEvaluationRepository,
    private readonly outbox: OutboxRepository,
    private readonly logger: Logger,
    private readonly evaluate: (
      request: OpportunityEvaluationContext['request'],
      sourceRequestSha256: string,
    ) => OpportunityEvaluationResult = evaluateOpportunitySnapshot,
  ) {}

  public async dispatchBatch(): Promise<OpportunityEvaluationDispatchSummary> {
    const discovered = await this.evaluations.discoverTasks(this.database);
    const tasks = await this.evaluations.claimBatch(
      this.database,
      this.claimedBy,
      this.config.evaluationBatchSize,
      this.config.evaluationClaimTtlMs,
    );
    let evaluated = 0;
    let duplicates = 0;
    let failed = 0;

    for (const task of tasks) {
      try {
        const context = await this.evaluations.loadContext(this.database, task);
        this.evaluations.assertPolicy(context);
        const result = this.evaluate(context.request, context.sourceRequestSha256);
        const persistence = await this.evaluations.persistResult(
          this.database,
          this.outbox,
          task,
          context,
          newEngineId(),
          newEngineId(),
          result,
        );

        if (persistence === 'created') {
          evaluated += 1;
        } else {
          duplicates += 1;
        }
      } catch (error) {
        failed += 1;
        await this.evaluations.markFailed(this.database, task);
        this.logger.error(
          {
            err: error,
            opportunityId: task.opportunity_id,
            policyKey: task.policy_key,
            policyVersion: task.policy_version,
          },
          'opportunity snapshot evaluation failed',
        );
      }
    }

    return {
      discovered,
      claimed: tasks.length,
      evaluated,
      duplicates,
      failed,
    };
  }

  public async shutdown(): Promise<void> {
    await this.evaluations.releaseClaims(this.database, this.claimedBy);
  }
}
