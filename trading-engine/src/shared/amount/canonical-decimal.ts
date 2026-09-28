import { Type, type Static } from '@sinclair/typebox';

const unsignedPattern = '^(?:0|[1-9][0-9]{0,47})(?:\\.[0-9]{0,29}[1-9])?$';
const signedPattern = '^-?(?:0|[1-9][0-9]{0,47})(?:\\.[0-9]{0,29}[1-9])?$';
const databaseDecimalPattern = /^(-?)([0-9]+)(?:\.([0-9]+))?$/;

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

export function canonicalizeDatabaseDecimal(value: string): string {
  const match = databaseDecimalPattern.exec(value);

  if (match === null) {
    throw new Error('PostgreSQL returned a non-decimal NUMERIC value');
  }

  const sign = match[1] ?? '';
  const integerDigits = (match[2] ?? '').replace(/^0+(?=[0-9])/, '');
  const fractionalDigits = (match[3] ?? '').replace(/0+$/, '');
  const unsigned = fractionalDigits === ''
    ? integerDigits
    : `${integerDigits}.${fractionalDigits}`;

  return sign === '-' && unsigned !== '0' ? `-${unsigned}` : unsigned;
}

export function compareCanonicalDecimals(left: string, right: string): -1 | 0 | 1 {
  if (!isCanonicalSignedDecimal(left) || !isCanonicalSignedDecimal(right)) {
    throw new Error('Decimal comparison requires canonical decimal strings');
  }

  const leftParts = decimalParts(left);
  const rightParts = decimalParts(right);

  if (leftParts.negative !== rightParts.negative) {
    return leftParts.negative ? -1 : 1;
  }

  const magnitude = compareMagnitude(leftParts, rightParts);

  return leftParts.negative && magnitude !== 0
    ? magnitude === 1 ? -1 : 1
    : magnitude;
}

interface DecimalParts {
  readonly negative: boolean;
  readonly integer: string;
  readonly fraction: string;
}

function decimalParts(value: string): DecimalParts {
  const negative = value.startsWith('-');
  const unsigned = negative ? value.slice(1) : value;
  const [integer = '0', fraction = ''] = unsigned.split('.', 2);

  return { negative, integer, fraction };
}

function compareMagnitude(left: DecimalParts, right: DecimalParts): -1 | 0 | 1 {
  if (left.integer.length !== right.integer.length) {
    return left.integer.length < right.integer.length ? -1 : 1;
  }

  if (left.integer !== right.integer) {
    return left.integer < right.integer ? -1 : 1;
  }

  const fractionLength = Math.max(left.fraction.length, right.fraction.length);
  const leftFraction = left.fraction.padEnd(fractionLength, '0');
  const rightFraction = right.fraction.padEnd(fractionLength, '0');

  if (leftFraction === rightFraction) {
    return 0;
  }

  return leftFraction < rightFraction ? -1 : 1;
}
