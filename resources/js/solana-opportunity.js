import { VersionedTransaction } from '@solana/web3.js';
import { base64ToBytes, detectWallets } from './solana-wallet.js';

const signaturePattern = /^[1-9A-HJ-NP-Za-km-z]{80,90}$/;

export function createSolanaOpportunityRecovery(userId, walletAddress, storage = globalThis.localStorage) {
    const key = `meme-scanner:solana-opportunity:${userId}:${walletAddress}`;
    let memory = null;
    let reporting = null;
    const valid = (value) => value && Number.isSafeInteger(value.attempt_id) && value.attempt_id > 0
        && signaturePattern.test(value.transaction_signature ?? '')
        && value.wallet_address === walletAddress;
    const pending = () => {
        if (valid(memory)) return memory;
        try {
            const parsed = JSON.parse(storage?.getItem(key) ?? 'null');
            return valid(parsed) ? (memory = parsed) : null;
        } catch {
            return null;
        }
    };
    return {
        pending,
        remember(value) {
            if (!valid(value)) throw new Error('The returned Solana transaction signature is invalid.');
            const existing = pending();
            if (existing && (existing.attempt_id !== value.attempt_id
                || existing.transaction_signature !== value.transaction_signature)) {
                throw new Error('A different saved Solana transaction must be resolved first. Do not replace or send another transaction.');
            }
            memory = value;
            try { storage?.setItem(key, JSON.stringify(value)); } catch {}
        },
        clear() {
            memory = null;
            try { storage?.removeItem(key); } catch {}
        },
        async recover(post) {
            if (reporting) return reporting;
            const value = pending();
            if (!value) return null;
            reporting = (async () => {
                try {
                    const result = await post('submitted', value);
                    if (result.swap?.transaction_signature !== value.transaction_signature
                        || !['submitted', 'confirmed', 'failed'].includes(result.swap?.status)) {
                        throw new Error('The server did not acknowledge the same Solana transaction.');
                    }
                    this.clear();

                    return result;
                } catch (error) {
                    if ([403, 404].includes(error?.status)) {
                        this.clear();
                        throw new Error('The saved Solana transaction is unavailable for this account. Its recovery record was discarded.');
                    }
                    throw error;
                }
            })();
            try {
                return await reporting;
            } finally {
                reporting = null;
            }
        },
    };
}

export async function sendSolanaOpportunity(wallet, post, binding, recovery) {
    if (!wallet || typeof wallet.signAndSendTransaction !== 'function') {
        throw new Error('This wallet cannot explicitly sign and broadcast a versioned Solana transaction.');
    }
    if (wallet.publicKey?.toString() !== binding.wallet_address) {
        throw new Error('The active wallet account does not match the reserved Solana wallet. Connect the reserved wallet first.');
    }
    if (recovery.pending()) {
        throw new Error('Recover the previously broadcast Solana transaction before continuing.');
    }

    const claimed = await post('confirm');
    const order = claimed.order;
    if (!Number.isInteger(order?.attempt_id) || order.attempt_id !== binding.attempt_id
        || typeof order.transaction !== 'string' || !/^[a-f0-9]{64}$/.test(order.signing_claim_token ?? '')) {
        throw new Error('The server returned an invalid Solana signing handoff.');
    }

    let transaction;
    try {
        transaction = VersionedTransaction.deserialize(base64ToBytes(order.transaction));
    } catch {
        await post('release', { signing_claim_token: order.signing_claim_token }).catch(() => {});
        throw new Error('The prepared Solana transaction could not be decoded safely.');
    }

    await post('arm', { signing_claim_token: order.signing_claim_token });
    let response;
    try {
        response = await wallet.signAndSendTransaction(transaction);
    } catch (error) {
        if (error?.code === 4001) {
            await post('rejected', { signing_claim_token: order.signing_claim_token, rejection_code: 4001 });
        }
        throw error;
    }

    const signature = typeof response === 'string' ? response : response?.signature;
    const record = {
        attempt_id: order.attempt_id,
        transaction_signature: signature,
        wallet_address: binding.wallet_address,
    };
    recovery.remember(record);
    const result = await post('submitted', record);
    if (result.swap?.transaction_signature !== signature) {
        throw new Error('The server did not acknowledge the same Solana transaction.');
    }
    recovery.clear();

    return result;
}

export function mountSolanaOpportunity(section, browser = window) {
    if (!section) return;
    const feedback = section.querySelector('[data-solana-feedback]');
    const say = (message) => { if (feedback) feedback.textContent = message; };
    const binding = {
        attempt_id: Number(section.dataset.attemptId),
        wallet_address: section.dataset.walletAddress,
    };
    const recovery = binding.wallet_address
        ? createSolanaOpportunityRecovery(section.dataset.userId, binding.wallet_address)
        : null;
    const post = async (step, payload = {}) => {
        const response = await fetch(section.dataset[`${step}Url`], {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(180000),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(body.message ?? 'The Solana request could not be completed.');
            error.status = response.status;
            throw error;
        }
        return body;
    };

    let wallets = detectWallets(browser);
    const picker = section.querySelector('[data-solana-wallet]');
    wallets.forEach((entry, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = entry.provider === 'compatible' ? 'Solana wallet' : entry.provider;
        picker?.append(option);
    });

    section.querySelector('[data-solana-connect]')?.addEventListener('click', async () => {
        try {
            const selected = wallets[Number(picker?.value ?? 0)];
            if (!selected) throw new Error('No supported Solana wallet was detected.');
            await selected.wallet.connect();
            say('Wallet connected. Confirming remains a separate explicit action.');
        } catch (error) {
            say(error.message ?? 'Wallet connection was not completed.');
        }
    });

    section.querySelector('[data-solana-prepare]')?.addEventListener('click', async (event) => {
        event.currentTarget.disabled = true;
        try {
            await post('prepare');
            browser.location.reload();
        } catch (error) {
            say(error.message);
            event.currentTarget.disabled = false;
        }
    });

    section.querySelector('[data-solana-confirm]')?.addEventListener('click', async (event) => {
        event.currentTarget.disabled = true;
        try {
            const selected = wallets[Number(picker?.value ?? 0)];
            const result = await sendSolanaOpportunity(selected?.wallet, post, binding, recovery);
            say(result.swap.status === 'confirmed' ? 'Solana transaction confirmed.' : 'Transaction reported; awaiting reconciliation.');
        } catch (error) {
            say(error?.code === 4001 ? 'Wallet request rejected.' : (error.message ?? 'Wallet outcome unresolved. Do not send again.'));
        }
    });

    section.querySelector('[data-solana-recover]')?.addEventListener('click', async () => {
        try {
            await recovery?.recover(post);
            say('The same Solana transaction was reported successfully.');
        } catch (error) {
            say(error.message);
        }
    });

    section.querySelector('[data-solana-report-known]')?.addEventListener('click', async () => {
        const signature = section.querySelector('[data-solana-known-signature]')?.value?.trim();
        try {
            recovery.remember({
                attempt_id: binding.attempt_id,
                transaction_signature: signature,
                wallet_address: binding.wallet_address,
            });
            await recovery.recover(post);
            say('The known Solana transaction was verified and reported.');
        } catch (error) {
            say(error.message);
        }
    });
}
