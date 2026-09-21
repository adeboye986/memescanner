import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createEthereumSwapRecovery, mountEthereumWalletCard, detectEthereumWallets, ethToWei, fetchEthereumBalance, sendEthereumSwap, verifyEthereumWallet } from './ethereum-wallet.js';

test('detects Phantom first, then MetaMask and EIP-6963 compatible wallets without duplicates', () => {
    const phantom = { request: async () => {}, isPhantom: true };
    const metamask = { request: async () => {}, isMetaMask: true };
    const compatible = { request: async () => {} };
    const wallets = detectEthereumWallets({ phantom: { ethereum: phantom }, ethereum: metamask }, [
        { provider: compatible, info: { name: 'Rabby', rdns: 'io.rabby' } },
        { provider: phantom, info: { name: 'Phantom' } },
    ]);

    assert.deepEqual(wallets.map((wallet) => wallet.provider), ['phantom', 'compatible', 'metamask']);
    assert.deepEqual(wallets.map((wallet) => wallet.label), ['Phantom', 'Rabby', 'MetaMask']);
});

test('verifies exact Ethereum ownership message on mainnet', async () => {
    const address = '0x1111111111111111111111111111111111111111';
    const challenge = { challenge_id: 4, message: 'Exact UTF-8 message ✓', expires_at: new Date(Date.now() + 60000).toISOString() };
    const signature = `0x${'ab'.repeat(65)}`;
    const requests = [];
    const posts = [];
    const wallet = {
        request: async (request) => {
            requests.push(request);
            if (request.method === 'eth_requestAccounts') return [address];
            if (request.method === 'eth_chainId') return '0x1';
            assert.deepEqual(request.params, [`0x${Buffer.from(challenge.message).toString('hex')}`, address]);
            return signature;
        },
    };

    const result = await verifyEthereumWallet({ wallet, provider: 'phantom' }, async (step, payload) => {
        posts.push(step);
        if (step === 'challenge') {
            assert.deepEqual(payload, { address, provider: 'phantom' });
            return challenge;
        }
        assert.deepEqual(payload, { challenge_id: 4, signature });
        return { verified: true, wallet: { chain: 'ethereum', address, provider: 'phantom' } };
    });

    assert.equal(result.address, address);
    assert.deepEqual(requests.map((request) => request.method), ['eth_requestAccounts', 'eth_chainId', 'personal_sign']);
    assert.deepEqual(posts, ['challenge', 'verify']);
});

test('wrong network never requests a signature or backend challenge', async () => {
    let backendCalled = false;
    const wallet = { request: async ({ method }) => method === 'eth_requestAccounts'
        ? ['0x1111111111111111111111111111111111111111'] : '0x89' };

    await assert.rejects(verifyEthereumWallet({ wallet, provider: 'compatible' }, async () => {
        backendCalled = true;
    }), /Ethereum Mainnet/);
    assert.equal(backendCalled, false);
});

test('validates normalized Ethereum balance response', async () => {
    const balance = await fetchEthereumBalance(async () => ({
        balance: { chain: 'ethereum', wei: '1250000000000000000', eth: '1.25', usd: '5000.00' },
    }));
    assert.equal(balance.eth, '1.25');
});

test('converts ETH to exact wei without floating point arithmetic', () => {
    assert.equal(ethToWei('0.000000000000000001'), '1');
    assert.equal(ethToWei('1.25'), '1250000000000000000');
    assert.throws(() => ethToWei('0.0000000000000000001'), /18 decimal places/);
});

