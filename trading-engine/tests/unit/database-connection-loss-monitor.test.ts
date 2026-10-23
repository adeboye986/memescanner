import { EventEmitter } from 'node:events';

import { Pool, type PoolClient, type PoolConfig, type QueryResult } from 'pg';
import { describe, expect, it, vi } from 'vitest';

import { DatabaseConnectionLossMonitor } from '../../src/infrastructure/database/client.js';

interface BackendPidRow {
  readonly pid: number;
}

function backendPidResult(pid: number): QueryResult<BackendPidRow> {
  return {
    command: 'SELECT',
    rowCount: 1,
    oid: 0,
    fields: [],
    rows: [{ pid }],
  };
}

function createClient(
  driverProcessId: number,
  backendPid: number,
): {
  readonly client: PoolClient;
  readonly events: EventEmitter;
  readonly query: ReturnType<typeof vi.fn<(queryText: string) => Promise<QueryResult<BackendPidRow>>>>;
  readonly processID: number;
} {
  const query = vi.fn<(queryText: string) => Promise<QueryResult<BackendPidRow>>>(
    () => Promise.resolve(backendPidResult(backendPid)),
  );
  const events = Object.assign(new EventEmitter(), {
    processID: driverProcessId,
    query,
  });

  return {
    client: events as unknown as PoolClient,
    events,
    query,
    processID: events.processID,
  };
}

interface PoolClientDouble extends EventEmitter {
  readonly processID: number;
  ended: boolean;
}

function createPoolClientDouble(
  queryImplementation: () => Promise<QueryResult<BackendPidRow>>,
): {
  readonly Client: NonNullable<PoolConfig['Client']>;
  readonly clients: PoolClientDouble[];
} {
  const clients: PoolClientDouble[] = [];

  class TestPoolClient extends EventEmitter {
    public readonly processID = 49;
    public ended = false;
    public _ending = false;
    public _queryable = true;

    public constructor() {
      super();
      clients.push(this);
    }

    public connect(callback: (error?: Error) => void): void {
      callback();
    }

    public query(): Promise<QueryResult<BackendPidRow>> {
      return queryImplementation();
    }

    public end(callback: () => void): void {
      this.ended = true;
      this._ending = true;
      callback();
    }
  }

  return {
    Client: TestPoolClient as unknown as NonNullable<PoolConfig['Client']>,
    clients,
  };
}

function createMonitor(): {
  readonly monitor: DatabaseConnectionLossMonitor;
  readonly poolEvents: EventEmitter;
} {
  const monitor = new DatabaseConnectionLossMonitor();
  const poolEvents = new EventEmitter();

  monitor.attach(poolEvents as unknown as Pool);

  return { monitor, poolEvents };
}

function verifyClient(
  monitor: DatabaseConnectionLossMonitor,
  client: PoolClient,
): Promise<Error | undefined> {
  return new Promise((resolve) => {
    monitor.verify(client, (error) => {
      resolve(error);
    });
  });
}

