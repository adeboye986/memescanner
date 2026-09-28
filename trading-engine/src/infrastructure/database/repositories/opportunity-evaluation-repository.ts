import {
  sql,
  type Insertable,
  type Kysely,
  type Selectable,
  type Transaction,
  type Updateable,
} from 'kysely';

import type { EventEnvelope } from '../../../contracts/events/event-envelope.schema.js';
import type { OpportunityCommand } from '../../../contracts/http/opportunity-command.schema.js';
import type { OpportunityEvaluationResult } from '../../../domain/opportunities/evaluate-opportunity.js';
import {
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from '../../../domain/opportunities/evaluation-policy.js';
import { hashCanonicalJson } from '../../../shared/json/canonical-json.js';
import type {
  Database,
  EvaluationPolicyTable,
  JsonValue,
  OpportunityEvaluationTable,
  OpportunityEvaluationTaskTable,
} from '../client.js';
import type { OutboxRepository } from './outbox-repository.js';

export type OpportunityEvaluationTaskRecord = Selectable<OpportunityEvaluationTaskTable>;

export interface OpportunityEvaluationContext {
  readonly opportunityId: string;
  readonly request: OpportunityCommand;
  readonly sourceRequestSha256: string;
  readonly sourceEventId: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly policy: Selectable<EvaluationPolicyTable>;
}

export class OpportunityEvaluationRepository {
  public async discoverTasks(database: Kysely<Database>): Promise<number> {
    const result = await sql`
      insert into opportunity_evaluation_tasks (
        opportunity_id,
        policy_key,
        policy_version,
        source_event_id
      )
      select
        opportunity.id,
        policy.policy_key,
        policy.policy_version,
        source_event.id
      from opportunities as opportunity
      join event_outbox as source_event
        on source_event.event_type = 'opportunity.recorded.v1'
        and source_event.aggregate_type = 'opportunity'
        and source_event.aggregate_id = opportunity.id
      join evaluation_policies as policy
        on policy.policy_key = ${OPPORTUNITY_EVALUATION_POLICY_V1.policy_key}
        and policy.policy_version = ${OPPORTUNITY_EVALUATION_POLICY_V1.policy_version}
        and opportunity.created_at >= policy.created_at
      on conflict (opportunity_id, policy_key, policy_version) do nothing
    `.execute(database);

    return Number(result.numAffectedRows ?? 0n);
  }

  public async claimBatch(
    database: Kysely<Database>,
    claimedBy: string,
    batchSize: number,
    claimTtlMs: number,
  ): Promise<readonly OpportunityEvaluationTaskRecord[]> {
    const staleBefore = new Date(Date.now() - claimTtlMs);

    return database.transaction().execute(async (transaction) => {
      const candidates = await transaction
        .selectFrom('opportunity_evaluation_tasks')
        .select(['opportunity_id', 'policy_key', 'policy_version'])
        .where((expression) => expression.or([
          expression.and([
            expression('status', '=', 'pending'),
            expression('available_at', '<=', new Date()),
          ]),
          expression.and([
            expression('status', '=', 'processing'),
            expression('claimed_at', '<', staleBefore),
          ]),
        ]))
        .orderBy('created_at', 'asc')
        .limit(batchSize)
        .forUpdate()
        .skipLocked()
        .execute();

      if (candidates.length === 0) {
        return [];
      }

      const opportunityIds = candidates.map((candidate) => candidate.opportunity_id);

      return transaction
        .updateTable('opportunity_evaluation_tasks')
        .set({
          status: 'processing',
          claimed_by: claimedBy,
          claimed_at: new Date(),
        })
        .where('opportunity_id', 'in', opportunityIds)
        .where('policy_key', '=', OPPORTUNITY_EVALUATION_POLICY_V1.policy_key)
        .where('policy_version', '=', OPPORTUNITY_EVALUATION_POLICY_V1.policy_version)
        .returningAll()
        .execute();
    });
  }

  public async loadContext(
    database: Kysely<Database>,
    task: OpportunityEvaluationTaskRecord,
  ): Promise<OpportunityEvaluationContext> {
    const row = await database
      .selectFrom('opportunity_evaluation_tasks as task')
      .innerJoin('opportunities as opportunity', 'opportunity.id', 'task.opportunity_id')
      .innerJoin('evaluation_policies as policy', (join) => join
        .onRef('policy.policy_key', '=', 'task.policy_key')
        .onRef('policy.policy_version', '=', 'task.policy_version'))
      .innerJoin('event_outbox as source_event', 'source_event.id', 'task.source_event_id')
      .select([
        'opportunity.id as opportunity_id',
        'opportunity.accepted_request',
        'opportunity.request_sha256 as source_request_sha256',
        'source_event.id as source_event_id',
        'source_event.correlation_id',
        'source_event.traceparent',
        'policy.policy_key',
        'policy.policy_version',
        'policy.algorithm_key',
        'policy.algorithm_version',
        'policy.definition',
        'policy.definition_sha256',
        'policy.created_at as policy_created_at',
      ])
      .where('task.opportunity_id', '=', task.opportunity_id)
      .where('task.policy_key', '=', task.policy_key)
      .where('task.policy_version', '=', task.policy_version)
      .executeTakeFirstOrThrow();

    if (hashCanonicalJson(row.accepted_request) !== row.source_request_sha256) {
      throw new Error('Stored opportunity request no longer matches its immutable hash');
    }

    const request = row.accepted_request as unknown as OpportunityCommand;

    return {
      opportunityId: row.opportunity_id,
      request,
      sourceRequestSha256: row.source_request_sha256,
      sourceEventId: row.source_event_id,
      correlationId: row.correlation_id,
      traceparent: row.traceparent,
      policy: {
        policy_key: row.policy_key,
        policy_version: row.policy_version,
        algorithm_key: row.algorithm_key,
        algorithm_version: row.algorithm_version,
        definition: row.definition,
        definition_sha256: row.definition_sha256,
        created_at: row.policy_created_at,
      },
    };
  }

  public assertPolicy(context: OpportunityEvaluationContext): void {
    const policy = context.policy;

    if (policy.policy_key !== OPPORTUNITY_EVALUATION_POLICY_V1.policy_key
      || policy.policy_version !== OPPORTUNITY_EVALUATION_POLICY_V1.policy_version
      || policy.algorithm_key !== OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_key
      || policy.algorithm_version !== OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_version
      || policy.definition_sha256 !== OPPORTUNITY_EVALUATION_POLICY_V1_SHA256
      || hashCanonicalJson(policy.definition) !== OPPORTUNITY_EVALUATION_POLICY_V1_SHA256) {
      throw new Error('The configured opportunity evaluation policy does not match v1');
    }
  }

  public async persistResult(
    database: Kysely<Database>,
    outbox: OutboxRepository,
    task: OpportunityEvaluationTaskRecord,
    context: OpportunityEvaluationContext,
    evaluationId: string,
    eventId: string,
    result: OpportunityEvaluationResult,
  ): Promise<'created' | 'duplicate'> {
    return database.transaction().execute(async (transaction) => {
      const values: Insertable<OpportunityEvaluationTable> = {
        id: evaluationId,
        opportunity_id: context.opportunityId,
        policy_key: context.policy.policy_key,
        policy_version: context.policy.policy_version,
        policy_snapshot: context.policy.definition,
        policy_definition_sha256: context.policy.definition_sha256,
        source_request_sha256: context.sourceRequestSha256,
        evaluation_input_sha256: result.evaluation_input_sha256,
        outcome: result.outcome,
        reason_codes: JSON.stringify(result.reason_codes),
        advisory_codes: JSON.stringify(result.advisory_codes),
        evidence: result.evidence as unknown as JsonValue,
        result_sha256: result.result_sha256,
        correlation_id: context.correlationId,
        traceparent: context.traceparent,
      };
      const inserted = await transaction
        .insertInto('opportunity_evaluations')
        .values(values)
        .onConflict((conflict) => conflict
          .columns(['opportunity_id', 'policy_key', 'policy_version'])
          .doNothing())
        .returning('id')
        .executeTakeFirst();

      if (inserted !== undefined) {
        await outbox.enqueue(
          transaction,
          evaluatedEnvelope(context, evaluationId, eventId, result),
        );
      } else {
        const existing = await transaction
          .selectFrom('opportunity_evaluations')
          .select('result_sha256')
          .where('opportunity_id', '=', context.opportunityId)
          .where('policy_key', '=', context.policy.policy_key)
          .where('policy_version', '=', context.policy.policy_version)
          .executeTakeFirstOrThrow();

        if (existing.result_sha256 !== result.result_sha256) {
          throw new Error('An immutable evaluation exists with a different result hash');
        }
      }

      await this.markCompleted(transaction, task);

      return inserted === undefined ? 'duplicate' : 'created';
    });
  }

  public async markFailed(
    database: Kysely<Database>,
    task: OpportunityEvaluationTaskRecord,
  ): Promise<void> {
    const attemptCount = task.attempt_count + 1;
    const delayMs = Math.min(60_000, 500 * 2 ** Math.min(attemptCount - 1, 7));
    const values: Updateable<OpportunityEvaluationTaskTable> = {
      status: 'pending',
      available_at: new Date(Date.now() + delayMs),
      claimed_by: null,
      claimed_at: null,
      attempt_count: attemptCount,
      last_error_code: 'EVALUATION_FAILED',
    };
    const updated = await database
      .updateTable('opportunity_evaluation_tasks')
      .set(values)
      .where('opportunity_id', '=', task.opportunity_id)
      .where('policy_key', '=', task.policy_key)
      .where('policy_version', '=', task.policy_version)
      .where('status', '=', 'processing')
      .where('claimed_by', '=', task.claimed_by)
      .executeTakeFirst();

    if (updated.numUpdatedRows !== 1n) {
      throw new Error('Failed evaluation claim is no longer owned');
    }
  }

  public async releaseClaims(database: Kysely<Database>, claimedBy: string): Promise<void> {
    await database
      .updateTable('opportunity_evaluation_tasks')
      .set({
        status: 'pending',
        available_at: new Date(),
        claimed_by: null,
        claimed_at: null,
      })
      .where('status', '=', 'processing')
      .where('claimed_by', '=', claimedBy)
      .execute();
  }

  private async markCompleted(
    transaction: Transaction<Database>,
    task: OpportunityEvaluationTaskRecord,
  ): Promise<void> {
    const updated = await transaction
      .updateTable('opportunity_evaluation_tasks')
      .set({
        status: 'completed',
        attempt_count: task.attempt_count + 1,
        last_error_code: null,
        completed_at: new Date(),
        claimed_by: null,
        claimed_at: null,
      })
      .where('opportunity_id', '=', task.opportunity_id)
      .where('policy_key', '=', task.policy_key)
      .where('policy_version', '=', task.policy_version)
      .where('status', '=', 'processing')
      .where('claimed_by', '=', task.claimed_by)
      .executeTakeFirst();

    if (updated.numUpdatedRows !== 1n) {
      throw new Error('Completed evaluation claim is no longer owned');
    }
  }
}

function evaluatedEnvelope(
  context: OpportunityEvaluationContext,
  evaluationId: string,
  eventId: string,
  result: OpportunityEvaluationResult,
): EventEnvelope {
  const payload = {
    evaluation_id: evaluationId,
    opportunity_id: context.opportunityId,
    policy: {
      key: context.policy.policy_key,
      version: context.policy.policy_version,
      algorithm_key: context.policy.algorithm_key,
      algorithm_version: context.policy.algorithm_version,
      definition_sha256: context.policy.definition_sha256,
    },
    source: {
      request_sha256: context.sourceRequestSha256,
      evaluation_input_sha256: result.evaluation_input_sha256,
    },
    outcome: result.outcome,
    reason_codes: result.reason_codes,
    advisory_codes: result.advisory_codes,
    evidence: result.evidence,
    result_sha256: result.result_sha256,
  };

  return {
    event_id: eventId,
    event_type: 'opportunity.evaluated.v1',
    schema_version: 1,
    occurred_at: new Date().toISOString(),
    producer: 'trading-engine',
    aggregate_type: 'opportunity_evaluation',
    aggregate_id: evaluationId,
    aggregate_version: 1,
    correlation_id: context.correlationId,
    causation_id: context.sourceEventId,
    idempotency_key:
      `evaluation:${context.opportunityId}:${context.policy.policy_key}:${context.policy.policy_version}`,
    traceparent: context.traceparent,
    payload,
    payload_sha256: hashCanonicalJson(payload),
  };
}
