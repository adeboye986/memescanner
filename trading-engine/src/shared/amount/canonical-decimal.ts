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

export function multiplyCanonicalDecimals(left: string, right: string): string {
  const leftParts = scaledInteger(left);
  const rightParts = scaledInteger(right);

  return canonicalFromScaled(
    leftParts.value * rightParts.value,
    leftParts.scale + rightParts.scale,
  );
}

export function divideCanonicalDecimals(
  numerator: string,
  denominator: string,
  precision = 18,
): string {
  const left = scaledInteger(numerator);
  const right = scaledInteger(denominator);

  if (right.value === 0n) {
    throw new Error("Decimal division by zero");
  }

  const scaledNumerator = left.value * (10n ** BigInt(precision + right.scale));
  const scaledDenominator = right.value * (10n ** BigInt(left.scale));

  return canonicalFromScaled(scaledNumerator / scaledDenominator, precision);
}

export function subtractCanonicalDecimals(left: string, right: string): string {
  const leftParts = scaledInteger(left);
  const rightParts = scaledInteger(right);
  const scale = Math.max(leftParts.scale, rightParts.scale);
  const leftValue = leftParts.value * (10n ** BigInt(scale - leftParts.scale));
  const rightValue = rightParts.value * (10n ** BigInt(scale - rightParts.scale));

  return canonicalFromScaled(leftValue - rightValue, scale);
}

interface ScaledInteger {
  readonly value: bigint;
  readonly scale: number;
}

function scaledInteger(value: string): ScaledInteger {
  if (!isCanonicalSignedDecimal(value)) {
    throw new Error("Decimal arithmetic requires canonical decimal strings");
  }

  const negative = value.startsWith("-");
  const unsigned = negative ? value.slice(1) : value;
  const [integer = "0", fraction = ""] = unsigned.split(".", 2);
  const magnitude = BigInt(`${integer}${fraction}`);

  return {
    value: negative ? -magnitude : magnitude,
    scale: fraction.length,
  };
}

function canonicalFromScaled(value: bigint, scale: number): string {
  const negative = value < 0n;
  const digits = (negative ? -value : value).toString().padStart(scale + 1, "0");
  const raw = scale === 0
    ? digits
    : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;

  return canonicalizeDatabaseDecimal(negative ? `-${raw}` : raw);
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