describe('DatabaseConnectionLossMonitor', () => {
  it('indexes a physical connection by its queried backend PID without changing the driver PID', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(41, 7_001);

    poolEvents.emit('connect', connection.client);

    expect(await verifyClient(monitor, connection.client)).toBeUndefined();
    expect(connection.query).toHaveBeenCalledOnce();
    expect(connection.query).toHaveBeenCalledWith(
      'select pg_backend_pid()::int as pid',
    );
    expect(monitor.signalForBackendPid(41)).toBeUndefined();
    expect(monitor.signalForBackendPid(7_001)).toBeDefined();
    expect(connection.processID).toBe(41);
  });

  it('marks the matching connection signal lost after an unexpected client error', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(42, 7_002);

    poolEvents.emit('connect', connection.client);
    expect(await verifyClient(monitor, connection.client)).toBeUndefined();
    const signal = monitor.signalForBackendPid(7_002);

    expect(signal).toBeDefined();

    connection.events.emit('error', new Error('synthetic connection loss'));

    expect(signal?.isLost()).toBe(true);
    await expect(signal?.lost).resolves.toBeUndefined();
  });

  it('keeps monitoring intact while a physical connection is reused', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(43, 7_003);

    poolEvents.emit('connect', connection.client);
    expect(await verifyClient(monitor, connection.client)).toBeUndefined();
    const initialSignal = monitor.signalForBackendPid(7_003);

    poolEvents.emit('release', undefined, connection.client);
    poolEvents.emit('acquire', connection.client);
    poolEvents.emit('connect', connection.client);

    expect(monitor.signalForBackendPid(7_003)).toBe(initialSignal);
    expect(initialSignal?.isLost()).toBe(false);
    expect(connection.events.listenerCount('error')).toBe(1);
    expect(connection.events.listenerCount('end')).toBe(1);
    expect(connection.query).toHaveBeenCalledOnce();
  });

  it('cleans up listeners and lookup state when the pool removes a connection', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(44, 7_004);

    poolEvents.emit('connect', connection.client);
    expect(await verifyClient(monitor, connection.client)).toBeUndefined();
    const signal = monitor.signalForBackendPid(7_004);

    poolEvents.emit('remove', connection.client);

    expect(signal?.isLost()).toBe(true);
    await expect(signal?.lost).resolves.toBeUndefined();
    expect(monitor.signalForBackendPid(7_004)).toBeUndefined();
    expect(connection.events.listenerCount('error')).toBe(0);
    expect(connection.events.listenerCount('end')).toBe(0);
  });

  it('does not let removal of an old connection erase a reused PID mapping', async () => {
    const { monitor, poolEvents } = createMonitor();
    const oldConnection = createClient(45, 7_005);
    const newConnection = createClient(46, 7_005);

    poolEvents.emit('connect', oldConnection.client);
    expect(await verifyClient(monitor, oldConnection.client)).toBeUndefined();
    const oldSignal = monitor.signalForBackendPid(7_005);

    poolEvents.emit('connect', newConnection.client);
    expect(await verifyClient(monitor, newConnection.client)).toBeUndefined();
    const newSignal = monitor.signalForBackendPid(7_005);

    expect(newSignal).not.toBe(oldSignal);

    poolEvents.emit('remove', oldConnection.client);

    expect(oldSignal?.isLost()).toBe(true);
    expect(monitor.signalForBackendPid(7_005)).toBe(newSignal);
    expect(newSignal?.isLost()).toBe(false);
  });

  it('fails initialization without publishing a signal when the PID query fails', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(47, 7_006);
    const failure = new Error('synthetic PID query failure');

    connection.query.mockRejectedValueOnce(failure);
    poolEvents.emit('connect', connection.client);

    expect(await verifyClient(monitor, connection.client)).toBe(failure);
    expect(monitor.signalForBackendPid(47)).toBeUndefined();
    expect(monitor.signalForBackendPid(7_006)).toBeUndefined();

    poolEvents.emit('remove', connection.client);

    expect(connection.events.listenerCount('error')).toBe(0);
    expect(connection.events.listenerCount('end')).toBe(0);
  });

  it('fails initialization if the connection is lost while the PID query is pending', async () => {
    const { monitor, poolEvents } = createMonitor();
    const connection = createClient(48, 7_007);
    let resolveQuery!: (result: QueryResult<BackendPidRow>) => void;

    connection.query.mockImplementationOnce(() => new Promise((resolve) => {
      resolveQuery = resolve;
    }));
    poolEvents.emit('connect', connection.client);
    const verification = verifyClient(monitor, connection.client);

    connection.events.emit('end');
    resolveQuery(backendPidResult(7_007));

    await expect(verification).resolves.toEqual(
      new Error('PostgreSQL connection was lost during monitoring initialization'),
    );
    expect(monitor.signalForBackendPid(7_007)).toBeUndefined();
  });

  it('holds a new pool checkout until asynchronous monitoring initialization succeeds', async () => {
    let resolveQuery!: (result: QueryResult<BackendPidRow>) => void;
    const clientDouble = createPoolClientDouble(() => new Promise((resolve) => {
      resolveQuery = resolve;
    }));
    const monitor = new DatabaseConnectionLossMonitor();
    const pool = new Pool({
      Client: clientDouble.Client,
      max: 1,
      verify: monitor.verify,
    });

    monitor.attach(pool);

    let checkoutCompleted = false;
    const checkout = pool.connect().then((client) => {
      checkoutCompleted = true;

      return client;
    });

    await Promise.resolve();
    await Promise.resolve();

    expect(checkoutCompleted).toBe(false);

    resolveQuery(backendPidResult(7_008));
    const client = await checkout;

    expect(checkoutCompleted).toBe(true);
    expect(monitor.signalForBackendPid(49)).toBeUndefined();
    expect(monitor.signalForBackendPid(7_008)).toBeDefined();

    client.release();
    await pool.end();
  });

  it('rejects a pool checkout and removes the client after initialization fails', async () => {
    const failure = new Error('synthetic checkout initialization failure');
    const clientDouble = createPoolClientDouble(() => Promise.reject(failure));
    const monitor = new DatabaseConnectionLossMonitor();
    const pool = new Pool({
      Client: clientDouble.Client,
      max: 1,
      verify: monitor.verify,
    });

    monitor.attach(pool);

    await expect(pool.connect()).rejects.toBe(failure);
    expect(clientDouble.clients).toHaveLength(1);
    expect(clientDouble.clients[0]?.ended).toBe(true);
    expect(clientDouble.clients[0]?.listenerCount('end')).toBe(0);
    expect(monitor.signalForBackendPid(49)).toBeUndefined();

    await pool.end();
  });
});
