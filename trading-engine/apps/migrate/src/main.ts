import { fileURLToPath } from 'node:url';

import { loadDatabaseConfig } from '../../../src/config/env.js';
import { migrateConfiguredDatabase } from '../../../src/infrastructure/database/migrate.js';
import { loadLocalEnvironment } from '../../../src/infrastructure/runtime/process-lifecycle.js';

export async function runDatabaseMigrationCli(
  env: NodeJS.ProcessEnv = process.env,
): Promise<void> {
  const result = await migrateConfiguredDatabase(loadDatabaseConfig(env));

  for (const migration of result.results ?? []) {
    process.stdout.write(
      JSON.stringify({
        migration: migration.migrationName,
        status: migration.status,
      }) + '\n',
    );
  }
}

async function main(): Promise<void> {
  loadLocalEnvironment();
  await runDatabaseMigrationCli();
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  void main().catch((error: unknown): void => {
    const message = error instanceof Error
      ? (error.stack ?? error.message)
      : String(error);

    process.stderr.write(message + '\n');
    process.exitCode = 1;
  });
}
