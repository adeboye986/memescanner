import { describe, expect, it } from 'vitest';

import { isSolanaAddress } from '../../src/application/handlers/record-opportunity-command-handler.js';

describe('network address validation', () => {
  it('accepts only Base58 Solana addresses that decode to 32 bytes', () => {
    expect(isSolanaAddress('So11111111111111111111111111111111111111112')).toBe(true);
    expect(isSolanaAddress('11111111111111111111111111111111')).toBe(true);
    expect(isSolanaAddress('2'.repeat(32))).toBe(false);
    expect(isSolanaAddress('0'.repeat(32))).toBe(false);
  });
});
