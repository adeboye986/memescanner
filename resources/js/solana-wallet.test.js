import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Keypair, TransactionMessage, VersionedTransaction } from '@solana/web3.js';
import {
    detectWallets,
    disconnectWallet,
    fetchSwapQuote,
    fetchWalletBalance,
    defaultSpendFromBalance,
    formatBaseUnits,
    quotePresentation,
    solToLamports,
    signAndExecuteSwap,
    validateQuoteSpend,
    verifyWallet,
} from './solana-wallet.js';

test('confirm-first swap prepares, wallet-signs, then submits exact serialized transaction', async () => {
    const signer = Keypair.generate();
    const transaction = new VersionedTransaction(new TransactionMessage({
        payerKey: signer.publicKey,
        recentBlockhash: Keypair.generate().publicKey.toBase58(),
        instructions: [],
    }).compileToV0Message());
    const prepared = Buffer.from(transaction.serialize()).toString('base64');
    const calls = [];

    const result = await signAndExecuteSwap({
        signTransaction: async (candidate) => {
            candidate.sign([signer]);
            return candidate;
        },
    }, async (step, payload) => {
        calls.push(step);
        if (step === 'order') {
            assert.equal(payload.amount, '1000000');
            return { order: { attempt_id: 7, transaction: prepared, expires_at: new Date(Date.now() + 60000).toISOString() } };
        }
        assert.equal(payload.attempt_id, 7);
        assert.notEqual(payload.signed_transaction, prepared);
        return { swap: { status: 'submitted', signature: 'chain-signature' } };
    }, {
        amount: '1000000',
    }, () => {});

    assert.deepEqual(calls, ['order', 'execute']);
    assert.equal(result.signature, 'chain-signature');
});

test('wallet rejection never submits a prepared swap', async () => {
    const signer = Keypair.generate();
    const transaction = new VersionedTransaction(new TransactionMessage({
        payerKey: signer.publicKey,
        recentBlockhash: Keypair.generate().publicKey.toBase58(),
        instructions: [],
    }).compileToV0Message());
    let calls = 0;

    await assert.rejects(signAndExecuteSwap({ signTransaction: async () => { throw new Error('User rejected'); } }, async (step) => {
        calls += 1;
        assert.equal(step, 'order');
        return { order: { attempt_id: 8, transaction: Buffer.from(transaction.serialize()).toString('base64'), expires_at: new Date(Date.now() + 60000).toISOString() } };
    }, {
        amount: '1000000',
    }), /rejected/);

    assert.equal(calls, 1);
});

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

test('disconnect returns only after backend confirmation', async () => {
    const result = await disconnectWallet(async (step, payload) => {
        assert.equal(step, 'disconnect');
        assert.deepEqual(payload, {});

        return { disconnected: true, wallet: { chain: 'solana' } };
    });

    assert.equal(result.disconnected, true);
});

test('failed disconnect cannot produce a disconnected result', async () => {
    await assert.rejects(
        disconnectWallet(async () => { throw new Error('No active wallet'); }),
        /No active wallet/,
    );

    await assert.rejects(
        disconnectWallet(async () => ({ disconnected: false, wallet: { chain: 'solana' } })),
        /not confirmed/,
    );
});

test('balance helper returns verified solana balance response', async () => {
    const get = async (step) => {
        assert.equal(step, 'balance');

        return {
            balance: {
                chain: 'solana',
                lamports: 2500000000,
                sol: '2.500000000',
                usd: '500.00',
            },
            price: { sol_usd: '200' },
            quote_limits: { maximum_lamports: 100000000, suggested_spend_lamports: 1000000 },
        };
    };

    const result = await fetchWalletBalance(get);

    assert.deepEqual(result, {
        chain: 'solana',
        lamports: 2500000000,
        sol: '2.500000000',
        usd: '500.00',
        sol_usd: '200',
        maximum_lamports: 100000000,
        suggested_spend_lamports: 1000000,
    });
});

test('missing USD valuation keeps the exact SOL balance available', async () => {
    const result = await fetchWalletBalance(async () => ({
        balance: { chain: 'solana', lamports: 6793573, sol: '0.006793573', usd: null },
        price: { sol_usd: null },
        quote_limits: { maximum_lamports: 100000000, suggested_spend_lamports: 679357 },
    }));

    assert.equal(result.sol, '0.006793573');
    assert.equal(result.usd, null);
});

test('malformed USD valuation is ignored without hiding valid SOL', async () => {
    const result = await fetchWalletBalance(async () => ({
        balance: { chain: 'solana', lamports: 1000000000, sol: '1.000000000', usd: 'NaN' },
        price: { sol_usd: 'secret' },
        quote_limits: { maximum_lamports: 100000000, suggested_spend_lamports: 1000000 },
    }));

    assert.equal(result.sol, '1.000000000');
    assert.equal(result.usd, null);
});

