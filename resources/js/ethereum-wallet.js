import { hexlify, parseEther, toQuantity, toUtf8Bytes } from 'ethers';

function providerName(provider, info = {}) {
    if (provider?.isPhantom || /phantom/i.test(info.rdns ?? info.name ?? '')) return 'phantom';
    if (provider?.isMetaMask || /metamask/i.test(info.rdns ?? info.name ?? '')) return 'metamask';
    return 'compatible';
}

export function detectEthereumWallets(browser, announced = []) {
    const candidates = [
        { provider: browser.phantom?.ethereum, info: { name: 'Phantom', rdns: 'app.phantom' } },
        ...announced.map((wallet) => ({ provider: wallet.provider, info: wallet.info ?? {} })),
        { provider: browser.ethereum, info: {} },
    ].filter((entry) => entry.provider && typeof entry.provider.request === 'function');
    const seen = new Set();

    return candidates.filter(({ provider }) => {
        if (seen.has(provider)) return false;
        seen.add(provider);
        return true;
    }).map(({ provider, info }) => ({
        wallet: provider,
        provider: providerName(provider, info),
        label: info.name || (provider.isPhantom ? 'Phantom' : provider.isMetaMask ? 'MetaMask' : 'Compatible Ethereum wallet'),
    }));
}

export async function verifyEthereumWallet(selected, post, progress = () => {}) {
    const wallet = selected.wallet;
    progress('Approve the Ethereum wallet connection…');
    const accounts = await wallet.request({ method: 'eth_requestAccounts' });
    const address = accounts?.[0]?.toLowerCase();
    if (!/^0x[a-f0-9]{40}$/.test(address ?? '')) throw new Error('The wallet did not provide a valid Ethereum address.');

    const chainId = await wallet.request({ method: 'eth_chainId' });
    if (String(chainId).toLowerCase() !== '0x1') {
        throw new Error('Switch this wallet to Ethereum Mainnet before connecting.');
    }

    let changed = false;
    const accountsChanged = () => { changed = true; };
    const chainChanged = () => { changed = true; };
    wallet.on?.('accountsChanged', accountsChanged);
    wallet.on?.('chainChanged', chainChanged);
    const ensureUnchanged = () => {
        if (changed) throw new Error('The wallet account or network changed during verification. Please reconnect.');
    };

    try {
        progress('Requesting a one-time ownership message…');
        const challenge = await post('challenge', { address, provider: selected.provider });
        ensureUnchanged();
        if (!challenge.challenge_id || typeof challenge.message !== 'string'
            || !Number.isFinite(Date.parse(challenge.expires_at)) || Date.parse(challenge.expires_at) <= Date.now()) {
            throw new Error('The Ethereum verification challenge is invalid or expired.');
        }

        progress('Sign the ownership message in your wallet…');
        const signature = await wallet.request({
            method: 'personal_sign',
            params: [hexlify(toUtf8Bytes(challenge.message)), address],
        });
        ensureUnchanged();
        if (typeof signature !== 'string' || !/^0x[a-fA-F0-9]{130}$/.test(signature)) {
            throw new Error('The wallet returned an invalid Ethereum signature.');
        }

        progress('Verifying Ethereum wallet ownership…');
        const result = await post('verify', { challenge_id: challenge.challenge_id, signature });
        ensureUnchanged();
        if (result.verified !== true || result.wallet?.chain !== 'ethereum'
            || result.wallet?.address?.toLowerCase() !== address) {
            throw new Error('Ethereum wallet verification was not confirmed.');
        }

        return result.wallet;
    } finally {
        wallet.removeListener?.('accountsChanged', accountsChanged);
        wallet.removeListener?.('chainChanged', chainChanged);
    }
}

export async function fetchEthereumBalance(get) {
    const result = await get('balance');
    if (result.balance?.chain !== 'ethereum'
        || typeof result.balance?.wei !== 'string' || !/^\d+$/.test(result.balance.wei)
        || typeof result.balance?.eth !== 'string' || !/^\d+\.\d+$/.test(result.balance.eth)) {
        throw new Error('The server returned an invalid Ethereum balance.');
    }

    return result.balance;
}

