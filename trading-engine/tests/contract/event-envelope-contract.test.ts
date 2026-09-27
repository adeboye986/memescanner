import { FormatRegistry } from '@sinclair/typebox';
import { Value } from '@sinclair/typebox/value';
import { describe, expect, it } from 'vitest';

import { EventEnvelopeSchema } from '../../src/contracts/events/event-envelope.schema.js';

FormatRegistry.Set('date-time', (value: string) => {
  return typeof value === 'string' && !Number.isNaN(Date.parse(value));
});

const validEnvelope = {
  event_id: '01K9ABCDEFGHIJKLMNOPQRSTUV',
  event_type: 'foundation.noop_accepted.v1',
  schema_version: 1,
  occurred_at: '2026-09-27T12:00:00.000Z',
  producer: 'trading-engine',
  aggregate_type: 'foundation_command',
  aggregate_id: '01K9ABCDEFGHIJKLMNOPQRSTUV',
  aggregate_version: 1,
  correlation_id: 'correlation-1',
  causation_id: '01K9ABCDEFGHIJKLMNOPQRSTUV',
  idempotency_key: 'noop-1',
  traceparent: '00-11111111111111111111111111111111-2222222222222222-01',
  payload: {
    operation_id: '01K9ABCDEFGHIJKLMNOPQRSTUV',
  },
  payload_sha256: 'a'.repeat(64),
} as const;

describe('event envelope contract', () => {
  it('accepts the versioned synthetic foundation event', () => {
    expect(Value.Check(EventEnvelopeSchema, validEnvelope)).toBe(true);
  });

  it('rejects an unversioned event type', () => {
    expect(
      Value.Check(EventEnvelopeSchema, {
        ...validEnvelope,
        event_type: 'foundation.noop_accepted',
      }),
    ).toBe(false);
  });

  it('rejects unexpected transport fields', () => {
    expect(
      Value.Check(EventEnvelopeSchema, {
        ...validEnvelope,
        private_key: 'must-never-cross-the-boundary',
      }),
    ).toBe(false);
  });
});
