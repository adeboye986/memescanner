import { describe, expect, it } from 'vitest';

import {
  DatabaseSchemaCompatibilityError,
  evaluateDatabaseSchemaCompatibility,
  formatDatabaseSchemaVersion,
  type DatabaseSchemaFeatureConfig,
  type DatabaseSchemaSnapshot,
} from '../../src/infrastructure/database/schema-compatibility.js';

const disabled: DatabaseSchemaFeatureConfig = {
  paperEntryEnabled: false,
  paperMarketMonitoringEnabled: false,
  paperMarketMonitorCanaryUserIds: [],
};

function snapshot(version: 6 | 7 | 8): DatabaseSchemaSnapshot {
  return {
    appliedMigrations: [
      '001_foundation',
      '002_opportunities',
      '003_opportunity_evaluations',
      '004_paper_position_lifecycle',
      '005_paper_financial_entry',
      '006_paper_financial_lifecycle',
      ...(version >= 7 ? ['007_paper_market_monitoring'] : []),
      ...(version >= 8 ? ['008_paper_market_shadow_observations'] : []),
    ],
    paperFinancialSchemaAvailable: true,
    paperEntrySchemaAvailable: version >= 7,
    paperShadowMonitoringSchemaAvailable: version >= 8,
  };
}

describe('database schema compatibility', () => {
  it('requires the baseline financial schema even with entry and monitoring disabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(disabled, {
      appliedMigrations: [],
      paperFinancialSchemaAvailable: false,
      paperEntrySchemaAvailable: false,
      paperShadowMonitoringSchemaAvailable: false,
    });

    expect(result).toEqual({
      compatible: false,
      detectedVersion: 0,
      requiredVersion: 6,
      missingCapabilities: [
        'MIGRATION_006_NOT_APPLIED',
        'PAPER_FINANCIAL_SCHEMA_UNAVAILABLE',
      ],
    });
  });

  it('accepts schema 006 while PAPER entry and monitoring remain disabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(disabled, snapshot(6));

    expect(result).toEqual({
      compatible: true,
      detectedVersion: 6,
      requiredVersion: 6,
      missingCapabilities: [],
    });
  });

  it('requires schema 007 before PAPER financial entry can be enabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(6),
    );

    expect(result).toEqual({
      compatible: false,
      detectedVersion: 6,
      requiredVersion: 7,
      missingCapabilities: [
        'MIGRATION_007_NOT_APPLIED',
        'PAPER_ENTRY_MONITORING_TASK_SCHEMA_UNAVAILABLE',
      ],
    });
  });

  it('accepts schema 007 for PAPER entry while monitoring remains disabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(7),
    );

    expect(result.compatible).toBe(true);
    expect(result.detectedVersion).toBe(7);
    expect(result.requiredVersion).toBe(7);
  });

  it('requires schema 008 before shadow monitoring can be enabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      {
        ...disabled,
        paperEntryEnabled: true,
        paperMarketMonitoringEnabled: true,
        paperMarketMonitorCanaryUserIds: ['1'],
      },
      snapshot(7),
    );

    expect(result.compatible).toBe(false);
    expect(result.detectedVersion).toBe(7);
    expect(result.requiredVersion).toBe(8);
    expect(result.missingCapabilities).toEqual([
      'MIGRATION_008_NOT_APPLIED',
      'PAPER_SHADOW_OBSERVATION_SCHEMA_UNAVAILABLE',
    ]);
  });

  it('accepts schema 008 for a valid monitoring canary', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      {
        ...disabled,
        paperEntryEnabled: true,
        paperMarketMonitoringEnabled: true,
        paperMarketMonitorCanaryUserIds: ['1'],
      },
      snapshot(8),
    );

    expect(result.compatible).toBe(true);
    expect(result.detectedVersion).toBe(8);
    expect(result.requiredVersion).toBe(8);
  });

  it('fails closed when monitoring has no eligible canary', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperMarketMonitoringEnabled: true },
      snapshot(8),
    );

    expect(result.compatible).toBe(false);
    expect(result.missingCapabilities).toEqual([
      'PAPER_MARKET_MONITOR_CANARY_UNAVAILABLE',
    ]);
  });

  it('reports detected and required versions without database details', () => {
    const compatibility = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(6),
    );
    const error = new DatabaseSchemaCompatibilityError(compatibility);

    expect(error.message).toBe(
      'Database schema version 006 is incompatible with required version 007 '
      + '(MIGRATION_007_NOT_APPLIED, PAPER_ENTRY_MONITORING_TASK_SCHEMA_UNAVAILABLE)',
    );
    expect(formatDatabaseSchemaVersion(8)).toBe('008');
  });
});
