import { Type, type Static } from '@sinclair/typebox';

export const HealthStatusSchema = Type.Object(
  {
    status: Type.Union([Type.Literal('ok'), Type.Literal('unavailable')]),
    service: Type.String({ minLength: 1 }),
    version: Type.String({ minLength: 1 }),
    timestamp: Type.String({ format: 'date-time' }),
    dependencies: Type.Optional(
      Type.Object(
        {
          postgres: Type.Union([Type.Literal('up'), Type.Literal('down')]),
          redis: Type.Union([
            Type.Literal('up'),
            Type.Literal('down'),
            Type.Literal('not_required'),
          ]),
        },
        { additionalProperties: false },
      ),
    ),
  },
  { additionalProperties: false },
);

export type HealthStatus = Static<typeof HealthStatusSchema>;

export const VersionSchema = Type.Object(
  {
    service: Type.String({ minLength: 1 }),
    version: Type.String({ minLength: 1 }),
    node: Type.String({ minLength: 1 }),
  },
  { additionalProperties: false },
);

export type VersionResponse = Static<typeof VersionSchema>;
