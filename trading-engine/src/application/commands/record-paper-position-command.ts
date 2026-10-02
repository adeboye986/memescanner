import type { PaperPositionRegistration } from '../../contracts/http/paper-position-command.schema.js';

export interface RecordPaperPositionCommand {
  readonly body: PaperPositionRegistration;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
}
