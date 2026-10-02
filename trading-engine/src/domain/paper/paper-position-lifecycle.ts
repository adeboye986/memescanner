import type { PaperPositionObservation, PaperPositionRegistration } from '../../contracts/http/paper-position-command.schema.js';
import {
  compareCanonicalDecimals,
  divideCanonicalDecimals,
  multiplyCanonicalDecimals,
  subtractCanonicalDecimals,
} from '../../shared/amount/canonical-decimal.js';

export const PAPER_LIFECYCLE_POLICY_KEY = 'laravel-paper-protection';
export const PAPER_LIFECYCLE_POLICY_VERSION = 1;

export type ProtectionState = 'none' | 'level_1' | 'level_2';
export type LifecycleDecision = 'HOLD' | 'EXIT';
export type LifecycleExitType = 'stop_loss' | 'protected_floor_exit';

export interface PaperLifecycleState {
  readonly entryMarketCap: string;
  readonly peakMarketCap: string;
  readonly protectionState: ProtectionState;
  readonly terminal: boolean;
  readonly strategy: PaperPositionRegistration['strategy'];
}

export interface PaperLifecycleResult {
  readonly decision: LifecycleDecision;
  readonly exitType: LifecycleExitType | null;
  readonly observedMultiple: string;
  readonly peakMarketCap: string;
  readonly peakMultiple: string;
  readonly drawdownPercent: string;
  readonly protectionBefore: ProtectionState;
  readonly protectionAfter: ProtectionState;
  readonly transitions: readonly string[];
  readonly triggerMultiple: string | null;
}

export function assertPaperStrategy(
  strategy: PaperPositionRegistration['strategy'],
): void {
  if (
    compareCanonicalDecimals(strategy.stop_loss_percent, '0') <= 0
    || compareCanonicalDecimals(strategy.stop_loss_percent, '100') >= 0
    || compareCanonicalDecimals(strategy.protection_level_1_percent, '0') <= 0
    || compareCanonicalDecimals(
      strategy.protection_level_2_percent,
      strategy.protection_level_1_percent,
    ) <= 0
  ) {
    throw new Error('Invalid PAPER lifecycle strategy snapshot');
  }
}

export function evaluatePaperLifecycle(
  state: PaperLifecycleState,
  observation: PaperPositionObservation,
): PaperLifecycleResult {
  if (state.terminal) {
    throw new Error('Terminal PAPER positions cannot be evaluated');
  }

  const marketCap = observation.market.market_cap_usd;
  const peakMarketCap = compareCanonicalDecimals(marketCap, state.peakMarketCap) > 0
    ? marketCap
    : state.peakMarketCap;
  const observedMultiple = divideCanonicalDecimals(marketCap, state.entryMarketCap);
  const peakMultiple = divideCanonicalDecimals(peakMarketCap, state.entryMarketCap);
  const drawdownPercent = multiplyCanonicalDecimals(
    divideCanonicalDecimals(
      subtractCanonicalDecimals(marketCap, peakMarketCap),
      peakMarketCap,
    ),
    '100',
  );
  const stopLossMultiple = divideCanonicalDecimals(
    subtractCanonicalDecimals('100', state.strategy.stop_loss_percent),
    '100',
  );
  const levelOneMultiple = divideCanonicalDecimals(
    subtractCanonicalDecimals('100', '-' + state.strategy.protection_level_1_percent),
    '100',
  );
  const levelTwoMultiple = divideCanonicalDecimals(
    subtractCanonicalDecimals('100', '-' + state.strategy.protection_level_2_percent),
    '100',
  );
  const transitions: string[] = [];
  let protectionAfter = state.protectionState;
  let protectionJustChanged = false;

  if (
    protectionAfter === 'none'
    && compareCanonicalDecimals(peakMultiple, levelOneMultiple) >= 0
  ) {
    protectionAfter = 'level_1';
    protectionJustChanged = true;
    transitions.push('PROTECTION_LEVEL_1_ARMED');
  }

  if (
    protectionAfter !== 'level_2'
    && compareCanonicalDecimals(peakMultiple, levelTwoMultiple) >= 0
  ) {
    protectionAfter = 'level_2';
    protectionJustChanged = true;
    transitions.push('PROTECTION_LEVEL_2_ARMED');
  }

  if (compareCanonicalDecimals(peakMarketCap, state.peakMarketCap) > 0) {
    transitions.unshift('PEAK_UPDATED');
  }

  if (
    state.protectionState === 'none'
    && compareCanonicalDecimals(observedMultiple, stopLossMultiple) <= 0
  ) {
    return result('EXIT', 'stop_loss', stopLossMultiple);
  }

  const floor = protectionAfter === 'level_2'
    ? levelTwoMultiple
    : protectionAfter === 'level_1'
      ? levelOneMultiple
      : null;

  if (
    floor !== null
    && !protectionJustChanged
    && compareCanonicalDecimals(observedMultiple, floor) <= 0
  ) {
    return result('EXIT', 'protected_floor_exit', floor);
  }

  return result('HOLD', null, null);

  function result(
    decision: LifecycleDecision,
    exitType: LifecycleExitType | null,
    triggerMultiple: string | null,
  ): PaperLifecycleResult {
    return {
      decision,
      exitType,
      observedMultiple,
      peakMarketCap,
      peakMultiple,
      drawdownPercent,
      protectionBefore: state.protectionState,
      protectionAfter,
      transitions,
      triggerMultiple,
    };
  }
}
