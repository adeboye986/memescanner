import type { TokenScanCommand } from '../../contracts/http/token-scan-command.schema.js';

export interface StartTokenScanCommand {
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly body: TokenScanCommand;
}