test('requests a firm order only after the active account and network still match', async () => {
    const address = '0x1111111111111111111111111111111111111111';
    const destination = '0x2222222222222222222222222222222222222222';
    const hash = `0x${'ab'.repeat(32)}`;
    const methods = [];
    const posts = [];
    const wallet = { request: async ({ method, params }) => {
        methods.push(method);
        if (method === 'eth_accounts') return [address];
        if (method === 'eth_chainId') return '0x1';
        assert.deepEqual(params, [{
            from: address, to: destination, data: '0x1234', value: '0xde0b6b3a7640000', gas: '0x5208', gasPrice: '0x3b9aca00',
        }]);
        return hash;
    } };
    const payload = { wallet_address: address, buy_token: destination, sell_amount_wei: '1000000000000000000', slippage_bps: 100 };

    const result = await sendEthereumSwap(wallet, async (step, body) => {
        posts.push(step);
        if (step === 'order') return { order: { attempt_id: 9, transaction: {
            from: address, to: destination, data: '0x1234', value: payload.sell_amount_wei,
            gas: '21000', gasPrice: '1000000000', chainId: '1',
        } } };
        assert.deepEqual(body, { attempt_id: 9, transaction_hash: hash });
        return { swap: { status: 'submitted', transaction_hash: hash } };
    }, payload, createEthereumSwapRecovery('test', address, null));

    assert.equal(result.swap.transaction_hash, hash);
    assert.deepEqual(methods, ['eth_accounts', 'eth_chainId', 'eth_sendTransaction']);
    assert.deepEqual(posts, ['order', 'submitted']);
});

test('reports a prepared attempt as cancelled when the wallet rejects the transaction', async () => {
    const address = '0x1111111111111111111111111111111111111111';
    const destination = '0x2222222222222222222222222222222222222222';
    const posts = [];

    const wallet = {
        request: async ({ method }) => {
            if (method === 'eth_accounts') return [address];
            if (method === 'eth_chainId') return '0x1';

            const error = new Error('User rejected the request.');
            error.code = 4001;
            throw error;
        },
    };

    const payload = {
        wallet_address: address,
        buy_token: destination,
        sell_amount_wei: '1000000000000000000',
        slippage_bps: 100,
    };

    await assert.rejects(
        sendEthereumSwap(wallet, async (step, body) => {
            posts.push(step);

            if (step === 'order') {
                return {
                    order: {
                        attempt_id: 9,
                        transaction: {
                            from: address,
                            to: destination,
                            data: '0x1234',
                            value: payload.sell_amount_wei,
                            gas: '21000',
                            gasPrice: '1000000000',
                            chainId: '1',
                        },
                    },
                };
            }

            if (step === 'cancelled') {
                assert.deepEqual(body, { attempt_id: 9 });

                return { swap: { status: 'cancelled' } };
            }

            throw new Error(`Unexpected backend step: ${step}`);
        }, payload, createEthereumSwapRecovery('test', address, null)),
        /User rejected/,
    );

    assert.deepEqual(posts, ['order', 'cancelled']);
});

test('does not request an order when a different wallet account is active', async () => {
    let backendCalled = false;
    const wallet = { request: async () => ['0x3333333333333333333333333333333333333333'] };

    await assert.rejects(sendEthereumSwap(wallet, async () => { backendCalled = true; }, {
        wallet_address: '0x1111111111111111111111111111111111111111', sell_amount_wei: '1',
    }, createEthereumSwapRecovery('test', '0x1111111111111111111111111111111111111111', null)), /does not match/);
    assert.equal(backendCalled, false);
});


function recoveryFixture() {
    const address = `0x${'a'.repeat(40)}`;
    const hash = `0x${'ab'.repeat(32)}`;
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    const record = { user_id: '7', wallet_address: address, attempt_id: 42, transaction_hash: hash };
    const key = `ethereum-swap-report:v2:7:${address}`;
    const payload = { wallet_address: address, sell_amount_wei: '1', buy_token: `0x${'b'.repeat(40)}` };
    const methods = [];
    const posts = [];
    const wallet = { request: async ({ method }) => {
        methods.push(method);
        if (method === 'eth_accounts') return [address];
        if (method === 'eth_chainId') return '0x1';
        return hash;
    } };
    const post = async (step, body) => {
        posts.push(step);
        if (step === 'order') return { order: { attempt_id: 42, transaction: {
            from: address, to: payload.buy_token, data: '0x1234', value: '1',
            gas: '21000', gasPrice: '1', chainId: '1',
        } } };
        assert.equal(step, 'submitted');
        assert.deepEqual(body, { attempt_id: 42, transaction_hash: hash });
        return { swap: { status: 'submitted', transaction_hash: hash } };
    };
    return { address, hash, values, storage, record, key, payload, methods, posts, wallet, post };
}

