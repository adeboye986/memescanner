import type { PaperPositionObservation } from '../../contracts/http/paper-position-command.schema.js';

export interface ObservePaperPositionCommand {
  readonly body: PaperPositionObservation;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
}
