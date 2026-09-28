import { describe, expect, it } from 'vitest';

import {
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
});