test('persists only a returned hash before reporting and clears on acknowledgement', async () => {
    const f = recoveryFixture();
    let writes = 0;
    f.storage.setItem = (key, value) => {
        writes++;
        assert.equal(f.methods.at(-1), 'eth_sendTransaction');
        assert.deepEqual(JSON.parse(value), f.record);
        f.values.set(key, value);
    };
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await sendEthereumSwap(f.wallet, async (step, body) => {
        if (step === 'submitted') assert.deepEqual(JSON.parse(f.values.get(f.key)), f.record);
        return f.post(step, body);
    }, f.payload, recovery);
    assert.equal(writes, 1);
    assert.equal(f.values.size, 0);
    assert.equal(recovery.pending(), null);
    assert.deepEqual(f.methods, ['eth_accounts', 'eth_chainId', 'eth_sendTransaction']);
});

test('setItem failure cannot prevent reporting or cause another wallet send', async () => {
    const f = recoveryFixture();
    f.storage.setItem = () => { throw new Error('QuotaExceededError'); };
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await sendEthereumSwap(f.wallet, f.post, f.payload, recovery);
    assert.deepEqual(f.posts, ['order', 'submitted']);
    assert.equal(recovery.pending(), null);
    assert.equal(f.methods.filter(m => m === 'eth_sendTransaction').length, 1);
});

test('ambiguous report retains exact hash and explicit recovery works after reload without a wallet or quote', async () => {
    const f = recoveryFixture();
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await assert.rejects(sendEthereumSwap(f.wallet, async (step, body) => {
        if (step === 'submitted') throw Object.assign(new Error('RPC unavailable'), { status: 503 });
        return f.post(step, body);
    }, f.payload, recovery), /reporting is unresolved/);
    assert.deepEqual(JSON.parse(f.values.get(f.key)), f.record);
    const reloaded = createEthereumSwapRecovery('7', f.address, f.storage);
    await reloaded.recover(f.post);
    assert.deepEqual(f.posts, ['order', 'submitted']);
    assert.equal(f.methods.filter(m => m === 'eth_sendTransaction').length, 1);
    assert.equal(f.values.size, 0);
});

for (const [label, raw] of [
    ['malformed JSON', '{bad'],
    ['hashless record', JSON.stringify({ user_id: '7', wallet_address: '0x' + 'a'.repeat(40), attempt_id: 42, transaction_hash: null })],
    ['invalid attempt ID', JSON.stringify({ user_id: '7', wallet_address: '0x' + 'a'.repeat(40), attempt_id: -1, transaction_hash: '0x' + 'ab'.repeat(32) })],
]) {
    test(`${label} is discarded and a fresh swap remains usable`, async () => {
        const f = recoveryFixture();
        f.values.set(f.key, raw);
        const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
        assert.equal(recovery.pending(), null);
        assert.equal(f.values.size, 0);
        await sendEthereumSwap(f.wallet, f.post, f.payload, recovery);
        assert.deepEqual(f.posts, ['order', 'submitted']);
    });
}

for (const field of ['wallet_address', 'user_id']) {
    test(`recovery rejects mismatched ${field} even under the current storage key`, async () => {
        const f = recoveryFixture();
        f.values.set(f.key, JSON.stringify({ ...f.record, [field]: field === 'user_id' ? '8' : '0x' + 'c'.repeat(40) }));
        const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
        assert.equal(await recovery.recover(f.post), null);
        assert.deepEqual(f.posts, []);
    });
}

test('same wallet under another application user has a separate recovery context', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    assert.equal(await createEthereumSwapRecovery('8', f.address, f.storage).recover(f.post), null);
    assert.deepEqual(f.posts, []);
    assert.equal(f.values.size, 1);
});

