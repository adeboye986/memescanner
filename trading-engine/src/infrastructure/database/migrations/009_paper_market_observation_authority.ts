import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .alterTable('paper_position_monitoring_tasks')
    .addColumn('observation_authority', 'varchar(32)', (column) =>
      column.notNull().defaultTo('shadow'),
    )
    .addColumn('next_financial_observation_sequence', 'integer', (column) =>
      column.notNull().defaultTo(1),
    )
    .execute();

  await sql`
    alter table paper_position_monitoring_tasks
      add constraint paper_position_monitoring_tasks_authority_check
      check (observation_authority in ('shadow', 'financial')),
      add constraint paper_position_monitoring_tasks_financial_sequence_check
      check (next_financial_observation_sequence > 0)
  `.execute(database);

  await sql`
    create index paper_position_monitoring_tasks_financial_due_index
      on paper_position_monitoring_tasks (
        next_observation_due_at,
        next_financial_observation_sequence,
        position_id
      )
      where monitoring_state = 'pending'
        and observation_authority = 'financial'
  `.execute(database);
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .dropIndex('paper_position_monitoring_tasks_financial_due_index')
    .ifExists()
    .execute();
  await sql`
    alter table paper_position_monitoring_tasks
      drop constraint if exists paper_position_monitoring_tasks_financial_sequence_check,
      drop constraint if exists paper_position_monitoring_tasks_authority_check
  `.execute(database);
  await database.schema
    .alterTable('paper_position_monitoring_tasks')
    .dropColumn('next_financial_observation_sequence')
    .dropColumn('observation_authority')
    .execute();
}