export function ethToWei(value) {
    if (!/^\d+(?:\.\d{1,18})?$/.test(String(value).trim())) throw new Error('Enter a valid ETH amount with no more than 18 decimal places.');
    const wei = parseEther(String(value).trim());
    if (wei <= 0n) throw new Error('Spend amount must be greater than zero.');
    return wei.toString();
}

export function createEthereumSwapRecovery(userId, walletAddress, storage) {
    const user = String(userId);
    const address = walletAddress.toLowerCase();
    const key = `ethereum-swap-report:v2:${user}:${address}`;
    let pending = null;
    let reporting = null;
    if (storage === undefined) {
        try { storage = globalThis.sessionStorage; } catch { storage = null; }
    }
    const clear = () => {
        pending = null;
        try { storage?.removeItem(key); } catch { /* An acknowledged record is harmless on the next reload. */ }
    };
    const valid = (record) => record?.user_id === user
        && record.wallet_address === address
        && Number.isSafeInteger(record.attempt_id) && record.attempt_id > 0
        && typeof record.transaction_hash === 'string'
        && /^0x[a-fA-F0-9]{64}$/.test(record.transaction_hash);
    try {
        const raw = storage?.getItem(key);
        if (raw) {
            const record = JSON.parse(raw);
            if (valid(record)) pending = record;
            else clear();
        }
    } catch { clear(); }

    return {
        sending: false,
        uncertain: false,
        pending: () => pending,
        remember(attemptId, hash) {
            const record = { user_id: user, wallet_address: address, attempt_id: attemptId, transaction_hash: hash };
            if (!valid(record)) throw new Error('Invalid Ethereum broadcast recovery record.');
            pending = record;
            try { storage?.setItem(key, JSON.stringify(record)); } catch { /* Reporting must continue even without storage. */ }
        },
        async recover(post) {
            if (reporting) return reporting;
            if (!pending) return null;
            const record = pending;
            reporting = (async () => {
                try {
                    const result = await post('submitted', { attempt_id: record.attempt_id, transaction_hash: record.transaction_hash });
                    if (result.swap?.transaction_hash?.toLowerCase() !== record.transaction_hash.toLowerCase()
                        || !['submitted', 'confirmed', 'failed'].includes(result.swap?.status)) {
                        throw new Error('The server did not acknowledge this transaction.');
                    }
                    clear();
                    return result;
                } catch (error) {
                    if ([403, 404].includes(error?.status)) {
                        clear();
                        throw new Error('The saved transaction is unavailable for this account. Its recovery record was discarded.');
                    }
                    const unresolved = new Error(`Transaction ${record.transaction_hash} was broadcast but reporting is unresolved. Use Retry transaction report; do not send another swap. ${error?.message ?? ''}`);
                    unresolved.transactionHash = record.transaction_hash;
                    throw unresolved;
                }
            })();
            try { return await reporting; } finally { reporting = null; }
        },
    };
}

export async function sendEthereumSwap(wallet, post, payload, recovery) {
    if (recovery.pending()) throw new Error('Recover the previously broadcast transaction before starting a new swap.');
    if (recovery.sending || recovery.uncertain) throw new Error('Wallet submission outcome is pending or unknown. Check wallet transaction history before attempting another swap.');
    recovery.sending = true;
    try {
        if (!wallet || typeof wallet.request !== 'function') throw new Error('Unlock an Ethereum wallet before confirming.');
        const accounts = await wallet.request({ method: 'eth_accounts' });
        if (accounts?.[0]?.toLowerCase() !== payload.wallet_address) {
            throw new Error('The active wallet account does not match the verified Ethereum wallet. Reconnect before confirming.');
        }
        if (String(await wallet.request({ method: 'eth_chainId' })).toLowerCase() !== '0x1') throw new Error('Switch to Ethereum Mainnet before confirming.');

        const order = await post('order', payload);
        const transaction = order.order?.transaction;
        if (!Number.isInteger(order.order?.attempt_id) || !transaction
            || transaction.chainId !== '1' || transaction.from !== payload.wallet_address
            || !/^0x[a-f0-9]{40}$/.test(transaction.to)
            || !/^0x[0-9a-fA-F]*$/.test(transaction.data)
            || transaction.value !== payload.sell_amount_wei
            || !/^\d+$/.test(transaction.gas) || !/^\d+$/.test(transaction.gasPrice)) {
            throw new Error('The server returned an invalid Ethereum transaction.');
        }
        return await broadcastEthereumOrder(wallet, post, order.order, recovery);

    } finally {
        recovery.sending = false;
    }
}

