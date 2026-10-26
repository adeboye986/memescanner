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
  paperMarketMonitoringAuthorityEnabled: false,
  paperMarketMonitorCanaryUserIds: [],
  tokenScanningEnabled: false,
  tokenScannerCanaryUserIds: [],
};

function snapshot(version: 6 | 7 | 8 | 9 | 10): DatabaseSchemaSnapshot {
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
      ...(version >= 9 ? ['009_paper_market_observation_authority'] : []),
      ...(version >= 10 ? ['010_token_discovery_scanning'] : []),
    ],
    paperFinancialSchemaAvailable: true,
    paperEntrySchemaAvailable: version >= 7,
    paperShadowMonitoringSchemaAvailable: version >= 8,
    paperMarketAuthoritySchemaAvailable: version >= 9,
    tokenScanningSchemaAvailable: version >= 10,
  };
}

describe('database schema compatibility', () => {
  it('requires the baseline financial schema even with entry and monitoring disabled', () => {
    const result = evaluateDatabaseSchemaCompatibility(disabled, {
      appliedMigrations: [],
      paperFinancialSchemaAvailable: false,
      paperEntrySchemaAvailable: false,
      paperShadowMonitoringSchemaAvailable: false,
      paperMarketAuthoritySchemaAvailable: false,
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
    expect(evaluateDatabaseSchemaCompatibility(disabled, snapshot(6))).toEqual({
      compatible: true,
      detectedVersion: 6,
      requiredVersion: 6,
      missingCapabilities: [],
    });
  });

  it('requires schema 009 before PAPER entry can create authority-fenced tasks', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(8),
    );

    expect(result).toEqual({
      compatible: false,
      detectedVersion: 8,
      requiredVersion: 9,
      missingCapabilities: [
        'MIGRATION_009_NOT_APPLIED',
        'PAPER_MARKET_AUTHORITY_SCHEMA_UNAVAILABLE',
      ],
    });
  });

  it('accepts schema 009 for PAPER entry', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(9),
    );

    expect(result.compatible).toBe(true);
    expect(result.detectedVersion).toBe(9);
    expect(result.requiredVersion).toBe(9);
  });

  it('requires schema 009 before either shadow or authoritative monitoring', () => {
    const config = {
      ...disabled,
      paperEntryEnabled: true,
      paperMarketMonitoringEnabled: true,
      paperMarketMonitorCanaryUserIds: ['1'],
    };
    const shadow = evaluateDatabaseSchemaCompatibility(config, snapshot(8));
    const authoritative = evaluateDatabaseSchemaCompatibility(
      { ...config, paperMarketMonitoringAuthorityEnabled: true },
      snapshot(8),
    );

    expect(shadow.requiredVersion).toBe(9);
    expect(authoritative.requiredVersion).toBe(9);
    expect(shadow.missingCapabilities).toEqual([
      'MIGRATION_009_NOT_APPLIED',
      'PAPER_MARKET_AUTHORITY_SCHEMA_UNAVAILABLE',
    ]);
    expect(authoritative.missingCapabilities).toEqual(shadow.missingCapabilities);
  });

  it('accepts schema 009 for an authoritative monitoring canary', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      {
        ...disabled,
        paperEntryEnabled: true,
        paperMarketMonitoringEnabled: true,
        paperMarketMonitoringAuthorityEnabled: true,
        paperMarketMonitorCanaryUserIds: ['1'],
      },
      snapshot(9),
    );

    expect(result).toEqual({
      compatible: true,
      detectedVersion: 9,
      requiredVersion: 9,
      missingCapabilities: [],
    });
  });

  it('fails closed when monitoring has no eligible canary', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperMarketMonitoringEnabled: true },
      snapshot(9),
    );

    expect(result.compatible).toBe(false);
    expect(result.missingCapabilities).toEqual([
      'PAPER_MARKET_MONITOR_CANARY_UNAVAILABLE',
    ]);
  });

  it('requires schema 010 and a canary before token scanning can start', () => {
    const missingSchema = evaluateDatabaseSchemaCompatibility(
      {
        ...disabled,
        tokenScanningEnabled: true,
        tokenScannerCanaryUserIds: ['1'],
      },
      snapshot(9),
    );
    const missingCanary = evaluateDatabaseSchemaCompatibility(
      { ...disabled, tokenScanningEnabled: true },
      snapshot(10),
    );

    expect(missingSchema.requiredVersion).toBe(10);
    expect(missingSchema.missingCapabilities).toEqual([
      'MIGRATION_010_NOT_APPLIED',
      'TOKEN_SCANNING_SCHEMA_UNAVAILABLE',
    ]);
    expect(missingCanary.missingCapabilities).toEqual([
      'TOKEN_SCANNER_CANARY_UNAVAILABLE',
    ]);
  });

  it('accepts schema 010 for an explicitly allowlisted scanner canary', () => {
    const result = evaluateDatabaseSchemaCompatibility(
      {
        ...disabled,
        tokenScanningEnabled: true,
        tokenScannerCanaryUserIds: ['1'],
      },
      snapshot(10),
    );

    expect(result).toEqual({
      compatible: true,
      detectedVersion: 10,
      requiredVersion: 10,
      missingCapabilities: [],
    });
  });

  it('reports detected and required versions without database details', () => {
    const compatibility = evaluateDatabaseSchemaCompatibility(
      { ...disabled, paperEntryEnabled: true },
      snapshot(8),
    );
    const error = new DatabaseSchemaCompatibilityError(compatibility);

    expect(error.message).toBe(
      'Database schema version 008 is incompatible with required version 009 '
      + '(MIGRATION_009_NOT_APPLIED, PAPER_MARKET_AUTHORITY_SCHEMA_UNAVAILABLE)',
    );
    expect(formatDatabaseSchemaVersion(9)).toBe('009');
  });
});
