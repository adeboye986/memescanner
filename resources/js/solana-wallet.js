export function detectWallets(browser) {
    const candidates = [browser.phantom?.solana, browser.solflare, browser.solana];
    return [...new Set(candidates.filter(Boolean))]
        .filter((wallet) => typeof wallet.connect === 'function' && typeof wallet.signMessage === 'function')
        .map((wallet) => ({ wallet, provider: wallet.isPhantom ? 'phantom' : wallet.isSolflare || wallet === browser.solflare ? 'solflare' : 'compatible' }));
}

export async function verifyWallet({ wallet, provider }, post, progress = () => {}) {
    let changed = false;
    const accountChanged = () => { changed = true; };
    progress('Approve the connection in your wallet…');
    await wallet.connect();
    const address = wallet.publicKey?.toString();
    if (!address) throw new Error('The wallet did not provide a Solana address. Please try another wallet.');
    wallet.on?.('accountChanged', accountChanged);
    wallet.on?.('disconnect', accountChanged);
    const checkAccount = () => {
        if (changed || wallet.publicKey?.toString() !== address) {
            throw new Error('Your wallet account changed during verification. Please connect again.');
        }
    };
    try {
        progress('Requesting a verification message…');
        const challenge = await post('challenge', { address, provider });
        checkAccount();
        if (!challenge.challenge_id || typeof challenge.message !== 'string' || !Number.isFinite(Date.parse(challenge.expires_at))) {
            throw new Error('The server returned an invalid challenge. Please try again.');
        }
        if (Date.parse(challenge.expires_at) <= Date.now()) throw new Error('The verification message expired. Please connect again.');
        progress('Sign the ownership message in your wallet…');
        const signed = await wallet.signMessage(new TextEncoder().encode(challenge.message), 'utf8');
        checkAccount();
        const signature = signed?.signature ?? signed;
        if (!(signature instanceof Uint8Array) || signature.length !== 64) throw new Error('This wallet returned an unsupported signature. Please try Phantom or Solflare.');
        progress('Verifying ownership…');
        const result = await post('verify', { challenge_id: challenge.challenge_id, signature: btoa(String.fromCharCode(...signature)) });
        checkAccount();
        if (result.verified !== true || result.wallet?.address !== address || result.wallet?.chain !== 'solana') {
            throw new Error('Wallet verification was not confirmed. Please reload your account before trying again.');
        }
        return result.wallet;
    } finally {
        wallet.removeListener?.('accountChanged', accountChanged);
        wallet.removeListener?.('disconnect', accountChanged);
    }
}

export async function disconnectWallet(post) {
    const result = await post('disconnect', {});

    if (result.disconnected !== true || result.wallet?.chain !== 'solana') {
        throw new Error('Wallet disconnection was not confirmed. Please reload your account before trying again.');
    }

    return result;
}

export async function fetchWalletBalance(get) {
    const result = await get('balance');
    const maximumLamports = result.quote_limits?.maximum_lamports;
    const suggestedSpendLamports = result.quote_limits?.suggested_spend_lamports;

    if (
        result.balance?.chain !== 'solana'
        || !Number.isSafeInteger(result.balance?.lamports)
        || result.balance.lamports < 0
        || typeof result.balance?.sol !== 'string'
        || !/^\d+\.\d{9}$/.test(result.balance.sol)
        || !Number.isSafeInteger(maximumLamports)
        || maximumLamports <= 0
        || !Number.isSafeInteger(suggestedSpendLamports)
        || suggestedSpendLamports < 0
        || suggestedSpendLamports > result.balance.lamports
        || suggestedSpendLamports > maximumLamports
    ) {
        throw new Error('The server returned an invalid wallet balance.');
    }

    const validUsd = typeof result.balance.usd === 'string' && /^\d+\.\d{2}$/.test(result.balance.usd);
    const validPrice = typeof result.price?.sol_usd === 'string' && /^\d+(?:\.\d+)?$/.test(result.price.sol_usd);

    return {
        ...result.balance,
        usd: validUsd && validPrice ? result.balance.usd : null,
        sol_usd: validUsd && validPrice ? result.price.sol_usd : null,
        maximum_lamports: maximumLamports,
        suggested_spend_lamports: suggestedSpendLamports,
    };
}

