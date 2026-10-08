import type { PaperFinancialObservation } from '../../contracts/http/paper-financial-observation-command.schema.js';

export interface ObservePaperFinancialPositionCommand {
  readonly body: PaperFinancialObservation;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
}
