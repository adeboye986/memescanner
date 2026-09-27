import { Redis } from 'ioredis';

import type { EngineConfig } from '../../config/env.js';

export function createRedisConnection(config: EngineConfig): Redis {
  return new Redis(config.redisUrl, {
    connectionName: config.serviceName,
    enableReadyCheck: true,
    lazyConnect: true,
    maxRetriesPerRequest: null,
  });
}

export async function pingRedis(redis: Redis): Promise<void> {
  if (redis.status === 'wait') {
    await redis.connect();
  }

  await redis.ping();
}

export async function closeRedis(redis: Redis): Promise<void> {
  if (redis.status === 'end') {
    return;
  }

  if (redis.status === 'wait') {
    redis.disconnect(false);
    return;
  }

  await redis.quit();
}
