import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    clearMocks: true,
    coverage: {
      enabled: false,
      provider: 'v8',
    },
    environment: 'node',
    fileParallelism: false,
    include: ['tests/**/*.test.ts'],
    mockReset: true,
    restoreMocks: true,
    sequence: {
      concurrent: false,
    },
    testTimeout: 30_000,
    hookTimeout: 120_000,
  },
});
