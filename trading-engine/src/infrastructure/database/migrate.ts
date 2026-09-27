import { existsSync, promises as fs } from 'node:fs';
import path from 'node:path';
import { loadEnvFile } from 'node:process';
import { fileURLToPath } from 'node:url';

import { sql, type Kysely } from 'kysely';
import {
  FileMigrationProvider,
  Migrator,
  type MigrationResultSet,
} from 'kysely/migration';

import { loadConfig } from '../../config/env.js';
import { createDatabase, type Database } from './client.js';

const migrationLockId = 7_421_901;

export async function migrateDatabase(
  database: Kysely<Database>,
): Promise<MigrationResultSet> {
  return database.connection().execute(async (connection) => {
    await sql`select pg_advisory_lock(${migrationLockId})`.execute(connection);

    try {
      const migrationFolder = path.join(
        path.dirname(fileURLToPath(import.meta.url)),
        'migrations',
      );
      const migrator = new Migrator({
        db: connection,
        provider: new FileMigrationProvider({
          fs,
          path,
          migrationFolder,
        }),
      });
      const result = await migrator.migrateToLatest();

      const failedMigration = result.results?.find(
        (migration) => migration.status === 'Error',
      );

      if (result.error !== undefined) {
        const cause = result.error instanceof Error
          ? result.error
          : new Error("Unknown migration failure");

        throw new Error("Database migration failed", { cause });
      }

      if (failedMigration !== undefined) {
        throw new Error("Migration " + failedMigration.migrationName + " failed");
      }

      return result;
    } finally {
      await sql`select pg_advisory_unlock(${migrationLockId})`.execute(connection);
    }
  });
}

async function run(): Promise<void> {
  if (existsSync('.env')) {
    loadEnvFile('.env');
  }

  const config = loadConfig();
  const database = createDatabase(config);

  try {
    const result = await migrateDatabase(database);

    for (const migration of result.results ?? []) {
      process.stdout.write(
        JSON.stringify({
          migration: migration.migrationName,
          status: migration.status,
        }) + '\n',
      );
    }
  } finally {
    await database.destroy();
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await run();
}
