import { address, getPublicKeyFromAddress } from '@solana/addresses';
import { assertIsSignatureBytes, verifySignature } from '@solana/keys';
import { getCompiledTransactionMessageDecoder } from '@solana/transaction-messages';
import { getTransactionDecoder } from '@solana/transactions';
import { createHash } from 'node:crypto';
import { getAddress, verifyMessage } from 'ethers';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const MAX_TRANSACTION_BYTES = 1232;
const MAX_INPUT_BYTES = 12 * 1024;

class ValidationFailure extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

function decodeBase64(value) {
    if (typeof value !== 'string' || value.length === 0 || value.length > 4096
        || value.length % 4 !== 0 || !/^[A-Za-z0-9+/]+={0,2}$/.test(value)) {
        throw new ValidationFailure('invalid_base64');
    }

    const bytes = Buffer.from(value, 'base64');

    if (bytes.length === 0 || bytes.length > MAX_TRANSACTION_BYTES || bytes.toString('base64') !== value) {
        throw new ValidationFailure('invalid_base64');
    }

    return bytes;
}

function parsePublicKey(value) {
    try {
        return address(value);
    } catch {
        throw new ValidationFailure('invalid_expected_wallet');
    }
}

function deserialize(value) {
    const bytes = decodeBase64(value);

    try {
        const transaction = getTransactionDecoder().decode(bytes);
        const message = getCompiledTransactionMessageDecoder().decode(transaction.messageBytes);
        if (message.version !== 0) {
            throw new ValidationFailure('unsupported_transaction_version');
        }
        if (Object.keys(transaction.signatures).length !== message.header.numSignerAccounts) {
            throw new ValidationFailure('invalid_signature_count');
        }
        return { transaction, message };
    } catch (error) {
        if (error instanceof ValidationFailure) throw error;
        throw new ValidationFailure('malformed_transaction');
    }
}

function messageMetadata(decoded, expectedWallet) {
    const messageBytes = Buffer.from(decoded.transaction.messageBytes);
    const requiredSigners = Object.keys(decoded.transaction.signatures);

    return {
        messageBytes,
        messageHash: createHash('sha256').update(messageBytes).digest('hex'),
        requiredSigners,
        expectedSignerIndex: requiredSigners.indexOf(expectedWallet),
        feePayer: decoded.message.staticAccounts[0] ?? null,
        lookupTables: (decoded.message.addressTableLookups ?? []).map((lookup) => lookup.lookupTableAddress),
        recentBlockhash: decoded.message.lifetimeToken,
    };
}

function isSignaturePresent(signature) {
    return signature instanceof Uint8Array && signature.length === 64;
}

function safeMetadata(decoded, metadata, expectedWallet) {
    const expectedSignature = decoded.transaction.signatures[expectedWallet] ?? null;

    return {
        valid: true,
        transaction_version: 0,
        message_hash: metadata.messageHash,
        fee_payer: metadata.feePayer,
        required_signer_addresses: metadata.requiredSigners,
        expected_wallet_is_required_signer: metadata.expectedSignerIndex >= 0,
        expected_wallet_is_fee_payer: metadata.feePayer === expectedWallet,
        signature_count: Object.keys(decoded.transaction.signatures).length,
        expected_wallet_signature_present: expectedSignature !== null && isSignaturePresent(expectedSignature),
        recent_blockhash: metadata.recentBlockhash,
        address_lookup_table_references: metadata.lookupTables,
    };
}

export function inspectPrepared(input) {
    const transaction = deserialize(input?.transaction);
    const expectedWallet = parsePublicKey(input?.expected_wallet);
    const metadata = messageMetadata(transaction, expectedWallet);

    if (metadata.expectedSignerIndex < 0) throw new ValidationFailure('expected_wallet_not_required_signer');
    return safeMetadata(transaction, metadata, expectedWallet);
}

export async function compareSigned(input) {
    const prepared = deserialize(input?.prepared_transaction);
    const signed = deserialize(input?.signed_transaction);
    const expectedWallet = parsePublicKey(input?.expected_wallet);
    const preparedMetadata = messageMetadata(prepared, expectedWallet);
    const signedMetadata = messageMetadata(signed, expectedWallet);

    if (!preparedMetadata.messageBytes.equals(signedMetadata.messageBytes)
        || preparedMetadata.messageHash !== signedMetadata.messageHash) {
        throw new ValidationFailure('transaction_message_changed');
    }
    if (preparedMetadata.expectedSignerIndex < 0) throw new ValidationFailure('expected_wallet_not_required_signer');

    const signature = signed.transaction.signatures[expectedWallet];
    if (!isSignaturePresent(signature)) throw new ValidationFailure('expected_wallet_signature_missing');

    assertIsSignatureBytes(signature);
    const publicKey = await getPublicKeyFromAddress(expectedWallet);
    if (!await verifySignature(publicKey, signature, signed.transaction.messageBytes)) {
        throw new ValidationFailure('expected_wallet_signature_invalid');
    }

    for (const [signerAddress, preparedSignature] of Object.entries(prepared.transaction.signatures)) {
        const signedSignature = signed.transaction.signatures[signerAddress];
        if (signerAddress !== expectedWallet) {
            const preparedPresent = isSignaturePresent(preparedSignature);
            const signedPresent = isSignaturePresent(signedSignature);

            if (preparedPresent !== signedPresent
                || (preparedPresent && !Buffer.from(preparedSignature).equals(Buffer.from(signedSignature)))) {
                throw new ValidationFailure('existing_signature_changed');
            }
        }
    }

    return safeMetadata(signed, signedMetadata, expectedWallet);
}

export function verifyEthereumSignature(input) {
    if (typeof input?.message !== 'string' || input.message.length === 0 || input.message.length > 4096) {
        throw new ValidationFailure('invalid_ethereum_message');
    }
    if (typeof input?.signature !== 'string' || !/^0x[a-fA-F0-9]{130}$/.test(input.signature)) {
        throw new ValidationFailure('invalid_ethereum_signature');
    }

    let expectedWallet;
    let recoveredWallet;
    try {
        expectedWallet = getAddress(input.expected_wallet);
        recoveredWallet = verifyMessage(input.message, input.signature);
    } catch {
        throw new ValidationFailure('invalid_ethereum_signature');
    }

    if (recoveredWallet !== expectedWallet) {
        throw new ValidationFailure('ethereum_signature_mismatch');
    }

    return {
        valid: true,
        recovered_address: recoveredWallet.toLowerCase(),
    };
}

export async function validateRequest(request) {
    try {
        if (request?.operation === 'inspect_prepared') return inspectPrepared(request);
        if (request?.operation === 'compare_signed') return await compareSigned(request);
        if (request?.operation === 'verify_ethereum_signature') return verifyEthereumSignature(request);
        throw new ValidationFailure('unsupported_operation');
    } catch (error) {
        return { valid: false, error_code: error instanceof ValidationFailure ? error.code : 'validator_failure' };
    }
}

async function runCli() {
    try {
        const stdin = readFileSync(0, { encoding: 'utf8' });
        if (Buffer.byteLength(stdin) > MAX_INPUT_BYTES) throw new ValidationFailure('input_too_large');
        process.stdout.write(`${JSON.stringify(await validateRequest(JSON.parse(stdin)))}\n`);
    } catch (error) {
        process.stdout.write(`${JSON.stringify({
            valid: false,
            error_code: error instanceof ValidationFailure ? error.code : 'invalid_json',
        })}\n`);
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await runCli();
