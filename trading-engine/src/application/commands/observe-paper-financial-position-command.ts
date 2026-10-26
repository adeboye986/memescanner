import type { PaperFinancialObservation } from '../../contracts/http/paper-financial-observation-command.schema.js';

export type PaperFinancialObservationCommandBody = Omit<
  PaperFinancialObservation,
  'source'
> & {
  readonly source: Omit<PaperFinancialObservation['source'], 'system'> & {
    readonly system:
      | PaperFinancialObservation['source']['system']
      | 'meme-scanner-trading-engine-monitor';
  };
};

export interface ObservePaperFinancialPositionCommand {
  readonly body: PaperFinancialObservationCommandBody;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
}
