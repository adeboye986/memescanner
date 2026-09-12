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

export async function sendEthereumSwap(wallet, post, payload) {
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
    let hash;

    try {
        hash = await wallet.request({ method: 'eth_sendTransaction', params: [{
            from: transaction.from,
            to: transaction.to,
            data: transaction.data,
            value: toQuantity(BigInt(transaction.value)),
            gas: toQuantity(BigInt(transaction.gas)),
            gasPrice: toQuantity(BigInt(transaction.gasPrice)),
        }], });
    } catch (error) {
        const cancelled = error?.code === 4001 || /reject|denied|cancel/i.test(error?.message ?? '');

        if (cancelled) {
            try {
                await post('cancelled', { attempt_id: order.order.attempt_id });
            } catch {
                // Preserve the wallet rejection as the primary user-facing result.
            }
        }

        throw error;
    }

    if (typeof hash !== 'string' || !/^0x[a-fA-F0-9]{64}$/.test(hash)) throw new Error('The wallet returned an invalid transaction hash.');

    return post('submitted', { attempt_id: order.order.attempt_id, transaction_hash: hash });
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
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Ethereum wallet request failed.');
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
    const announced = [];
    let activeWallet = null;
    let activeAddress = card.querySelector('[data-eth-address]')?.title?.toLowerCase() || null;
    let quotedPayload = null;
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
                    card.querySelector('[data-eth-status]').textContent = 'Connected / Verified';
                    card.querySelector('[data-eth-details]').hidden = false;
                    card.querySelector('[data-eth-provider]').textContent = selected.label;
                    const address = card.querySelector('[data-eth-address]');
                    address.textContent = `${verified.address.slice(0, 6)}…${verified.address.slice(-4)}`;
                    address.title = verified.address;
                    card.querySelector('.copy-value').dataset.copyValue = verified.address;
                    card.querySelector('[data-eth-disconnect]').hidden = false;
                    card.querySelector('[data-eth-balance-refresh]').hidden = false;
                    await loadBalance();
                    say('Ethereum wallet ownership verified. No transaction was authorized.');
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
            invalidatePrice();
            say('Ethereum wallet disconnected from this account. No funds were moved.');
        } catch (error) {
            say(error?.message || 'Could not disconnect the Ethereum wallet.');
        }
    });
    card.querySelector('[data-eth-balance-refresh]')?.addEventListener('click', loadBalance);
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
            const result = await sendEthereumSwap(wallet, post, quotedPayload);
            quotedPayload = null;
            button.hidden = true;
            say(`Ethereum swap submitted: ${result.swap.transaction_hash}`);
            await loadBalance();
        } catch (error) {
            say(error?.code === 4001 || /reject|denied|cancel/i.test(error?.message ?? '') ? 'Ethereum swap cancelled. Nothing was submitted.' : error?.message || 'Could not submit the Ethereum swap.');
        } finally {
            button.disabled = false;
        }
    });
    if (!card.querySelector('[data-eth-details]')?.hidden) loadBalance();
}
