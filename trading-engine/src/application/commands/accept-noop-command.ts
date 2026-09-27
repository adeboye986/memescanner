export interface AcceptNoopCommand {
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly message?: string;
}
