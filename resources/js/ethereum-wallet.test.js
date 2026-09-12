import assert from 'node:assert/strict';
import { test } from 'node:test';
import { detectEthereumWallets, ethToWei, fetchEthereumBalance, sendEthereumSwap, verifyEthereumWallet } from './ethereum-wallet.js';

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
    }, payload);

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
        }, payload),
        /User rejected/,
    );

    assert.deepEqual(posts, ['order', 'cancelled']);
});

test('does not request an order when a different wallet account is active', async () => {
    let backendCalled = false;
    const wallet = { request: async () => ['0x3333333333333333333333333333333333333333'] };

    await assert.rejects(sendEthereumSwap(wallet, async () => { backendCalled = true; }, {
        wallet_address: '0x1111111111111111111111111111111111111111', sell_amount_wei: '1',
    }), /does not match/);
    assert.equal(backendCalled, false);
});
