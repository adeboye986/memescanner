import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createEthereumSwapRecovery, sendEthereumOpportunity } from './ethereum-wallet.js';
import { mountEthereumOpportunity } from './ethereum-opportunity.js';

const address = `0x${'1'.repeat(40)}`;
const hash = `0x${'a'.repeat(64)}`;
const binding = { attempt_id: 8, wallet_address: address, sell_amount_wei: '1000' };
const order = () => ({ attempt_id: 8, signing_claim_token: 'c'.repeat(64), valid_for_ms: 10000, expires_at: new Date(Date.now() + 60000).toISOString(), transaction: {
    from: address, to: `0x${'2'.repeat(40)}`, value: '1000', data: '0x1234', gas: '21000', gasPrice: '100', chainId: '1',
} });
const acknowledged = () => ({ swap: { status: 'submitted', transaction_hash: hash } });
function fixture(options = {}) {
    const calls = [], posts = [], records = new Map();
    const storage = { getItem: key => records.get(key), setItem: (key, value) => { records.set(key, value); }, removeItem: key => records.delete(key) };
    const recovery = createEthereumSwapRecovery('42', address, options.storage ?? storage);
    const wallet = { request: async request => {
        calls.push(request);
        if (request.method === 'eth_accounts') return [options.account ?? address];
        if (request.method === 'eth_chainId') return options.chain ?? '0x1';
        return options.send ? options.send(request) : hash;
    } };
    const post = async (step, payload) => {
        posts.push({ step, payload });
        if (step === 'confirm') return { order: options.order ?? order() };
        if (step === 'arm') return options.arm ? options.arm(payload) : { armed: true, valid_for_ms: 10000 };
        if (step === 'release') return { armed: false, status: 'prepared' };
        if (step === 'rejected') return { status: 'cancelled' };
        assert.equal(recovery.pending().transaction_hash, hash);
        if (options.report) return options.report(payload);
        return acknowledged();
    };
    return { calls, posts, records, storage, recovery, wallet, post, send: () => sendEthereumOpportunity(wallet, post, binding, recovery) };
}