async function broadcastEthereumOrder(wallet, post, order, recovery, strictRejection = false) {
    const transaction = order.transaction;
    let hash;
    recovery.uncertain = true;

    try {
        hash = await wallet.request({ method: 'eth_sendTransaction', params: [{
            from: transaction.from,
            to: transaction.to,
            data: transaction.data,
            value: toQuantity(BigInt(transaction.value)),
            gas: toQuantity(BigInt(transaction.gas)),
            gasPrice: toQuantity(BigInt(transaction.gasPrice)),
            ...(strictRejection ? { chainId: toQuantity(BigInt(transaction.chainId)) } : {}),
        }], });
    } catch (error) {
        const cancelled = error?.code === 4001 || (!strictRejection && /reject|denied|cancel/i.test(error?.message ?? ''));

        if (cancelled) {
            if (!strictRejection) recovery.uncertain = false;
            try {
                const rejection = await post(strictRejection ? 'rejected' : 'cancelled', strictRejection
                    ? { signing_claim_token: order.signing_claim_token, rejection_code: 4001 } : { attempt_id: order.attempt_id });
                if (strictRejection && ['cancelled', 'expired'].includes(rejection.status)) recovery.uncertain = false;
            } catch {
                // Preserve the wallet rejection as the primary user-facing result.
            }
        }

        if (strictRejection) {
            throw Object.assign(new Error(cancelled ? 'Wallet request rejected.' : 'Wallet outcome is unknown. Check wallet history; do not send again.'), { code: error?.code });
        }
        throw error;
    }

    if (typeof hash !== 'string' || !/^0x[a-fA-F0-9]{64}$/.test(hash)) throw new Error('The wallet returned an invalid transaction hash.');

    recovery.remember(order.attempt_id, hash);
    recovery.uncertain = false;
    return await recovery.recover(post);
}

export async function sendEthereumOpportunity(wallet, post, binding, recovery) {
    if (recovery.pending()) throw new Error('Recover the previously broadcast transaction before confirming.');
    if (recovery.sending || recovery.uncertain) throw new Error('Wallet submission is pending or unknown. Check wallet history; do not send again.');
    recovery.sending = true;
    let claim = null;
    let armRequested = false;
    const budgetAvailable = (budget, started) => Number.isInteger(budget) && budget > 0 && budget <= 10000 && performance.now() - started < budget;
    try {
        const checkWallet = async () => {
            if (!wallet || typeof wallet.request !== 'function') throw new Error('Select and unlock your verified Ethereum wallet.');
            let accounts, chain;
            try {
                accounts = await wallet.request({ method: 'eth_accounts' });
                chain = await wallet.request({ method: 'eth_chainId' });
            } catch {
                throw new Error('Wallet account and network could not be checked. Nothing was sent.');
            }
            if (accounts?.[0]?.toLowerCase() !== binding.wallet_address) throw new Error('The active wallet account does not match the reserved Ethereum wallet.');
            if (String(chain).toLowerCase() !== '0x1') throw new Error('Switch to Ethereum Mainnet before confirming.');
        };
        await checkWallet();
        // A failed response may still have claimed the handoff on the server.
        recovery.uncertain = true;
        const handoffStarted = performance.now();
        const result = await post('confirm', {});
        const order = result.order;
        if (/^[a-f0-9]{64}$/.test(order?.signing_claim_token ?? '')) claim = order.signing_claim_token;
        const transaction = order?.transaction;
        if (!claim || order?.attempt_id !== binding.attempt_id || !transaction || transaction.chainId !== '1'
            || transaction.from !== binding.wallet_address || transaction.value !== binding.sell_amount_wei
            || !/^0x[a-f0-9]{40}$/.test(transaction.to) || !/^0x(?:[0-9a-fA-F]{2})*$/.test(transaction.data)
            || !/^[1-9][0-9]*$/.test(transaction.gas) || !/^[1-9][0-9]*$/.test(transaction.gasPrice)) {
            throw new Error('The server returned an invalid Ethereum transaction.');
        }
        await checkWallet();
        if (!budgetAvailable(order.valid_for_ms, handoffStarted)) {
            throw new Error('The prepared transaction expired. Nothing was sent. Refresh the opportunity.');
        }
        const armStarted = performance.now();
        armRequested = true;
        const armed = await post('arm', { signing_claim_token: claim });
        await checkWallet();
        if (armed.armed !== true || !budgetAvailable(armed.valid_for_ms, armStarted)) {
            throw new Error('Signing authorization is unresolved or expired. Nothing was sent by this page; do not retry sending.');
        }
        return await broadcastEthereumOrder(wallet, post, order, recovery, true);
    } catch (error) {
        if (claim && !armRequested) {
            try {
                const released = await post('release', { signing_claim_token: claim });
                if (released.armed === false && ['prepared', 'expired'].includes(released.status)) {
                    recovery.uncertain = false;
                    error.attemptUnavailable = released.status !== 'prepared';
                }
            } catch { /* A lost release response must not authorize another send. */ }
        }
        throw error;
    } finally {
        recovery.sending = false;
        // Once handed off, only a known rejection or acknowledged hash clears uncertainty.
    }
}

