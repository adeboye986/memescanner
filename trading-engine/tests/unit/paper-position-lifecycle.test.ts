import { describe, expect, it } from 'vitest';

import type { PaperPositionObservation } from '../../src/contracts/http/paper-position-command.schema.js';
import {
  assertPaperStrategy,
  evaluatePaperLifecycle,
  type PaperLifecycleState,
} from '../../src/domain/paper/paper-position-lifecycle.js';

const strategy = {
  stop_loss_percent: '10',
  protection_level_1_percent: '100',
  protection_level_2_percent: '200',
} as const;

function state(overrides: Partial<PaperLifecycleState> = {}): PaperLifecycleState {
  return {
    entryMarketCap: '10000',
    peakMarketCap: '10000',
    protectionState: 'none',
    terminal: false,
    strategy,
    ...overrides,
  };
}

function observation(marketCap: string, sequence = 1): PaperPositionObservation {
  return {
    schema_version: 1,
    position_id: '01K6K8N9XSF7M2VCTP4R6W8YZA',
    source: {
      paper_position_id: '94',
      observation_id: `paper-position-94-observation-${sequence}`,
      sequence,
    },
    subject: { control_plane_user_id: '2' },
    network: { id: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' },
    asset: { address: 'So11111111111111111111111111111111111111112' },
    market: {
      market_cap_usd: marketCap,
      price_usd: '0.001',
      liquidity_usd: '1000',
      observed_at: '2026-10-02T20:00:00.000Z',
      fetched_at: '2026-10-02T20:00:00.000Z',
      provider: 'dexscreener',
    },
    validation: {
      status: 'eligible',
      identity_verified: true,
      simulation_allowed: true,
    },
  };
}

describe('PAPER position lifecycle policy v1', () => {
  it('holds on the first valid observation and updates the peak', () => {
    const result = evaluatePaperLifecycle(state(), observation('12000'));

    expect(result).toMatchObject({
      decision: 'HOLD',
      observedMultiple: '1.2',
      peakMultiple: '1.2',
      protectionAfter: 'none',
    });
    expect(result.transitions).toContain('PEAK_UPDATED');
  });

  it('exits at the actual observed stop-loss multiple', () => {
    const result = evaluatePaperLifecycle(state(), observation('8500'));

    expect(result).toMatchObject({
      decision: 'EXIT',
      exitType: 'stop_loss',
      triggerMultiple: '0.9',
      observedMultiple: '0.85',
    });
  });

  it('arms level one and holds without exiting on the same observation', () => {
    const result = evaluatePaperLifecycle(state(), observation('21000'));

    expect(result).toMatchObject({
      decision: 'HOLD',
      protectionAfter: 'level_1',
    });
    expect(result.transitions).toContain('PROTECTION_LEVEL_1_ARMED');
  });

  it('upgrades level two and holds without exiting on the same observation', () => {
    const result = evaluatePaperLifecycle(
      state({ peakMarketCap: '21000', protectionState: 'level_1' }),
      observation('31000'),
    );

    expect(result).toMatchObject({
      decision: 'HOLD',
      protectionAfter: 'level_2',
    });
    expect(result.transitions).toContain('PROTECTION_LEVEL_2_ARMED');
  });

  it('exits later at the actual observed protected-floor multiple', () => {
    const result = evaluatePaperLifecycle(
      state({ peakMarketCap: '21000', protectionState: 'level_1' }),
      observation('19500', 2),
    );

    expect(result).toMatchObject({
      decision: 'EXIT',
      exitType: 'protected_floor_exit',
      triggerMultiple: '2',
      observedMultiple: '1.95',
    });
  });

  it('tracks negative drawdown without binary floating point', () => {
    const result = evaluatePaperLifecycle(
      state({ peakMarketCap: '12500' }),
      observation('10000'),
    );

    expect(result.drawdownPercent).toBe('-20');
  });

  it('rejects invalid immutable strategy snapshots', () => {
    expect(() => {
      assertPaperStrategy({
        stop_loss_percent: '100',
        protection_level_1_percent: '100',
        protection_level_2_percent: '200',
      });
    }).toThrow('Invalid PAPER lifecycle strategy snapshot');
  });

  it('rejects evaluation of terminal positions', () => {
    expect(() => evaluatePaperLifecycle(state({ terminal: true }), observation('12000')))
      .toThrow('Terminal PAPER positions cannot be evaluated');
  });
});
