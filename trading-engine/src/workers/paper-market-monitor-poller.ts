import type { Logger } from 'pino';

import type { RuntimeHandle } from '../infrastructure/runtime/process-lifecycle.js';
import type { PaperMarketMonitoringSummary } from './paper-market-monitor.js';

const maximumPollIntervalMs = 300_000;

export interface PaperMarketMonitoringCycleRunner {
  runCycle(): Promise<PaperMarketMonitoringSummary>;
  shutdown(): Promise<void>;
}

export interface PaperMarketMonitorPollerOptions {
  readonly intervalMs: number;
  readonly logger: Logger;
  readonly cycle: PaperMarketMonitoringCycleRunner;
  readonly beforeCycle?: () => Promise<boolean>;
}

export interface PaperMarketMonitorPollerHandle extends RuntimeHandle {
  readonly completed: Promise<void>;
  start(): void;
}

export class PaperMarketMonitorPoller implements PaperMarketMonitorPollerHandle {
  private started = false;
  private stopping = false;
  private timer: NodeJS.Timeout | undefined;
  private releaseWait: (() => void) | undefined;
  private runPromise: Promise<void> | undefined;
  private closePromise: Promise<void> | undefined;

  public constructor(private readonly options: PaperMarketMonitorPollerOptions) {
    if (
      !Number.isSafeInteger(options.intervalMs)
      || options.intervalMs < 1
      || options.intervalMs > maximumPollIntervalMs
    ) {
      throw new RangeError(
        `PAPER market monitor poll interval must be between 1 and ${maximumPollIntervalMs} ms`,
      );
    }
  }

  public get completed(): Promise<void> {
    return this.runPromise ?? Promise.resolve();
  }

  public start(): void {
    if (this.started || this.stopping) {
      return;
    }

    this.started = true;
    this.runPromise = this.run();
  }

  public close(): Promise<void> {
    this.closePromise ??= (async (): Promise<void> => {
      this.stopping = true;
      this.releaseWait?.();
      await this.runPromise;
    })();

    return this.closePromise;
  }

  private async run(): Promise<void> {
    try {
      while (this.isRunning()) {
        if (!await this.mayRunCycle() || !this.isRunning()) {
          break;
        }

        try {
          await this.options.cycle.runCycle();
        } catch {
          this.options.logger.error(
            { errorCode: 'PAPER_MARKET_MONITOR_CYCLE_FAILED' },
            'PAPER market monitoring cycle failed',
          );
        }

        if (this.isRunning()) {
          await this.waitForNextCycle();
        }
      }
    } finally {
      await this.options.cycle.shutdown();
    }
  }

  private isRunning(): boolean {
    return !this.stopping;
  }

  private async mayRunCycle(): Promise<boolean> {
    if (this.options.beforeCycle === undefined) {
      return true;
    }

    try {
      const mayRun = await this.options.beforeCycle();

      if (!mayRun) {
        this.options.logger.warn(
          { errorCode: 'PAPER_MARKET_MONITOR_LEADERSHIP_LOST' },
          'PAPER market monitoring leadership was lost',
        );
      }

      return mayRun;
    } catch {
      this.options.logger.error(
        { errorCode: 'PAPER_MARKET_MONITOR_LEADERSHIP_CHECK_FAILED' },
        'PAPER market monitoring leadership check failed',
      );

      return false;
    }
  }

  private waitForNextCycle(): Promise<void> {
    return new Promise((resolve) => {
      const complete = (): void => {
        if (this.timer !== undefined) {
          clearTimeout(this.timer);
        }

        this.timer = undefined;
        this.releaseWait = undefined;
        resolve();
      };

      this.timer = setTimeout(complete, this.options.intervalMs);
      this.releaseWait = complete;
    });
  }
}
