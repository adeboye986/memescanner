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

export function mountWalletCard(card) {
    if (!card) return;
    const connect = card.querySelector('[data-wallet-connect]');
    if (!connect) return;
    const feedback = card.querySelector('[data-wallet-feedback]');
    const picker = card.querySelector('[data-wallet-picker]');
    const options = card.querySelector('[data-wallet-options]');
    const say = (message) => { feedback.textContent = message; };
    let busy = false;
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
            connect.textContent = 'Connect another wallet';
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
}