test('explicit handoff sends exact prepared fields and saves hash before reporting', async () => {
    const f = fixture();
    await f.send();
    assert.deepEqual(f.calls.find(c => c.method === 'eth_sendTransaction').params, [{ from: address, to: order().transaction.to, data: '0x1234', value: '0x3e8', gas: '0x5208', gasPrice: '0x64', chainId: '0x1' }]);
    assert.deepEqual(f.posts.map(p => p.step), ['confirm', 'arm', 'submitted']);
    assert.equal(f.recovery.pending(), null);
    assert.equal(f.records.size, 0);
});
for (const options of [{ account: `0x${'9'.repeat(40)}` }, { chain: '0x89' }]) {
    test(`wrong account/network blocks handoff: ${JSON.stringify(options)}`, async () => {
        const f = fixture(options);
        await assert.rejects(f.send());
        assert.equal(f.posts.length, 0);
        assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
    });
}
test('wallet account change during handoff prevents sending', async () => {
    const f = fixture();
    let reads = 0;
    f.wallet.request = async ({ method }) => method === 'eth_accounts' ? [++reads === 1 ? address : `0x${'9'.repeat(40)}`] : '0x1';
    await assert.rejects(f.send(), /account/);
    assert.deepEqual(f.posts.map(p => p.step), ['confirm', 'release']);
});
test('expired or malformed prepared payload never sends', async () => {
    for (const change of [o => { o.valid_for_ms = 0; }, o => { o.transaction.value = '999'; }, o => { o.attempt_id = 999; }]) {
        const payload = order(); change(payload);
        const f = fixture({ order: payload });
        await assert.rejects(f.send());
        assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
        assert.equal(f.recovery.pending(), null);
    }
});
test('storage failure still reports the returned hash', async () => {
    const f = fixture({ storage: { getItem() {}, setItem() { throw Error('quota'); }, removeItem() {} } });
    await f.send();
    assert.equal(f.posts.at(-1).payload.transaction_hash, hash);
});
test('report timeout retains hash; retry and reload reuse it without signing or preparing', async () => {
    const f = fixture({ report: () => { throw Error('timeout after server acknowledgement'); } });
    await assert.rejects(f.send(), /reporting is unresolved/);
    await assert.rejects(f.send(), /previously broadcast/);
    await assert.rejects(f.recovery.recover(f.post));
    const reloaded = createEthereumSwapRecovery('42', address, f.storage);
    await reloaded.recover(async (step, body) => {
        assert.equal(step, 'submitted'); assert.deepEqual(body, { attempt_id: 8, transaction_hash: hash }); return acknowledged();
    });
    assert.equal(f.calls.filter(c => c.method === 'eth_sendTransaction').length, 1);
    assert.equal(f.posts.filter(p => p.step === 'confirm').length, 1);
    assert.equal(f.records.size, 0);
});
test('only explicit wallet rejection cancels; ambiguous errors keep signing blocked', async () => {
    for (const code of [4001, -32000]) {
        const f = fixture({ send: () => { throw Object.assign(Error('request rejected by transport'), { code }); } });
        await assert.rejects(f.send());
        assert.equal(f.recovery.pending(), null);
        assert.equal(f.posts.some(p => p.step === 'rejected'), code === 4001);
        if (code !== 4001) await assert.rejects(f.send(), /unknown/);
        assert.equal(f.calls.filter(c => c.method === 'eth_sendTransaction').length, 1);
    }
});
test('expiry while popup is open still reports the returned hash', async () => {
    const payload = order();
    const f = fixture({ order: payload, send: () => { payload.expires_at = new Date(0).toISOString(); return hash; } });
    await f.send();
    assert.equal(f.posts.at(-1).step, 'submitted');
    assert.equal(f.posts.some(p => p.step === 'cancelled'), false);
});
test('two tabs sharing server one-time handoff result in one wallet send', async () => {
    const first = fixture(), second = fixture();
    let claimed = false;
    const post = async step => {
        if (step === 'confirm') {
            if (claimed) throw Error('already claimed');
            claimed = true; return { order: order() };
        }
        if (step === 'arm') return { armed: true, valid_for_ms: 10000 };
        return acknowledged();
    };
    const results = await Promise.allSettled([first, second].map(f => sendEthereumOpportunity(f.wallet, post, binding, f.recovery)));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal([...first.calls, ...second.calls].filter(c => c.method === 'eth_sendTransaction').length, 1);
});
test('page mount does not request accounts or send; only explicit Confirm click sends', async () => {
    const f = fixture();
    const listeners = new Map();
    const feedback = {};
    const confirm = { addEventListener: (name, cb) => listeners.set(name, cb) };
    const section = { dataset: { userId: '42', attemptId: '8', walletAddress: address, sellAmountWei: '1000', confirmUrl: '/confirm', armUrl: '/arm', submittedUrl: '/submitted' },
        querySelector: selector => selector === '[data-opportunity-feedback]' ? feedback : selector === '[data-opportunity-confirm]' ? confirm : null };
    const oldFetch = globalThis.fetch, oldDocument = globalThis.document;
    globalThis.document = { querySelector: () => null };
    globalThis.fetch = async url => ({ ok: true, json: async () => url === '/confirm' ? { order: order() } : url === '/arm' ? { armed: true, valid_for_ms: 10000 } : acknowledged() });
    try {
        mountEthereumOpportunity(section, { ethereum: f.wallet });
        assert.equal(f.calls.length, 0);
        await listeners.get('click')();
        assert.equal(f.calls.filter(c => c.method === 'eth_sendTransaction').length, 1);
        assert.match(feedback.textContent, /awaiting blockchain confirmation/);
        assert.doesNotMatch(feedback.textContent, /Executed/);
    } finally { globalThis.fetch = oldFetch; globalThis.document = oldDocument; }
});

test('mounted reload, retry, and wallet reconnect report the saved attempt without sending', async () => {
    const f = fixture();
    f.recovery.remember(77, hash);
    const buttons = new Map(), providerEvents = new Map(), reports = [];
    const feedback = {};
    const button = selector => ({ addEventListener: (name, callback) => buttons.set(selector, callback) });
    const confirm = button('confirm'), retry = button('retry');
    const picker = { replaceChildren() {}, append() {}, value: '0' };
    f.wallet.on = (name, callback) => providerEvents.set(name, callback);
    const section = { dataset: { userId: '42', attemptId: '8', walletAddress: address, sellAmountWei: '1000', submittedUrl: '/submitted' },
        querySelector: selector => ({ '[data-opportunity-feedback]': feedback, '[data-opportunity-confirm]': confirm,
            '[data-opportunity-report]': retry, '[data-opportunity-wallet]': picker }[selector] ?? null) };
    const previous = { fetch: globalThis.fetch, document: globalThis.document, sessionStorage: globalThis.sessionStorage };
    globalThis.sessionStorage = f.storage;
    globalThis.document = { querySelector: () => null, createElement: () => ({}) };
    globalThis.fetch = async (url, request) => {
        assert.equal(url, '/submitted'); reports.push(JSON.parse(request.body));
        return { ok: reports.length >= 3, status: 503, json: async () => reports.length >= 3 ? acknowledged() : { message: 'Retry report.' } };
    };
    try {
        mountEthereumOpportunity(section, { ethereum: f.wallet });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(confirm.disabled, true);
        await buttons.get('retry')();
        await providerEvents.get('connect')();
        assert.deepEqual(reports, Array(3).fill({ attempt_id: 77, transaction_hash: hash }));
        assert.equal(f.calls.length, 0);
        assert.equal(f.records.size, 0);
        assert.match(feedback.textContent, /Previous transaction reported/);
    } finally { Object.assign(globalThis, previous); }
});