export function solToLamports(value) {
    const match = String(value).trim().match(/^(\d+)(?:\.(\d{1,9}))?$/);
    if (!match) throw new Error('Enter a valid SOL amount with no more than 9 decimal places.');
    const lamports = BigInt(match[1]) * 1000000000n + BigInt((match[2] ?? '').padEnd(9, '0'));
    if (lamports <= 0n) throw new Error('Spend amount must be greater than zero.');
    return lamports.toString();
}

export async function fetchSwapQuote(post, payload) {
    const result = await post('quote', payload);
    const quote = result.quote;
    const decimals = quote?.output?.decimals;
    const hasDecimals = Number.isInteger(decimals) && decimals >= 0 && decimals <= 18;
    if (quote?.input?.mint !== 'So11111111111111111111111111111111111111112'
        || quote.input.amount !== payload.amount
        || quote.output?.mint !== payload.output_mint
        || !/^[1-9]\d*$/.test(quote.output?.amount ?? '')
        || !/^[1-9]\d*$/.test(quote.minimum_received ?? '')
        || (decimals !== null && !hasDecimals)
        || (!hasDecimals && quote.output.amount_formatted !== null)
        || (!hasDecimals && quote.minimum_received_formatted !== null)
        || (hasDecimals && quote.output.amount_formatted !== formatBaseUnits(quote.output.amount, decimals))
        || (hasDecimals && quote.minimum_received_formatted !== formatBaseUnits(quote.minimum_received, decimals))
        || !Number.isInteger(quote.slippage_bps)
        || quote.slippage_bps !== payload.slippage_bps
        || typeof quote.price_impact_pct !== 'string'
        || !/^\d+(?:\.\d+)?$/.test(quote.price_impact_pct)
        || !Array.isArray(quote.route)
        || quote.route.length === 0
        || !quote.route.every((step) => typeof step?.label === 'string' && step.label.trim() !== '')) {
        throw new Error('The server returned an invalid swap quote.');
    }
    return quote;
}

export function formatBaseUnits(amount, decimals) {
    if (!Number.isInteger(decimals)) return `${amount} base units`;
    if (decimals === 0) return amount;
    const padded = amount.padStart(decimals + 1, '0');
    const whole = padded.slice(0, -decimals) || '0';
    const fraction = decimals === 0 ? '' : padded.slice(-decimals).replace(/0+$/, '');
    return fraction ? `${whole}.${fraction}` : whole;
}

export function defaultSpendFromBalance(balance) {
    if (!Number.isSafeInteger(balance?.suggested_spend_lamports) || balance.suggested_spend_lamports <= 0) {
        return '';
    }

    if (balance.suggested_spend_lamports > balance.lamports || balance.suggested_spend_lamports > balance.maximum_lamports) {
        return '';
    }

    return formatBaseUnits(String(balance.suggested_spend_lamports), 9);
}

export function validateQuoteSpend(amount, balanceLamports, maximumLamports) {
    if (!Number.isSafeInteger(balanceLamports) || !Number.isSafeInteger(maximumLamports)) {
        throw new Error('Refresh the wallet balance before requesting a quote.');
    }

    if (BigInt(amount) > BigInt(balanceLamports)) {
        throw new Error('Spend amount exceeds the connected wallet balance.');
    }

    if (BigInt(amount) > BigInt(maximumLamports)) {
        throw new Error('Spend amount exceeds the configured maximum trade amount.');
    }
}

