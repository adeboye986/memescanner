import type { PaperEntryCommandBody } from '../../contracts/http/paper-entry-command.schema.js';

export interface ExecutePaperEntryCommand {
  readonly body: PaperEntryCommandBody;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
}
