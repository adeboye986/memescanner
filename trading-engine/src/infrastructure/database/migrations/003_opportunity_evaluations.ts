import { sql, type Kysely } from 'kysely';

import {
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from '../../../domain/opportunities/evaluation-policy.js';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('evaluation_policies')
    .addColumn('policy_key', 'varchar(128)', (column) => column.notNull())
    .addColumn('policy_version', 'integer', (column) => column.notNull())
    .addColumn('algorithm_key', 'varchar(128)', (column) => column.notNull())
    .addColumn('algorithm_version', 'integer', (column) => column.notNull())
    .addColumn('definition', 'jsonb', (column) => column.notNull())
    .addColumn('definition_sha256', 'char(64)', (column) => column.notNull().unique())
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addPrimaryKeyConstraint('evaluation_policies_primary', [
      'policy_key',
      'policy_version',
    ])
    .addCheckConstraint('evaluation_policies_version_check', sql`policy_version >= 1`)
    .addCheckConstraint('evaluation_algorithms_version_check', sql`algorithm_version >= 1`)
    .execute();

  await sql`
    insert into evaluation_policies (
      policy_key,
      policy_version,
      algorithm_key,
      algorithm_version,
      definition,
      definition_sha256
    ) values (
      ${OPPORTUNITY_EVALUATION_POLICY_V1.policy_key},
      ${OPPORTUNITY_EVALUATION_POLICY_V1.policy_version},
      ${OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_key},
      ${OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_version},
      ${JSON.stringify(OPPORTUNITY_EVALUATION_POLICY_V1)}::jsonb,
      ${OPPORTUNITY_EVALUATION_POLICY_V1_SHA256}
    )
  `.execute(database);

  await database.schema
    .createTable('opportunity_evaluation_tasks')
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunities.id').onDelete('restrict'),
    )
    .addColumn('policy_key', 'varchar(128)', (column) => column.notNull())
    .addColumn('policy_version', 'integer', (column) => column.notNull())
    .addColumn('source_event_id', 'varchar(26)', (column) =>
      column.notNull().references('event_outbox.id').onDelete('restrict'),
    )
    .addColumn('status', 'varchar(16)', (column) => column.notNull().defaultTo('pending'))
    .addColumn('available_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addColumn('claimed_by', 'varchar(64)')
    .addColumn('claimed_at', 'timestamptz')
    .addColumn('attempt_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('last_error_code', 'varchar(128)')
    .addColumn('completed_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addPrimaryKeyConstraint('opportunity_evaluation_tasks_primary', [
      'opportunity_id',
      'policy_key',
      'policy_version',
    ])
    .addForeignKeyConstraint(
      'opportunity_evaluation_tasks_policy_foreign',
      ['policy_key', 'policy_version'],
      'evaluation_policies',
      ['policy_key', 'policy_version'],
      (constraint) => constraint.onDelete('restrict'),
    )
    .addCheckConstraint(
      'opportunity_evaluation_tasks_status_check',
      sql`status in ('pending', 'processing', 'completed')`,
    )
    .addCheckConstraint(
      'opportunity_evaluation_tasks_claim_check',
      sql`(status = 'processing' and claimed_by is not null and claimed_at is not null)
        or (status <> 'processing' and claimed_by is null and claimed_at is null)`,
    )
    .execute();

  await database.schema
    .createIndex('opportunity_evaluation_tasks_claim_index')
    .on('opportunity_evaluation_tasks')
    .columns(['status', 'available_at', 'created_at'])
    .execute();

  await database.schema
    .createTable('opportunity_evaluations')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunities.id').onDelete('restrict'),
    )
    .addColumn('policy_key', 'varchar(128)', (column) => column.notNull())
    .addColumn('policy_version', 'integer', (column) => column.notNull())
    .addColumn('policy_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('policy_definition_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('source_request_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('evaluation_input_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('outcome', 'varchar(16)', (column) => column.notNull())
    .addColumn('reason_codes', 'jsonb', (column) => column.notNull())
    .addColumn('advisory_codes', 'jsonb', (column) => column.notNull())
    .addColumn('evidence', 'jsonb', (column) => column.notNull())
    .addColumn('result_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addUniqueConstraint('opportunity_evaluations_opportunity_policy_unique', [
      'opportunity_id',
      'policy_key',
      'policy_version',
    ])
    .addForeignKeyConstraint(
      'opportunity_evaluations_policy_foreign',
      ['policy_key', 'policy_version'],
      'evaluation_policies',
      ['policy_key', 'policy_version'],
      (constraint) => constraint.onDelete('restrict'),
    )
    .addCheckConstraint(
      'opportunity_evaluations_outcome_check',
      sql`outcome in ('passed', 'failed', 'indeterminate')`,
    )
    .execute();

  await sql`
    create function reject_opportunity_evaluation_mutation()
    returns trigger
    language plpgsql
    as $$
    begin
      raise exception 'evaluation policies and completed evaluations are append-only';
    end;
    $$
  `.execute(database);

  await sql`
    create trigger evaluation_policies_append_only
    before update or delete on evaluation_policies
    for each row execute function reject_opportunity_evaluation_mutation()
  `.execute(database);

  await sql`
    create trigger opportunity_evaluations_append_only
    before update or delete on opportunity_evaluations
    for each row execute function reject_opportunity_evaluation_mutation()
  `.execute(database);
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('opportunity_evaluations').ifExists().execute();
  await database.schema.dropTable('opportunity_evaluation_tasks').ifExists().execute();
  await database.schema.dropTable('evaluation_policies').ifExists().execute();
  await sql`drop function if exists reject_opportunity_evaluation_mutation()`.execute(database);
}
