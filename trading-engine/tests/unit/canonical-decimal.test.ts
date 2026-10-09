import { types } from 'pg';
import { describe, expect, it } from 'vitest';

import '../../src/infrastructure/database/client.js';

import {
  addCanonicalDecimals,
  canonicalizeDatabaseDecimal,
  isCanonicalNonNegativeDecimal,
  isCanonicalSignedDecimal,
  roundCanonicalDecimal,
} from '../../src/shared/amount/canonical-decimal.js';

describe('canonical decimal strings', () => {
  it.each(['0', '1', '1.25', '0.00000001', '999999999999999999999999999999999999999999999999.123']) (
    'accepts canonical non-negative decimal %s',
    (value) => {
      expect(isCanonicalNonNegativeDecimal(value)).toBe(true);
    },
  );

  it.each([
    1,
    '-1',
    '+1',
    '01',
    '1.0',
    '1e3',
    '1,000',
    'NaN',
    'Infinity',
  ])('rejects non-canonical non-negative decimal %s', (value) => {
    expect(isCanonicalNonNegativeDecimal(value)).toBe(false);
  });

  it.each(['0', '1.25', '-1.25', '-0.01'])(
    'accepts canonical signed decimal %s',
    (value) => {
      expect(isCanonicalSignedDecimal(value)).toBe(true);
    },
  );

  it.each(['-0', '-0.0', '+1', '01', '1.0', '1e3'])(
    'rejects non-canonical signed decimal %s',
    (value) => {
      expect(isCanonicalSignedDecimal(value)).toBe(false);
    },
  );

  it.each([
    ['0.000001250000000000000000000000', '0.00000125'],
    ['12000.000000000000000000000000000000', '12000'],
    ['00120.500000', '120.5'],
    ['-2.500000000000000000000000000000', '-2.5'],
    ['-0.000000000000000000000000000000', '0'],
  ])('canonicalizes PostgreSQL NUMERIC text %s as %s', (stored, expected) => {
    expect(canonicalizeDatabaseDecimal(stored)).toBe(expected);
  });

  it('registers canonical string decoding for every PostgreSQL NUMERIC column', () => {
    const parseNumeric = types.getTypeParser(
      types.builtins.NUMERIC,
      'text',
    ) as unknown as (value: string) => unknown;
    const parsed = parseNumeric('12500.500000000000000000000000000000');

    expect(parsed).toBe('12500.5');
    expect(typeof parsed).toBe('string');
  });

  it.each(['1e3', 'NaN', 'Infinity', '1,000', '+1'])(
    'rejects invalid PostgreSQL NUMERIC text %s',
    (stored) => {
      expect(() => canonicalizeDatabaseDecimal(stored)).toThrow(
        'PostgreSQL returned a non-decimal NUMERIC value',
      );
    },
  );

  it.each([
    ['4.9', '0.085', '4.985'],
    ['0', '-0.015', '-0.015'],
    ['0.1', '-0.1', '0'],
  ])('adds signed canonical decimals without floating point conversion', (left, right, expected) => {
    expect(addCanonicalDecimals(left, right)).toBe(expected);
  });

  it.each([
    {
      value: '0.1234567890123456789012345678905',
      scale: 30,
      expected: '0.123456789012345678901234567891',
    },
    {
      value: '-0.1234567890123456789012345678905',
      scale: 30,
      expected: '-0.123456789012345678901234567891',
    },
    { value: '1.2344', scale: 3, expected: '1.234' },
    { value: '1.2345', scale: 3, expected: '1.235' },
    { value: '-1.2345', scale: 3, expected: '-1.235' },
    { value: '0.0004', scale: 3, expected: '0' },
    { value: '-0.0004', scale: 3, expected: '0' },
    { value: '9.9995', scale: 3, expected: '10' },
    { value: '12.34', scale: 30, expected: '12.34' },
  ])('rounds exact decimal $value to scale $scale as $expected', ({ value, scale, expected }) => {
    expect(roundCanonicalDecimal(value, scale)).toBe(expected);
  });

  it('keeps higher-precision rounding inputs separate from public decimal schemas', () => {
    const higherPrecision = '0.1234567890123456789012345678905';

    expect(isCanonicalSignedDecimal(higherPrecision)).toBe(false);
    expect(roundCanonicalDecimal(higherPrecision, 30))
      .toBe('0.123456789012345678901234567891');
  });

  it.each([
    { value: '1e3', scale: 30 },
    { value: '01.5', scale: 30 },
    { value: '1.230', scale: 30 },
    { value: '-0', scale: 30 },
    { value: `0.${'1'.repeat(61)}`, scale: 30 },
    { value: `${'1'.repeat(97)}.5`, scale: 30 },
  ])('rejects unsafe rounding input $value', ({ value, scale }) => {
    expect(() => roundCanonicalDecimal(value, scale)).toThrow(
      'Decimal rounding requires a bounded canonical decimal string',
    );
  });

  it.each([-1, 1.5, 31])('rejects unsafe rounding scale %s', (scale) => {
    expect(() => roundCanonicalDecimal('1.25', scale)).toThrow(
      'Decimal scale must be an integer between 0 and 30',
    );
  });

  it('rejects rounding results outside the public financial precision', () => {
    expect(() => roundCanonicalDecimal('9'.repeat(96), 30)).toThrow(
      'Rounded decimal exceeds canonical financial precision',
    );
  });
});
