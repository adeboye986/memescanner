import { Type, type Static } from '@sinclair/typebox';

export const EventEnvelopeSchema = Type.Object(
  {
    event_id: Type.String({ minLength: 26, maxLength: 26 }),
    event_type: Type.String({ pattern: '^[a-z][a-z0-9_.-]+\\.v[1-9][0-9]*$' }),
    schema_version: Type.Integer({ minimum: 1 }),
    occurred_at: Type.String({ format: 'date-time' }),
    producer: Type.Literal('trading-engine'),
    aggregate_type: Type.String({ minLength: 1, maxLength: 64 }),
    aggregate_id: Type.String({ minLength: 26, maxLength: 26 }),
    aggregate_version: Type.Integer({ minimum: 1 }),
    correlation_id: Type.String({ minLength: 1, maxLength: 128 }),
    causation_id: Type.String({ minLength: 1, maxLength: 128 }),
    idempotency_key: Type.String({ minLength: 1, maxLength: 128 }),
    traceparent: Type.String({
      pattern: '^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$',
    }),
    payload: Type.Record(Type.String(), Type.Unknown()),
    payload_sha256: Type.String({ pattern: '^[0-9a-f]{64}$' }),
  },
  { additionalProperties: false },
);

export type EventEnvelope = Static<typeof EventEnvelopeSchema>;
