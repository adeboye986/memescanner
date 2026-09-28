import type { OpportunityCommand } from '../../contracts/http/opportunity-command.schema.js';

export interface RecordOpportunityCommand {
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly body: OpportunityCommand;
}