for (const change of ['account', 'chain', 'unavailable']) {
    test(`post-claim ${change} failure releases exact claim and permits safe retry`, async () => {
        const f = fixture();
        let claimed = false;
        const post = async (step, body) => {
            if (step === 'confirm') claimed = true;
            return f.post(step, body);
        };
        f.wallet.request = async ({ method }) => {
            if (claimed && change === 'unavailable') throw Error('disconnected');
            if (method === 'eth_accounts') return [claimed && change === 'account' ? `0x${'9'.repeat(40)}` : address];
            if (method === 'eth_chainId') return claimed && change === 'chain' ? '0x89' : '0x1';
            assert.fail('must not send');
        };
        await assert.rejects(sendEthereumOpportunity(f.wallet, post, binding, f.recovery));
        assert.deepEqual(f.posts.map(p => p.step), ['confirm', 'release']);
        assert.deepEqual(f.posts.at(-1).payload, { signing_claim_token: 'c'.repeat(64) });
        assert.equal(f.recovery.uncertain, false);
    });
}
test('arm failure prevents sending even with an incorrect browser wall clock', async () => {
    const original = Date.now;
    Date.now = () => 0;
    try {
        const f = fixture({ arm: () => { throw Error('server expired'); } });
        await assert.rejects(f.send(), /server expired/);
        assert.deepEqual(f.posts.map(p => p.step), ['confirm', 'arm']);
        assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
        assert.equal(f.recovery.uncertain, true);
    } finally { Date.now = original; }
});
for (const delayedStep of ['confirm', 'arm']) {
    test(`monotonic budget prevents delayed ${delayedStep} response from authorizing send`, async () => {
        const original = globalThis.performance;
        let elapsed = 0;
        globalThis.performance = { now: () => elapsed };
        try {
            const f = fixture();
            await assert.rejects(sendEthereumOpportunity(f.wallet, async (step, body) => {
                const response = await f.post(step, body);
                if (step === delayedStep) elapsed += 20000;
                return response;
            }, binding, f.recovery), /expired/);
            assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
            assert.equal(f.posts.some(p => p.step === 'release'), delayedStep === 'confirm');
            assert.equal(f.recovery.uncertain, delayedStep === 'arm');
        } finally { globalThis.performance = original; }
    });
}
test('same-page double click while wallet is pending makes one claim, arm and prompt', async () => {
    let resolveSend, notifyEntered;
    const entered = new Promise(resolve => { notifyEntered = resolve; });
    const waiting = new Promise(resolve => { resolveSend = resolve; });
    const f = fixture({ send: () => { notifyEntered(); return waiting; } });
    const first = f.send();
    await entered;
    await assert.rejects(f.send(), /pending or unknown/);
    resolveSend(hash);
    await first;
    assert.deepEqual(f.posts.map(p => p.step), ['confirm', 'arm', 'submitted']);
    assert.equal(f.calls.filter(c => c.method === 'eth_sendTransaction').length, 1);
});
test('lost handoff response remains unresolved without release, arm or resend', async () => {
    const f = fixture();
    const post = async () => { throw Error('response lost'); };
    await assert.rejects(sendEthereumOpportunity(f.wallet, post, binding, f.recovery));
    await assert.rejects(f.send(), /unknown/);
    assert.equal(f.recovery.uncertain, true);
    assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
});
test('definitive rejection is bound to exact armed claim while ambiguous error cannot release', async () => {
    for (const code of [4001, -32000]) {
        const f = fixture({ send: () => { throw Object.assign(Error('private provider error'), { code }); } });
        await assert.rejects(f.send());
        assert.equal(f.posts.some(p => p.step === 'release' || p.step === 'cancelled'), false);
        if (code === 4001) {
            assert.deepEqual(f.posts.at(-1), { step: 'rejected', payload: { signing_claim_token: 'c'.repeat(64), rejection_code: 4001 } });
        } else {
            assert.equal(f.recovery.uncertain, true);
        }
    }
});

test('reload with unresolved armed server state disables confirmation and never sends', () => {
    const f = fixture();
    const confirm = { addEventListener() {} };
    const section = { dataset: { userId: '42', attemptId: '8', walletAddress: address, sellAmountWei: '1000', signingRequested: '1' },
        querySelector: selector => selector === '[data-opportunity-confirm]' ? confirm : selector === '[data-opportunity-feedback]' ? {} : null };
    mountEthereumOpportunity(section, { ethereum: f.wallet });
    assert.equal(confirm.disabled, true);
    assert.equal(f.calls.length, 0);
});

test('an expired released claim is marked unavailable instead of reopening confirmation', async () => {
    const f = fixture({ order: { ...order(), valid_for_ms: 0 } });
    await assert.rejects(sendEthereumOpportunity(f.wallet, async (step, body) => step === 'release'
        ? { armed: false, status: 'expired' } : f.post(step, body), binding, f.recovery), error => error.attemptUnavailable === true);
    assert.equal(f.recovery.uncertain, false);
    assert.equal(f.calls.some(c => c.method === 'eth_sendTransaction'), false);
});