test('existing broadcast blocks a new token/amount operation instead of silently substituting recovery', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await assert.rejects(sendEthereumSwap(f.wallet, f.post, { ...f.payload, buy_token: '0x' + 'c'.repeat(40), sell_amount_wei: '99' }, recovery), /Recover the previously broadcast/);
    assert.deepEqual(f.posts, []);
    assert.deepEqual(f.methods, []);
    assert.deepEqual(recovery.pending(), f.record);
});

for (const status of [403, 404]) {
    test(`authoritative ${status} discards stale recovery and does not block a new operation`, async () => {
        const f = recoveryFixture();
        f.values.set(f.key, JSON.stringify(f.record));
        const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
        await assert.rejects(recovery.recover(async () => { throw Object.assign(new Error('Unavailable'), { status }); }), /discarded/);
        assert.equal(recovery.pending(), null);
        assert.equal(f.values.size, 0);
        await sendEthereumSwap(f.wallet, f.post, f.payload, recovery);
        assert.deepEqual(f.posts, ['order', 'submitted']);
    });
}

for (const status of [503, 500, 422, 401, undefined]) {
    test(`unresolved reporting (${status ?? 'network error'}) retains the known broadcast`, async () => {
        const f = recoveryFixture();
        f.values.set(f.key, JSON.stringify(f.record));
        const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
        await assert.rejects(recovery.recover(async () => { throw Object.assign(new Error('Unresolved'), { status }); }), /reporting is unresolved/);
        assert.deepEqual(recovery.pending(), f.record);
        assert.equal(f.values.size, 1);
    });
}

test('getItem and removeItem exceptions do not prevent normal reporting', async () => {
    const f = recoveryFixture();
    f.storage.getItem = () => { throw new Error('SecurityError'); };
    f.storage.removeItem = () => { throw new Error('SecurityError'); };
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await sendEthereumSwap(f.wallet, f.post, f.payload, recovery);
    assert.equal(recovery.pending(), null);
    assert.deepEqual(f.posts, ['order', 'submitted']);
});

test('failed removal of acknowledged data allows idempotent recovery after reload without sending', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    f.storage.removeItem = () => { throw new Error('SecurityError'); };
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await recovery.recover(f.post);
    assert.equal(recovery.pending(), null);
    await createEthereumSwapRecovery('7', f.address, f.storage).recover(async (step, body) => {
        assert.equal(step, 'submitted');
        assert.equal(body.transaction_hash, f.hash);
        return { swap: { status: 'confirmed', transaction_hash: f.hash } };
    });
    assert.deepEqual(f.methods, []);
});

test('wallet rejection creates no durable recovery record', async () => {
    const f = recoveryFixture();
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    const wallet = { request: async ({ method }) => {
        if (method === 'eth_accounts') return [f.address];
        if (method === 'eth_chainId') return '0x1';
        throw Object.assign(new Error('Rejected'), { code: 4001 });
    } };
    const posts = [];
    await assert.rejects(sendEthereumSwap(wallet, async (step, body) => {
        posts.push(step);
        if (step === 'cancelled') return {};
        return f.post(step, body);
    }, f.payload, recovery), /Rejected/);
    assert.deepEqual(posts, ['order', 'cancelled']);
    assert.equal(f.values.size, 0);
    assert.equal(recovery.pending(), null);
    assert.equal(recovery.uncertain, false);
});

test('unknown wallet outcome stays in memory only and never automatically sends again', async () => {
    const f = recoveryFixture();
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    const wallet = { request: async ({ method }) => {
        if (method === 'eth_accounts') return [f.address];
        if (method === 'eth_chainId') return '0x1';
        throw new Error('Wallet transport disconnected');
    } };
    await assert.rejects(sendEthereumSwap(wallet, f.post, f.payload, recovery), /disconnected/);
    await assert.rejects(sendEthereumSwap(wallet, f.post, f.payload, recovery), /pending or unknown/);
    assert.equal(f.values.size, 0);
    assert.deepEqual(f.posts, ['order']);
});

