import { Type, type Static } from '@sinclair/typebox';

const unsignedPattern = '^(?:0|[1-9][0-9]{0,47})(?:\\.[0-9]{0,29}[1-9])?$';
const signedPattern = '^-?(?:0|[1-9][0-9]{0,47})(?:\\.[0-9]{0,29}[1-9])?$';

export const NonNegativeDecimalStringSchema = Type.String({
  pattern: unsignedPattern,
  maxLength: 79,
});

export const SignedDecimalStringSchema = Type.Unsafe<string>({
  type: 'string',
  pattern: signedPattern,
  maxLength: 80,
  not: { const: '-0' },
});

export type NonNegativeDecimalString = Static<typeof NonNegativeDecimalStringSchema>;
export type SignedDecimalString = Static<typeof SignedDecimalStringSchema>;

const unsignedExpression = new RegExp(unsignedPattern);
const signedExpression = new RegExp(signedPattern);

export function isCanonicalNonNegativeDecimal(
  value: unknown,
): value is NonNegativeDecimalString {
  return typeof value === 'string' && unsignedExpression.test(value);
}

export function isCanonicalSignedDecimal(
  value: unknown,
): value is SignedDecimalString {
  return typeof value === 'string'
    && value !== '-0'
    && signedExpression.test(value);
}