export function mountEthereumWalletCard(card) {
    if (!card) return;
    const feedback = card.querySelector('[data-eth-feedback]');
    const say = (message) => { feedback.textContent = message; };
    const post = async (step, payload) => {
        const response = await fetch(card.dataset[`${step}Url`], {
            method: 'POST', credentials: 'same-origin', redirect: 'error',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify(payload), signal: AbortSignal.timeout(30000),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Ethereum wallet request failed.');
            error.status = response.status;
            throw error;
        }
        return data;
    };
    const get = async (step) => {
        const response = await fetch(card.dataset[`${step}Url`], { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(30000) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Ethereum balance is unavailable.');
        return data;
    };
    const loadBalance = async () => {
        const balance = card.querySelector('[data-eth-balance]');
        try {
            const result = await fetchEthereumBalance(get);
            balance.textContent = `${result.eth} ETH`;
            card.querySelector('[data-eth-balance-usd]').textContent = result.usd ? `≈ $${result.usd} USD` : 'USD value unavailable';
        } catch {
            balance.textContent = 'Balance unavailable';
            card.querySelector('[data-eth-balance-usd]').textContent = '';
        }
    };
    const loadHistory = async () => {
        const section = card.querySelector('[data-eth-history]');
        const loading = card.querySelector('[data-eth-history-loading]');
        const empty = card.querySelector('[data-eth-history-empty]');
        const list = card.querySelector('[data-eth-history-list]');
        const error = card.querySelector('[data-eth-history-error]');

        if (!section || !list) return;

        loading.hidden = false;
        empty.hidden = true;
        error.hidden = true;
        list.replaceChildren();

        try {
            const result = await get('history');
            const transactions = Array.isArray(result.transactions) ? result.transactions : [];

            loading.hidden = true;

            if (!transactions.length) {
                empty.hidden = false;

                return;
            }

            transactions.forEach((transaction) => {
                const item = document.createElement('div');
                item.className = 'rounded-xl border border-slate-800 bg-slate-950/60 p-4';

                const header = document.createElement('div');
                header.className = 'flex flex-wrap items-center justify-between gap-3';

                const amount = document.createElement('p');
                amount.className = 'font-semibold text-white';
                amount.textContent = `${transaction.sell_amount_eth} ETH`;

                const status = document.createElement('span');
                status.className = 'rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold uppercase tracking-wider text-slate-300';
                status.textContent = transaction.status;

                header.append(amount, status);
                item.append(header);

                const token = document.createElement('p');
                token.className = 'mt-2 break-all font-mono text-xs text-slate-400';
                token.textContent = `Token: ${transaction.buy_token}`;
                item.append(token);

                if (transaction.actual_network_fee_eth) {
                    const fee = document.createElement('p');
                    fee.className = 'mt-2 text-sm text-slate-400';
                    fee.textContent = `Actual network fee: ${transaction.actual_network_fee_eth} ETH`;
                    item.append(fee);
                }

                if (transaction.transaction_hash) {
                    const link = document.createElement('a');
                    link.className = 'mt-3 inline-block text-sm font-medium text-violet-300 hover:text-violet-200';
                    link.href = `https://etherscan.io/tx/${transaction.transaction_hash}`;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'View on Etherscan';
                    item.append(link);
                }

                if (transaction.failure_reason) {
                    const failure = document.createElement('p');
                    failure.className = 'mt-2 text-sm text-red-300';
                    failure.textContent = transaction.failure_reason;
                    item.append(failure);
                }

                list.append(item);
            });
        } catch (historyError) {
            loading.hidden = true;
            error.hidden = false;
            error.textContent = historyError?.message || 'Could not load Ethereum transaction history.';
        }
    };
    const announced = [];
    let activeWallet = null;
    let activeAddress = card.querySelector('[data-eth-address]')?.title?.toLowerCase() || null;
    let quotedPayload = null;
    const recoveries = new Map();
    const recoveryFor = (address) => {
        if (!recoveries.has(address)) recoveries.set(address, createEthereumSwapRecovery(card.dataset.recoveryUser, address));
        return recoveries.get(address);
    };
    let recovery = activeAddress ? recoveryFor(activeAddress) : null;
    const recoveryButton = card.querySelector('[data-eth-report-retry]');
    const recoverBroadcast = async () => {
        const current = recovery;
        if (!current?.pending()) {
            if (recoveryButton) recoveryButton.hidden = true;
            return;
        }
        if (recoveryButton) { recoveryButton.hidden = false; recoveryButton.disabled = true; }
        try {
            const result = await current.recover(post);
            if (current === recovery && result) say(`Previous Ethereum transaction ${result.swap.status}: ${result.swap.transaction_hash}`);
        } catch (error) {
            if (current === recovery) say(error.message);
        } finally {
            if (current === recovery && recoveryButton) {
                recoveryButton.hidden = !current.pending();
                recoveryButton.disabled = false;
            }
        }
    };
    recoveryButton?.addEventListener('click', recoverBroadcast);
    window.addEventListener?.('eip6963:announceProvider', (event) => announced.push(event.detail));
    window.dispatchEvent?.(new Event('eip6963:requestProvider'));

    card.querySelector('[data-eth-connect]')?.addEventListener('click', () => {
        const wallets = detectEthereumWallets(window, announced);
        const picker = card.querySelector('[data-eth-picker]');
        const options = card.querySelector('[data-eth-options]');
        options.replaceChildren();
        if (!wallets.length) return say('No Ethereum wallet was detected. Install Phantom, MetaMask, or another EIP-1193 wallet.');
        picker.hidden = false;
        wallets.forEach((selected) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-xl border border-slate-700 px-4 py-3 text-sm text-slate-100 hover:border-violet-400';
            button.textContent = selected.label;
            button.addEventListener('click', async () => {
                picker.hidden = true;
                try {
                    const verified = await verifyEthereumWallet(selected, post, say);
                    activeWallet = selected.wallet;
                    activeAddress = verified.address.toLowerCase();
                    recovery = recoveryFor(activeAddress);
                    card.querySelector('[data-eth-status]').textContent = 'Connected / Verified';
                    card.querySelector('[data-eth-details]').hidden = false;
                    card.querySelector('[data-eth-provider]').textContent = selected.label;
                    const address = card.querySelector('[data-eth-address]');
                    address.textContent = `${verified.address.slice(0, 6)}…${verified.address.slice(-4)}`;
                    address.title = verified.address;
                    card.querySelector('.copy-value').dataset.copyValue = verified.address;
                    card.querySelector('[data-eth-disconnect]').hidden = false;
                    card.querySelector('[data-eth-balance-refresh]').hidden = false;
                    say('Ethereum wallet ownership verified. No transaction was authorized.');
                    await recoverBroadcast();
                    await loadBalance();
                    await loadHistory();
                } catch (error) {
                    say(error?.code === 4001 || /reject|denied|cancel/i.test(error?.message ?? '') ? 'Ethereum wallet request cancelled.' : error?.message || 'Could not connect this Ethereum wallet.');
                }
            });
            options.append(button);
        });
    });

    card.querySelector('[data-eth-disconnect]')?.addEventListener('click', async () => {
        try {
            await post('disconnect', {});
            card.querySelector('[data-eth-status]').textContent = 'Not connected';
            card.querySelector('[data-eth-details]').hidden = true;
            card.querySelector('[data-eth-disconnect]').hidden = true;
            activeWallet = null;
            activeAddress = null;
            recovery = null;
            if (recoveryButton) recoveryButton.hidden = true;
            invalidatePrice();
            say('Ethereum wallet disconnected from this account. No funds were moved.');
        } catch (error) {
            say(error?.message || 'Could not disconnect the Ethereum wallet.');
        }
    });
    card.querySelector('[data-eth-balance-refresh]')?.addEventListener('click', loadBalance);
    card.querySelector('[data-eth-history-refresh]')?.addEventListener('click', loadHistory);
    const swapForm = card.querySelector('[data-eth-swap-form]');
    const invalidatePrice = () => {
        quotedPayload = null;
        const confirm = card.querySelector('[data-eth-swap-confirm]');
        if (confirm) confirm.hidden = true;
    };
    card.querySelector('[data-eth-spend]')?.addEventListener('input', invalidatePrice);
    card.querySelector('[data-eth-buy-token]')?.addEventListener('input', invalidatePrice);
    card.querySelector('[data-eth-slippage]')?.addEventListener('input', invalidatePrice);
    swapForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = card.querySelector('[data-eth-price-submit]');
        submit.disabled = true;
        try {
            if (!activeAddress) throw new Error('Reconnect the Ethereum wallet before requesting a price.');
            const slippage = card.querySelector('[data-eth-slippage]').value.trim();
            if (!/^\d+(?:\.\d{1,2})?$/.test(slippage)) throw new Error('Enter slippage with no more than 2 decimal places.');
            quotedPayload = {
                buy_token: card.querySelector('[data-eth-buy-token]').value.trim().toLowerCase(),
                sell_amount_wei: ethToWei(card.querySelector('[data-eth-spend]').value),
                slippage_bps: Math.round(Number(slippage) * 100),
                wallet_address: activeAddress,
            };
            const result = await post('price', quotedPayload);
            const output = result.price.output;

            card.querySelector('[data-eth-buy-amount]').textContent = output
                ? `${output.amount_formatted}${output.symbol ? ` ${output.symbol}` : ''}`
                : `${result.price.buy_amount} base units`;

            card.querySelector('[data-eth-minimum]').textContent = output
                ? `${output.minimum_amount_formatted}${output.symbol ? ` ${output.symbol}` : ''}`
                : `${result.price.minimum_buy_amount} base units`;
            const networkFee = result.price.network_fee;

            card.querySelector('[data-eth-network-fee]').textContent = networkFee
                ? `${networkFee.eth} ETH${networkFee.usd ? ` (≈ $${networkFee.usd})` : ''}`
                : 'Unavailable';
            card.querySelector('[data-eth-route]').textContent = result.price.sources.join(' → ') || 'Direct';
            card.querySelector('[data-eth-price-preview]').hidden = false;
            card.querySelector('[data-eth-swap-confirm]').hidden = false;
            say('Indicative price ready. Review it before requesting the firm wallet transaction.');
        } catch (error) {
            invalidatePrice();
            say(error?.message || 'Could not retrieve an Ethereum swap price.');
        } finally {
            submit.disabled = false;
        }
    });
    card.querySelector('[data-eth-swap-confirm]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            if (!quotedPayload) throw new Error('Request a fresh price before confirming.');
            const wallet = activeWallet ?? detectEthereumWallets(window, announced)[0]?.wallet;
            const result = await sendEthereumSwap(wallet, post, quotedPayload, recovery);
            quotedPayload = null;
            button.hidden = true;
            say(`Ethereum swap ${result.swap.status}: ${result.swap.transaction_hash}`);
            await loadBalance();
            await loadHistory();
        } catch (error) {
            if (recoveryButton) recoveryButton.hidden = !recovery?.pending();
            say(!error?.transactionHash && (error?.code === 4001 || /reject|denied|cancel/i.test(error?.message ?? '')) ? 'Ethereum swap cancelled. Nothing was submitted.' : error?.message || 'Could not submit the Ethereum swap.');
        } finally {
            button.disabled = false;
        }
    });
    if (!card.querySelector('[data-eth-details]')?.hidden) {
        recoverBroadcast();
        loadBalance();
        loadHistory();
    }
}