test('mounted wallet page recovers a saved broadcast even when balance and pricing are unavailable', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    const originals = Object.fromEntries(['window', 'document', 'fetch', 'sessionStorage'].map(k => [k, Object.getOwnPropertyDescriptor(globalThis, k)]));
    const elements = new Map();
    const element = (selector) => {
        if (!elements.has(selector)) elements.set(selector, { hidden: false, textContent: '', title: '', addEventListener() {} });
        return elements.get(selector);
    };
    element('[data-eth-address]').title = f.address;
    const card = { dataset: { recoveryUser: '7', submittedUrl: '/submitted', balanceUrl: '/balance' }, querySelector: selector => selector === '[data-eth-history]' ? null : element(selector) };
    const requests = [];
    try {
        Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: f.storage });
        globalThis.window = { addEventListener() {}, dispatchEvent() {} };
        globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
        globalThis.fetch = async (url, options) => {
            requests.push(url);
            if (url === '/balance') throw new Error('Unavailable');
            assert.equal(url, '/submitted');
            assert.deepEqual(JSON.parse(options.body), { attempt_id: 42, transaction_hash: f.hash });
            return { ok: true, json: async () => ({ swap: { status: 'submitted', transaction_hash: f.hash } }) };
        };
        mountEthereumWalletCard(card);
        await new Promise(resolve => setImmediate(resolve));
        assert.ok(requests.includes('/submitted'));
        assert.ok(requests.every(url => ['/submitted', '/balance'].includes(url)));
        assert.equal(f.values.size, 0);
        assert.match(element('[data-eth-feedback]').textContent, /Previous Ethereum transaction submitted/);
    } finally {
        for (const [key, descriptor] of Object.entries(originals)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    }
});

test('blocked sessionStorage access still allows a normal submission report', async () => {
    const f = recoveryFixture();
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage');
    try {
        Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, get() { throw new Error('SecurityError'); } });
        await sendEthereumSwap(f.wallet, f.post, f.payload, createEthereumSwapRecovery('7', f.address));
        assert.deepEqual(f.posts, ['order', 'submitted']);
    } finally {
        if (descriptor) Object.defineProperty(globalThis, 'sessionStorage', descriptor);
        else delete globalThis.sessionStorage;
    }
});

test('a failed storage write plus failed report retains in-memory recovery', async () => {
    const f = recoveryFixture();
    f.storage.setItem = () => { throw new Error('QuotaExceededError'); };
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await assert.rejects(sendEthereumSwap(f.wallet, async (step, body) => {
        if (step === 'submitted') throw new Error('Network failure');
        return f.post(step, body);
    }, f.payload, recovery), /reporting is unresolved/);
    assert.deepEqual(recovery.pending(), f.record);
    await recovery.recover(f.post);
    assert.equal(f.methods.filter(m => m === 'eth_sendTransaction').length, 1);
    assert.equal(recovery.pending(), null);
});

test('an acknowledgement for a different hash does not clear recovery', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    await assert.rejects(recovery.recover(async () => ({ swap: { status: 'submitted', transaction_hash: '0x' + 'cd'.repeat(32) } })), /did not acknowledge/);
    assert.deepEqual(recovery.pending(), f.record);
    assert.equal(f.values.size, 1);
});

test('concurrent recovery requests share one report and cannot start another swap', async () => {
    const f = recoveryFixture();
    f.values.set(f.key, JSON.stringify(f.record));
    const recovery = createEthereumSwapRecovery('7', f.address, f.storage);
    let finish;
    let reports = 0;
    const post = async () => { reports++; return new Promise(resolve => { finish = resolve; }); };
    const first = recovery.recover(post);
    const second = recovery.recover(post);
    await assert.rejects(sendEthereumSwap(f.wallet, f.post, f.payload, recovery), /Recover the previously broadcast/);
    finish({ swap: { status: 'submitted', transaction_hash: f.hash } });
    await Promise.all([first, second]);
    assert.equal(reports, 1);
    assert.deepEqual(f.methods, []);
});
