import { Type, type Static } from '@sinclair/typebox';

export const NoopCommandSchema = Type.Object(
  {
    message: Type.Optional(Type.String({ maxLength: 256 })),
  },
  { additionalProperties: false },
);

export type NoopCommand = Static<typeof NoopCommandSchema>;

export const NoopCommandResponseSchema = Type.Object(
  {
    operationId: Type.String({ minLength: 26, maxLength: 26 }),
    eventId: Type.String({ minLength: 26, maxLength: 26 }),
    status: Type.Literal('accepted'),
    duplicate: Type.Boolean(),
  },
  { additionalProperties: false },
);

export type NoopCommandResponse = Static<typeof NoopCommandResponseSchema>;