test('quote preview presents normalized values', () => {
    const presentation = quotePresentation({
        input: { amount: '10000000' },
        output: { amount: '2500000', amount_formatted: '2.5', decimals: 6, symbol: 'USDC' },
        minimum_received: '2475000',
        minimum_received_formatted: '2.475',
        slippage_bps: 100,
        price_impact_pct: '0.001',
        spend_usd: '2.00',
        route: [{ label: 'Raydium', fee_amount: '10' }],
    });

    assert.deepEqual(presentation, {
        spend: '0.01 SOL',
        spendUsd: '≈ $2.00 USD',
        output: '2.5 USDC',
        minimum: '2.475 USDC',
        slippage: '1.00%',
        impact: '0.001%',
        route: 'Raydium',
        fees: '10 base units',
    });
});

test('quote preview uses token fallback when symbol is unavailable', () => {
    const presentation = quotePresentation({
        input: { amount: '1000000' },
        output: { amount: '104444', amount_formatted: '0.104444', decimals: 6, symbol: null },
        minimum_received: '103400',
        minimum_received_formatted: '0.1034',
        slippage_bps: 100,
        price_impact_pct: '0.001',
        spend_usd: null,
        route: [{ label: 'GoonFi V2', fee_amount: null }],
    });

    assert.equal(presentation.output, '0.104444 tokens');
    assert.equal(presentation.minimum, '0.1034 tokens');
});

test('quote preview falls back to raw base units when decimals are unavailable', () => {
    const presentation = quotePresentation({
        input: { amount: '1000000' },
        output: { amount: '104444', amount_formatted: null, decimals: null, symbol: null },
        minimum_received: '103400',
        minimum_received_formatted: null,
        slippage_bps: 100,
        price_impact_pct: '0.001',
        spend_usd: null,
        route: [{ label: 'GoonFi V2', fee_amount: null }],
    });

    assert.equal(presentation.output, '104444 base units');
    assert.equal(presentation.minimum, '103400 base units');
});

test('base-unit formatting preserves padding and large integer precision', () => {
    assert.equal(formatBaseUnits('104444', 6), '0.104444');
    assert.equal(formatBaseUnits('103400', 6), '0.1034');
    assert.equal(formatBaseUnits('44', 6), '0.000044');
    assert.equal(formatBaseUnits('123456789012345678901234567890', 6), '123456789012345678901234.56789');
});

test('default quote spend never exceeds wallet balance or configured maximum', () => {
    const balance = { lamports: 6793573, maximum_lamports: 100000000, suggested_spend_lamports: 679357 };

    assert.equal(defaultSpendFromBalance(balance), '0.000679357');
    assert.ok(BigInt(solToLamports(defaultSpendFromBalance(balance))) <= BigInt(balance.lamports));
    assert.ok(BigInt(solToLamports(defaultSpendFromBalance(balance))) <= BigInt(balance.maximum_lamports));
    assert.equal(defaultSpendFromBalance({ ...balance, suggested_spend_lamports: 0 }), '');
    assert.equal(defaultSpendFromBalance({ ...balance, suggested_spend_lamports: 7000000 }), '');
});

test('quote spend guard accepts exact balance and rejects excessive spend', () => {
    assert.doesNotThrow(() => validateQuoteSpend('1000000', 1000000, 100000000));
    assert.throws(() => validateQuoteSpend('1000001', 1000000, 100000000), /wallet balance/);
    assert.throws(() => validateQuoteSpend('10000001', 100000000, 10000000), /maximum trade amount/);
    assert.throws(() => validateQuoteSpend('1', null, 10000000), /Refresh the wallet balance/);
});

test('quote failure never requests wallet signing', async () => {
    let signingRequests = 0;
    const wallet = { signMessage: async () => { signingRequests++; } };

    await assert.rejects(
        fetchSwapQuote(async () => { throw new Error('Quote unavailable'); }, {
            input_mint: 'So11111111111111111111111111111111111111112',
            output_mint: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            amount: '10000000',
            slippage_bps: 100,
        }),
        /Quote unavailable/,
    );

    assert.equal(signingRequests, 0);
    assert.equal(typeof wallet.signMessage, 'function');
});

test('balance helper rejects invalid balance responses', async () => {
    const get = async () => ({
        balance: {
            chain: 'solana',
            lamports: '2500000000',
            sol: '2.500000000',
        },
    });

    await assert.rejects(
        () => fetchWalletBalance(get),
        /invalid wallet balance/i,
    );
});

test('balance helper rejects non-solana balance responses', async () => {
    const get = async () => ({
        balance: {
            chain: 'ethereum',
            lamports: 1000000000,
            sol: '1.000000000',
        },
    });

    await assert.rejects(
        () => fetchWalletBalance(get),
        /invalid wallet balance/i,
    );
});
