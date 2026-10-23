import assert from 'node:assert/strict';
import test from 'node:test';
import { createDashboardActivityPoller, failureState } from '../../resources/js/dashboard-activity-poller.js';

class FakeDocument {
    hidden = false;

    listeners = new Map();

    addEventListener(name, listener) {
        this.listeners.set(name, listener);
    }

    removeEventListener(name) {
        this.listeners.delete(name);
    }

    dispatch(name) {
        this.listeners.get(name)?.();
    }
}

const timers = () => {
    let nextId = 0;

    return {
        setTimeoutFn: () => ++nextId,
        clearTimeoutFn: () => {},
    };
};

test('failure state distinguishes unavailable from stale data', () => {
    assert.equal(failureState(null, 20_000, 15_000), 'unavailable');
    assert.equal(failureState(10_000, 20_000, 15_000), 'unavailable');
    assert.equal(failureState(1_000, 20_000, 15_000), 'stale');
});

test('poller prevents overlapping requests and records a successful refresh', async () => {
    const documentRef = new FakeDocument();
    let resolveFetch;
    let fetchCount = 0;
    const states = [];
    const pendingResponse = new Promise((resolve) => {
        resolveFetch = resolve;
    });
    const poller = createDashboardActivityPoller({
        endpoint: '/dashboard/activity',
        documentRef,
        ...timers(),
        now: () => 42_000,
        fetchImpl: () => {
            fetchCount += 1;

            return pendingResponse;
        },
        onState: (state) => states.push(state),
    });

    const first = poller.start();
    assert.equal(poller.isInFlight(), true);
    assert.equal(await poller.pollNow(), false);
    assert.equal(fetchCount, 1);

    resolveFetch({
        ok: true,
        status: 200,
        json: async () => ({ current_activities: [] }),
    });

    assert.equal(await first, true);
    assert.equal(poller.lastSuccessfulAt(), 42_000);
    assert.deepEqual(states, ['connected']);
    poller.stop();
});

test('poller stops after authentication expiry', async () => {
    const documentRef = new FakeDocument();
    const states = [];
    let fetchCount = 0;
    const poller = createDashboardActivityPoller({
        endpoint: '/dashboard/activity',
        documentRef,
        ...timers(),
        fetchImpl: async () => {
            fetchCount += 1;

            return { ok: false, status: 419 };
        },
        onState: (state) => states.push(state),
    });

    assert.equal(await poller.start(), false);
    assert.equal(await poller.pollNow(), false);
    assert.equal(fetchCount, 1);
    assert.deepEqual(states, ['authentication_expired']);
});

test('polling pauses while hidden and resumes immediately when visible', async () => {
    const documentRef = new FakeDocument();
    documentRef.hidden = true;
    let fetchCount = 0;
    const states = [];
    const poller = createDashboardActivityPoller({
        endpoint: '/dashboard/activity',
        documentRef,
        ...timers(),
        fetchImpl: async () => {
            fetchCount += 1;

            return { ok: true, status: 200, json: async () => ({}) };
        },
        onState: (state) => states.push(state),
    });

    assert.equal(await poller.start(), false);
    assert.equal(fetchCount, 0);
    documentRef.hidden = false;
    documentRef.dispatch('visibilitychange');
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(fetchCount, 1);
    assert.deepEqual(states, ['paused', 'connected']);
    poller.stop();
});

test('non-2xx responses preserve the last success and report stale data', async () => {
    const documentRef = new FakeDocument();
    const states = [];
    let currentTime = 10_000;
    let response = { ok: true, status: 200, json: async () => ({}) };
    const poller = createDashboardActivityPoller({
        endpoint: '/dashboard/activity',
        documentRef,
        ...timers(),
        now: () => currentTime,
        staleAfterMs: 15_000,
        fetchImpl: async () => response,
        onState: (state) => states.push(state),
    });

    assert.equal(await poller.start(), true);
    currentTime = 30_000;
    response = { ok: false, status: 503 };
    assert.equal(await poller.pollNow(), false);

    assert.equal(poller.lastSuccessfulAt(), 10_000);
    assert.deepEqual(states, ['connected', 'stale']);
    poller.stop();
});

test('poller treats an authentication redirect as expiry instead of successful JSON', async () => {
    const states = [];
    const poller = createDashboardActivityPoller({
        endpoint: '/dashboard/activity',
        documentRef: new FakeDocument(),
        ...timers(),
        fetchImpl: async () => ({ ok: true, status: 200, redirected: true }),
        onState: (state) => states.push(state),
    });

    assert.equal(await poller.start(), false);
    assert.deepEqual(states, ['authentication_expired']);
});
