import { monotonicFactory } from 'ulid';

const monotonicUlid = monotonicFactory();

export type EngineId = string & { readonly __engineId: unique symbol };

export function newEngineId(timestamp: number = Date.now()): EngineId {
  return monotonicUlid(timestamp) as EngineId;
}
