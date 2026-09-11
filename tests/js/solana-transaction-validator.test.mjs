import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import { getAddressFromPublicKey } from '@solana/addresses';
import { generateKeyPair } from '@solana/keys';
import { getCompiledTransactionMessageEncoder } from '@solana/transaction-messages';
import { getTransactionEncoder, partiallySignTransaction } from '@solana/transactions';
import { Wallet } from 'ethers';
import {
    compareSigned,
    inspectPrepared,
    validateRequest,
    verifyEthereumSignature,
} from '../../scripts/solana-transaction-validator.mjs';

test('Ethereum personal signature recovers the expected wallet', async () => {
    const wallet = Wallet.createRandom();
    const message = 'MemeScanner Ethereum ownership challenge';
    const signature = await wallet.signMessage(message);

    assert.deepEqual(verifyEthereumSignature({
        message,
        signature,
        expected_wallet: wallet.address,
    }), {
        valid: true,
        recovered_address: wallet.address.toLowerCase(),
    });
});

test('Ethereum signature for another wallet is rejected', async () => {
    const wallet = Wallet.createRandom();
    const stranger = Wallet.createRandom();
    const result = await validateRequest({
        operation: 'verify_ethereum_signature',
        message: 'Exact ownership message',
        signature: await stranger.signMessage('Exact ownership message'),
        expected_wallet: wallet.address,
    });

    assert.deepEqual(result, { valid: false, error_code: 'ethereum_signature_mismatch' });
});

async function generatedIdentity() {
    const keyPair = await generateKeyPair();
    return { keyPair, address: await getAddressFromPublicKey(keyPair.publicKey) };
}

async function transactionFixture({ lookupTable = false, additionalSigner = false, version = 0 } = {}) {
    const wallet = await generatedIdentity();
    const marketMaker = additionalSigner ? await generatedIdentity() : null;
    const program = await generatedIdentity();
    const blockhash = await generatedIdentity();
    const lookup = lookupTable ? await generatedIdentity() : null;
    const staticAccounts = [wallet.address];
    if (marketMaker) staticAccounts.push(marketMaker.address);
    staticAccounts.push(program.address);

    const compiledMessage = {
        version,
        header: {
            numSignerAccounts: marketMaker ? 2 : 1,
            numReadonlySignerAccounts: marketMaker ? 1 : 0,
            numReadonlyNonSignerAccounts: 1,
        },
        staticAccounts,
        lifetimeToken: blockhash.address,
        instructions: [{ programAddressIndex: staticAccounts.length - 1, accountIndices: [0], data: new Uint8Array([1]) }],
        ...(lookup ? {
            addressTableLookups: [{ lookupTableAddress: lookup.address, writableIndexes: [], readonlyIndexes: [0] }],
        } : {}),
    };
    const messageBytes = getCompiledTransactionMessageEncoder().encode(compiledMessage);
    let prepared = {
        messageBytes,
        signatures: Object.fromEntries([
            [wallet.address, null],
            ...(marketMaker ? [[marketMaker.address, null]] : []),
        ]),
    };
    if (marketMaker) prepared = await partiallySignTransaction([marketMaker.keyPair], prepared);

    return { wallet, marketMaker, prepared, lookup };
}

function base64(transaction) {
    return Buffer.from(getTransactionEncoder().encode(transaction)).toString('base64');
}

test('inspect_prepared returns safe v0 signer and fee-payer metadata', async () => {
    const { wallet, prepared } = await transactionFixture();

    const result = inspectPrepared({ transaction: base64(prepared), expected_wallet: wallet.address });

    assert.equal(result.valid, true);
    assert.equal(result.transaction_version, 0);
    assert.equal(result.fee_payer, wallet.address);
    assert.deepEqual(result.required_signer_addresses, [wallet.address]);
    assert.equal(result.expected_wallet_is_required_signer, true);
    assert.equal(result.expected_wallet_is_fee_payer, true);
    assert.equal(result.expected_wallet_signature_present, false);
    assert.match(result.message_hash, /^[a-f0-9]{64}$/);
});

test('compare_signed accepts a valid expected-wallet signature over an unchanged message', async () => {
    const { wallet, prepared } = await transactionFixture();
    const preparedBytes = base64(prepared);
    const signed = await partiallySignTransaction([wallet.keyPair], prepared);

    const result = await compareSigned({
        prepared_transaction: preparedBytes,
        signed_transaction: base64(signed),
        expected_wallet: wallet.address,
    });

    assert.equal(result.valid, true);
    assert.equal(result.expected_wallet_signature_present, true);
});