function displayTokenAmount(baseUnits, formatted, decimals, symbol) {
    if (!Number.isInteger(decimals)) {
        return `${baseUnits} base units`;
    }

    return `${formatted} ${symbol || 'tokens'}`;
}

export function quotePresentation(quote) {
    const outputLabel = quote.output.symbol || 'tokens';

    return {
        spend: `${formatBaseUnits(quote.input.amount, 9)} SOL`,
        spendUsd: quote.spend_usd === null ? 'Unavailable' : `≈ $${quote.spend_usd} USD`,
        output: displayTokenAmount(quote.output.amount, quote.output.amount_formatted, quote.output.decimals, outputLabel),
        minimum: displayTokenAmount(quote.minimum_received, quote.minimum_received_formatted, quote.output.decimals, outputLabel),
        slippage: `${(quote.slippage_bps / 100).toFixed(2)}%`,
        impact: `${quote.price_impact_pct}%`,
        route: quote.route.map((step) => step.label).join(' → ') || 'Direct',
        fees: quote.route
            .filter((step) => step.fee_amount && step.fee_amount !== '0')
            .map((step) => `${step.fee_amount} base units`)
            .join(' + ') || 'Not supplied',
    };
}

export function mountWalletCard(card) {
    if (!card) return;
    const connect = card.querySelector('[data-wallet-connect]');
    if (!connect) return;
    const feedback = card.querySelector('[data-wallet-feedback]');
    const picker = card.querySelector('[data-wallet-picker]');
    const options = card.querySelector('[data-wallet-options]');
    const disconnect = card.querySelector('[data-wallet-disconnect]');
    const disconnectConfirmation = card.querySelector('[data-wallet-disconnect-confirmation]');
    const disconnectCancel = card.querySelector('[data-wallet-disconnect-cancel]');
    const disconnectConfirm = card.querySelector('[data-wallet-disconnect-confirm]');
    const balance = card.querySelector('[data-wallet-balance]');
    const balanceUsd = card.querySelector('[data-wallet-balance-usd]');
    const balanceRefresh = card.querySelector('[data-wallet-balance-refresh]');
    const quoteSpend = card.querySelector('[data-quote-spend]');
    const quoteSpendHelp = card.querySelector('[data-quote-spend-help]');
    const say = (message) => { feedback.textContent = message; };
    let busy = false;
    let quoteSpendEdited = false;
    let currentBalanceLamports = null;
    let currentMaximumLamports = null;
    const post = async (step, payload) => {
        const response = await fetch(card.dataset[`${step}Url`], {
            method: 'POST', credentials: 'same-origin', redirect: 'error',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify(payload), signal: AbortSignal.timeout(30000),
        });
        if ([401, 419].includes(response.status)) throw new Error('Your session expired. Reload the page and sign in again.');
        if (response.status === 429) throw new Error('Too many attempts. Please wait a minute and try again.');
        if (response.status >= 500) throw new Error('The server is temporarily unavailable. Please try again later.');
        const data = await response.json();
        if (!response.ok) throw new Error(response.status === 422 ? Object.values(data.errors ?? {}).flat()[0] ?? 'Verification failed. Please start again.' : 'Verification is unavailable. Check your account and email verification.');
        return data;
    };
    const get = async (step) => {
        const response = await fetch(card.dataset[`${step}Url`], {
            method: 'GET',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
            },
            signal: AbortSignal.timeout(30000),
        });

        if ([401, 419].includes(response.status)) {
            throw new Error('Your session expired. Reload the page and sign in again.');
        }

        if (response.status === 429) {
            throw new Error('Too many requests. Please wait a minute and try again.');
        }

        if (response.status >= 500) {
            throw new Error('Balance is temporarily unavailable.');
        }

        const data = await response.json();

        if (!response.ok) {
            throw new Error(
                response.status === 422
                    ? data.message || 'No active verified wallet was found.'
                    : 'Balance is unavailable.'
            );
        }

        return data;
    };
    const loadBalance = async () => {
        if (!balance || !balanceRefresh) return;

        balanceRefresh.disabled = true;
        balance.textContent = 'Loading…';

        try {
            const result = await fetchWalletBalance(get);
            currentBalanceLamports = result.lamports;
            currentMaximumLamports = result.maximum_lamports;
            balance.textContent = `${result.sol} SOL`;
            balanceUsd.textContent = result.usd === null ? 'USD value unavailable' : `≈ $${result.usd} USD`;
            if (!quoteSpendEdited && quoteSpend) {
                quoteSpend.value = defaultSpendFromBalance(result);
                quoteSpendHelp.textContent = quoteSpend.value === ''
                    ? 'No safe default spend is available for the current balance.'
                    : 'Suggested preview amount based on the current balance and configured risk limit.';
            }
        } catch {
            currentBalanceLamports = null;
            currentMaximumLamports = null;
            balance.textContent = 'Balance unavailable';
            balanceUsd.textContent = '';
        } finally {
            balanceRefresh.disabled = false;
        }
    };

    balanceRefresh?.addEventListener('click', loadBalance);
    quoteSpend?.addEventListener('input', () => { quoteSpendEdited = true; });
    const run = async (selected) => {
        if (busy) return;
        busy = true;
        connect.disabled = true;
        picker.hidden = true;
        card.setAttribute('aria-busy', 'true');
        try {
            const verified = await verifyWallet(selected, post, say);
            card.querySelector('[data-wallet-status]').textContent = 'Connected / Verified';
            card.querySelector('[data-wallet-empty]').hidden = true;
            card.querySelector('[data-wallet-details]').hidden = false;
            const address = card.querySelector('[data-wallet-address]');
            address.textContent = `${verified.address.slice(0, 4)}…${verified.address.slice(-4)}`;
            address.title = verified.address;
            card.querySelector('[data-wallet-provider]').textContent = { phantom: 'Phantom', solflare: 'Solflare', compatible: 'Compatible wallet' }[verified.provider] ?? 'Compatible wallet';
            card.querySelector('.copy-value').dataset.copyValue = verified.address;
            connect.textContent = 'Change wallet';
            disconnect.hidden = false;
            balanceRefresh.hidden = false;
            quoteSpendEdited = false;
            await loadBalance();
            say('Wallet ownership verified. Live trading remains disabled.');
        } catch (error) {
            const rejected = error?.code === 4001 || /reject|denied|cancel/i.test(error?.message ?? '');
            say(rejected ? 'Wallet request cancelled. Nothing was verified in this attempt. You can try again.' : error instanceof TypeError || error?.name === 'TimeoutError' ? 'Connection interrupted. Reload your account to check verification status, then try again.' : error?.message || 'Could not connect this wallet. Please try again.');
        } finally {
            busy = false;
            connect.disabled = false;
            card.setAttribute('aria-busy', 'false');
        }
    };
    if (!card.querySelector('[data-wallet-details]').hidden) {
        loadBalance();
    }
    connect.addEventListener('click', () => {
        const wallets = detectWallets(window);
        options.replaceChildren();
        if (!wallets.length) {
            say('No supported Solana wallet detected. Install and unlock Phantom or Solflare, then reload this page. Use a browser with the wallet extension enabled.');
            return;
        }
        if (wallets.length === 1) { run(wallets[0]); return; }
        picker.hidden = false;
        say('');
        wallets.forEach((selected) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-xl border border-slate-700 px-4 py-3 text-sm text-slate-100 hover:border-sky-400';
            button.textContent = { phantom: 'Phantom', solflare: 'Solflare', compatible: 'Compatible Solana wallet' }[selected.provider];
            button.addEventListener('click', () => run(selected));
            options.append(button);
        });
        options.querySelector('button')?.focus();
    });

    disconnect?.addEventListener('click', () => {
        disconnectConfirmation.hidden = false;
        disconnectConfirm?.focus();
    });
    disconnectCancel?.addEventListener('click', () => {
        disconnectConfirmation.hidden = true;
        disconnect?.focus();
    });
    disconnectConfirm?.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        connect.disabled = true;
        disconnect.disabled = true;
        disconnectConfirm.disabled = true;
        card.setAttribute('aria-busy', 'true');
        say('Disconnecting wallet…');
        try {
            await disconnectWallet(post);
            card.querySelector('[data-wallet-status]').textContent = 'Not connected';
            card.querySelector('[data-wallet-empty]').hidden = false;
            card.querySelector('[data-wallet-details]').hidden = true;
            const address = card.querySelector('[data-wallet-address]');
            address.textContent = '';
            address.title = '';
            card.querySelector('[data-wallet-provider]').textContent = '';
            card.querySelector('.copy-value').dataset.copyValue = '';
            connect.textContent = 'Connect Wallet';
            disconnect.hidden = true;
            disconnectConfirmation.hidden = true;
            if (balance) {
                balance.textContent = '—';
            }

            if (balanceUsd) {
                balanceUsd.textContent = '';
            }

            if (balanceRefresh) {
                balanceRefresh.hidden = true;
            }

            if (quoteSpend) {
                quoteSpend.value = '';
            }

            currentBalanceLamports = null;
            currentMaximumLamports = null;
            quoteSpendEdited = false;
            say('Wallet disconnected from this account. No funds were moved and your wallet extension remains connected independently.');
        } catch (error) {
            say(error instanceof TypeError || error?.name === 'TimeoutError' ? 'Connection interrupted. The verified wallet remains shown; reload before trying again.' : error?.message || 'Could not disconnect this wallet. The verified association remains unchanged.');
        } finally {
            busy = false;
            connect.disabled = false;
            disconnect.disabled = false;
            disconnectConfirm.disabled = false;
            card.setAttribute('aria-busy', 'false');
        }
    });

    const quoteForm = card.querySelector('[data-wallet-quote-form]');
    quoteForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = card.querySelector('[data-wallet-quote-submit]');
        const preview = card.querySelector('[data-wallet-quote-preview]');
        submit.disabled = true;
        preview.hidden = true;
        try {
            const amount = solToLamports(card.querySelector('[data-quote-spend]').value);
            validateQuoteSpend(amount, currentBalanceLamports, currentMaximumLamports);
            const slippageValue = card.querySelector('[data-quote-slippage]').value.trim();
            if (!/^\d+(?:\.\d{1,2})?$/.test(slippageValue)) throw new Error('Enter slippage as a percentage with no more than 2 decimal places.');
            const slippageBps = Math.round(Number(slippageValue) * 100);
            const quote = await fetchSwapQuote(post, {
                input_mint: 'So11111111111111111111111111111111111111112',
                output_mint: card.querySelector('[data-quote-output-mint]').value.trim(),
                amount,
                slippage_bps: slippageBps,
            });
            const presentation = quotePresentation(quote);
            card.querySelector('[data-quote-preview-spend]').textContent = presentation.spend;
            card.querySelector('[data-quote-preview-usd]').textContent = presentation.spendUsd;
            card.querySelector('[data-quote-preview-output]').textContent = presentation.output;
            card.querySelector('[data-quote-preview-minimum]').textContent = presentation.minimum;
            card.querySelector('[data-quote-preview-slippage]').textContent = presentation.slippage;
            card.querySelector('[data-quote-preview-impact]').textContent = presentation.impact;
            card.querySelector('[data-quote-preview-route]').textContent = presentation.route;
            card.querySelector('[data-quote-preview-fees]').textContent = presentation.fees;
            preview.hidden = false;
            say('Quote refreshed. No transaction was created or signed.');
        } catch (error) {
            say(error?.message || 'Could not retrieve a swap quote.');
        } finally {
            submit.disabled = false;
        }
    });
}
