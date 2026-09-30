import test from 'node:test';
import assert from 'node:assert/strict';
import { Keypair, TransactionMessage, VersionedTransaction } from '@solana/web3.js';
import {
    createSolanaOpportunityRecovery,
    sendSolanaOpportunity,
} from './solana-opportunity.js';

const signature = '3'.repeat(88);

function preparedTransaction() {
    const signer = Keypair.generate();
    const transaction = new VersionedTransaction(new TransactionMessage({
        payerKey: signer.publicKey,
        recentBlockhash: Keypair.generate().publicKey.toBase58(),
        instructions: [],
    }).compileToV0Message());

    return Buffer.from(transaction.serialize()).toString('base64');
}

function storage() {
    const values = new Map();
    return {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
}

test('explicit wallet action signs and broadcasts before reporting the exact signature', async () => {
    const calls = [];
    const recovery = createSolanaOpportunityRecovery('7', 'wallet-a', storage());
    const result = await sendSolanaOpportunity({
        publicKey: { toString: () => 'wallet-a' },
        signAndSendTransaction: async (transaction) => {
            assert.ok(transaction instanceof VersionedTransaction);
            calls.push('wallet');
            return { signature };
        },
    }, async (step, payload = {}) => {
        calls.push(step);
        if (step === 'confirm') {
            return { order: { attempt_id: 9, transaction: preparedTransaction(), signing_claim_token: 'a'.repeat(64) } };
        }
        if (step === 'arm') {
            assert.equal(payload.signing_claim_token, 'a'.repeat(64));
            return { armed: true };
        }
        assert.deepEqual(payload, {
            attempt_id: 9,
            transaction_signature: signature,
            wallet_address: 'wallet-a',
        });
        return { swap: { status: 'submitted', transaction_signature: signature } };
    }, { attempt_id: 9, wallet_address: 'wallet-a' }, recovery);

    assert.deepEqual(calls, ['confirm', 'arm', 'wallet', 'submitted']);
    assert.equal(result.swap.transaction_signature, signature);
    assert.equal(recovery.pending(), null);
});

test('wallet rejection is recorded and never reports a transaction', async () => {
    const calls = [];
    const error = new Error('rejected');
    error.code = 4001;
    await assert.rejects(sendSolanaOpportunity({
        publicKey: { toString: () => 'wallet-a' },
        signAndSendTransaction: async () => { throw error; },
    }, async (step, payload = {}) => {
        calls.push(step);
        if (step === 'confirm') {
            return { order: { attempt_id: 9, transaction: preparedTransaction(), signing_claim_token: 'b'.repeat(64) } };
        }
        if (step === 'rejected') {
            assert.equal(payload.rejection_code, 4001);
        }
        return {};
    }, { attempt_id: 9, wallet_address: 'wallet-a' }, createSolanaOpportunityRecovery('7', 'wallet-a', storage())), /rejected/);

    assert.deepEqual(calls, ['confirm', 'arm', 'rejected']);
});

test('a failed report retains only the same signature for explicit recovery', async () => {
    const store = storage();
    const recovery = createSolanaOpportunityRecovery('7', 'wallet-a', store);
    let reportCalls = 0;
    await assert.rejects(sendSolanaOpportunity({
        publicKey: { toString: () => 'wallet-a' },
        signAndSendTransaction: async () => ({ signature }),
    }, async (step) => {
        if (step === 'confirm') {
            return { order: { attempt_id: 9, transaction: preparedTransaction(), signing_claim_token: 'c'.repeat(64) } };
        }
        if (step === 'submitted') {
            reportCalls++;
            throw new Error('offline');
        }
        return {};
    }, { attempt_id: 9, wallet_address: 'wallet-a' }, recovery), /offline/);

    assert.equal(recovery.pending().transaction_signature, signature);
    await recovery.recover(async (step, payload) => {
        assert.equal(step, 'submitted');
        assert.equal(payload.transaction_signature, signature);
        reportCalls++;
        return { swap: { status: 'submitted', transaction_signature: signature } };
    });
    assert.equal(reportCalls, 2);
    assert.equal(recovery.pending(), null);
});

test('recovery refuses to replace a different saved attempt or signature', () => {
    const recovery = createSolanaOpportunityRecovery('7', 'wallet-a', storage());
    recovery.remember({
        attempt_id: 9,
        transaction_signature: signature,
        wallet_address: 'wallet-a',
    });

    assert.throws(() => recovery.remember({
        attempt_id: 10,
        transaction_signature: '4'.repeat(88),
        wallet_address: 'wallet-a',
    }), /different saved Solana transaction/);
    assert.equal(recovery.pending().attempt_id, 9);
    assert.equal(recovery.pending().transaction_signature, signature);
});

test('the active wallet must match the immutable reserved wallet', async () => {
    let called = false;
    await assert.rejects(sendSolanaOpportunity({
        publicKey: { toString: () => 'wallet-b' },
        signAndSendTransaction: async () => {
            called = true;
            return { signature };
        },
    }, async () => {
        called = true;
        return {};
    }, { attempt_id: 9, wallet_address: 'wallet-a' }, createSolanaOpportunityRecovery('7', 'wallet-a', storage())), /does not match/);

    assert.equal(called, false);
});