test('compare_signed rejects an altered transaction message', async () => {
    const original = await transactionFixture();
    const altered = await transactionFixture();
    const signedAltered = await partiallySignTransaction([altered.wallet.keyPair], altered.prepared);

    const result = await validateRequest({
        operation: 'compare_signed',
        prepared_transaction: base64(original.prepared),
        signed_transaction: base64(signedAltered),
        expected_wallet: original.wallet.address,
    });

    assert.deepEqual(result, { valid: false, error_code: 'transaction_message_changed' });
});

test('different expected wallet and missing signatures are rejected', async () => {
    const { wallet, prepared } = await transactionFixture();
    const stranger = await generatedIdentity();

    assert.deepEqual(await validateRequest({
        operation: 'inspect_prepared', transaction: base64(prepared), expected_wallet: stranger.address,
    }), { valid: false, error_code: 'expected_wallet_not_required_signer' });
    assert.deepEqual(await validateRequest({
        operation: 'compare_signed',
        prepared_transaction: base64(prepared),
        signed_transaction: base64(prepared),
        expected_wallet: wallet.address,
    }), { valid: false, error_code: 'expected_wallet_signature_missing' });
});

test('invalid expected-wallet signature is rejected cryptographically', async () => {
    const { wallet, prepared } = await transactionFixture();
    const signed = { ...prepared, signatures: { ...prepared.signatures, [wallet.address]: new Uint8Array(64).fill(7) } };

    assert.deepEqual(await validateRequest({
        operation: 'compare_signed',
        prepared_transaction: base64(prepared),
        signed_transaction: base64(signed),
        expected_wallet: wallet.address,
    }), { valid: false, error_code: 'expected_wallet_signature_invalid' });
});

test('corrupt base64, malformed data, and unsupported versions fail deterministically', async () => {
    assert.deepEqual(await validateRequest({ operation: 'inspect_prepared', transaction: '!!!', expected_wallet: 'x' }), {
        valid: false, error_code: 'invalid_base64',
    });
    assert.deepEqual(await validateRequest({
        operation: 'inspect_prepared', transaction: Buffer.from('malformed').toString('base64'), expected_wallet: 'x',
    }), { valid: false, error_code: 'malformed_transaction' });

    const legacy = await transactionFixture({ version: 'legacy' });
    assert.deepEqual(await validateRequest({
        operation: 'inspect_prepared', transaction: base64(legacy.prepared), expected_wallet: legacy.wallet.address,
    }), { valid: false, error_code: 'unsupported_transaction_version' });
});

test('existing additional signatures are preserved while the customer signs', async () => {
    const { wallet, marketMaker, prepared } = await transactionFixture({ additionalSigner: true });
    const preparedBytes = base64(prepared);
    const existingSignature = Buffer.from(prepared.signatures[marketMaker.address]);
    const signed = await partiallySignTransaction([wallet.keyPair], prepared);

    const result = await compareSigned({
        prepared_transaction: preparedBytes,
        signed_transaction: base64(signed),
        expected_wallet: wallet.address,
    });

    assert.equal(result.valid, true);
    assert.deepEqual(Buffer.from(signed.signatures[marketMaker.address]), existingSignature);
});

test('mutation of an existing non-customer signature is rejected', async () => {
    const { wallet, marketMaker, prepared } = await transactionFixture({ additionalSigner: true });
    const signed = await partiallySignTransaction([wallet.keyPair], prepared);
    const mutated = {
        ...signed,
        signatures: { ...signed.signatures, [marketMaker.address]: new Uint8Array(64).fill(9) },
    };

    assert.deepEqual(await validateRequest({
        operation: 'compare_signed',
        prepared_transaction: base64(prepared),
        signed_transaction: base64(mutated),
        expected_wallet: wallet.address,
    }), { valid: false, error_code: 'existing_signature_changed' });
});

test('v0 address lookup tables are reported and never treated as signers', async () => {
    const { wallet, prepared, lookup } = await transactionFixture({ lookupTable: true });

    const result = inspectPrepared({ transaction: base64(prepared), expected_wallet: wallet.address });

    assert.deepEqual(result.required_signer_addresses, [wallet.address]);
    assert.deepEqual(result.address_lookup_table_references, [lookup.address]);
});

test('CLI uses stdin JSON and never echoes transaction bytes in errors', () => {
    const marker = Buffer.from('never-print-this-transaction').toString('base64');
    const child = spawnSync(process.execPath, ['scripts/solana-transaction-validator.mjs'], {
        cwd: process.cwd(),
        input: JSON.stringify({ operation: 'inspect_prepared', transaction: marker, expected_wallet: 'invalid' }),
        encoding: 'utf8',
    });

    assert.equal(child.status, 0);
    assert.deepEqual(JSON.parse(child.stdout), { valid: false, error_code: 'malformed_transaction' });
    assert.equal(child.stdout.includes(marker), false);
    assert.equal(child.stderr.includes(marker), false);
});
