import { types } from 'pg';
import { describe, expect, it } from 'vitest';

import '../../src/infrastructure/database/client.js';

import {
  canonicalizeDatabaseDecimal,
  isCanonicalNonNegativeDecimal,
  isCanonicalSignedDecimal,
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
});
