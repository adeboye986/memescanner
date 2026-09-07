import { test } from 'node:test';
import assert from 'node:assert/strict';
import { detectWallets, verifyWallet } from './solana-wallet.js';

const fixture = () => {
    const wallet = { publicKey: 'address-a', connect: async () => {}, signMessage: async () => new Uint8Array(64) };
    const challenge = { challenge_id: 12, message: 'Exact message\nwith UTF-8: ✓', expires_at: new Date(Date.now() + 60000).toISOString() };
    return { wallet, challenge };
};

test('detects and deduplicates injected supported wallets', () => {
    const { wallet } = fixture();
    wallet.isPhantom = true;
    assert.equal(detectWallets({ phantom: { solana: wallet }, solana: wallet }).length, 1);
    assert.deepEqual(detectWallets({ solana: {} }), []);
    assert.equal(detectWallets({ solflare: fixture().wallet })[0].provider, 'solflare');
    assert.equal(detectWallets({ solana: fixture().wallet })[0].provider, 'compatible');
});

for (const shape of ['phantom', 'solflare']) {
    test(`${shape} signs exact UTF-8 bytes and submits base64 before returning verified state`, async () => {
        const { wallet, challenge } = fixture();
        wallet.signMessage = async (bytes) => {
            assert.equal(new TextDecoder().decode(bytes), challenge.message);
            return shape === 'phantom' ? { signature: new Uint8Array(64).fill(7) } : new Uint8Array(64).fill(7);
        };
        const calls = [];
        const result = await verifyWallet({ wallet, provider: shape }, async (step, payload) => {
            calls.push(step);
            if (step === 'challenge') {
                assert.deepEqual(payload, { address: 'address-a', provider: shape });
                return challenge;
            }
            assert.deepEqual(payload, { challenge_id: 12, signature: Buffer.alloc(64, 7).toString('base64') });
            return { verified: true, wallet: { address: 'address-a', chain: 'solana' } };
        });
        assert.equal(result.address, 'address-a');
        assert.deepEqual(calls, ['challenge', 'verify']);
    });
}

test('account change while signing aborts before verification', async () => {
    const { wallet, challenge } = fixture();
    wallet.signMessage = async () => { wallet.publicKey = 'address-b'; return new Uint8Array(64); };
    await assert.rejects(verifyWallet({ wallet, provider: 'compatible' }, async (step) => {
        assert.equal(step, 'challenge');
        return challenge;
    }), /account changed/);
});

test('expired challenges never reach signing', async () => {
    const { wallet, challenge } = fixture();
    wallet.signMessage = () => assert.fail('Must not sign');
    await assert.rejects(verifyWallet({ wallet, provider: 'phantom' }, async () => ({ ...challenge, expires_at: '2000-01-01' })), /expired/);
});

test('backend rejection never returns verified wallet', async () => {
    const { wallet, challenge } = fixture();
    await assert.rejects(verifyWallet({ wallet, provider: 'phantom' }, async (step) => {
        if (step === 'challenge') return challenge;
        throw new Error('Wallet belongs to another account');
    }), /another account/);
});

test('wallet signing rejection does not send verification request', async () => {
    const { wallet, challenge } = fixture();
    wallet.signMessage = async () => { throw new Error('User rejected'); };
    await assert.rejects(verifyWallet({ wallet, provider: 'phantom' }, async (step) => {
        assert.equal(step, 'challenge');
        return challenge;
    }), /rejected/);
});
