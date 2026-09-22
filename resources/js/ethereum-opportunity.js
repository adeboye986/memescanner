import { createEthereumSwapRecovery, detectEthereumWallets, ethToWei, sendEthereumOpportunity } from './ethereum-wallet.js';

export function mountEthereumOpportunity(section, browser = window) {
    if (!section) return;
    const feedback = section.querySelector('[data-opportunity-feedback]');
    const say = (text) => { feedback.textContent = text; };
    const binding = { attempt_id: Number(section.dataset.attemptId), wallet_address: section.dataset.walletAddress, sell_amount_wei: section.dataset.sellAmountWei };
    const recovery = binding.wallet_address ? createEthereumSwapRecovery(section.dataset.userId, binding.wallet_address) : null;
    const post = async (step, payload = {}) => {
        const response = await fetch(section.dataset[`${step}Url`], {
            method: 'POST', credentials: 'same-origin', redirect: 'error',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify(payload), signal: AbortSignal.timeout(180000),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(body.message ?? 'The Ethereum request could not be completed. Refresh its status.');
            error.status = response.status;
            throw error;
        }
        return body;
    };
    const retry = section.querySelector('[data-opportunity-report]');
    const confirm = section.querySelector('[data-opportunity-confirm]');
    const prepare = section.querySelector('[data-opportunity-prepare]');
    const refreshControls = () => {
        if (retry) retry.hidden = !recovery?.pending();
        if (confirm) confirm.disabled = !!(recovery?.pending() || recovery?.sending || recovery?.uncertain) || section.dataset.signingRequested === '1';
        if (prepare) prepare.disabled = !!(recovery?.pending() || recovery?.sending || recovery?.uncertain);
    };
    const recover = async () => {
        if (!recovery?.pending()) return;
        const attemptId = recovery.pending().attempt_id;
        try {
            await recovery.recover(post);
            say(attemptId === binding.attempt_id ? 'Transaction reported. Refresh to see blockchain confirmation status.' : 'Previous transaction reported. Refresh this opportunity before continuing.');
            if (confirm) confirm.hidden = true;
        } catch (error) { say(error.message); }
        finally { refreshControls(); }
    };
    retry?.addEventListener('click', recover);
    const announced = [];
    const picker = section.querySelector('[data-opportunity-wallet]');
    let wallets = [];
    const subscribed = new Set();
    const detect = () => {
        wallets = detectEthereumWallets(browser, announced);
        if (!picker) return;
        picker.replaceChildren();
        wallets.forEach((entry, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = entry.label;
            picker.append(option);
            if (!subscribed.has(entry.wallet)) {
                entry.wallet.on?.('accountsChanged', recover);
                entry.wallet.on?.('connect', recover);
                subscribed.add(entry.wallet);
            }
        });
    };
    browser.addEventListener?.('eip6963:announceProvider', (event) => { announced.push(event.detail); detect(); });
    browser.dispatchEvent?.(new Event('eip6963:requestProvider'));
    detect();
    section.querySelector('[data-opportunity-connect]')?.addEventListener('click', async () => {
        try {
            const selected = wallets[Number(picker?.value ?? 0)];
            if (!selected) throw new Error('No Ethereum wallet detected. Open your wallet extension.');
            await selected.wallet.request({ method: 'eth_requestAccounts' });
            await recover();
            if (!recovery?.pending()) say('Wallet connected. Confirm & Buy checks the reserved account and Ethereum Mainnet before sending.');
        } catch { say('Wallet connection was not completed.'); }
    });
    prepare?.addEventListener('click', async () => {
        if (recovery?.pending() || recovery?.sending || recovery?.uncertain) return refreshControls();
        prepare.disabled = true;
        say('Preparing: refreshing market/security data and checking the reserved trade…');
        try {
            await post('prepare');
            browser.location.reload();
        } catch (error) { say(error.message); }
        // Refresh explicitly to resolve a timeout; never automatically request another quote.
    });
    confirm?.addEventListener('click', async () => {
        confirm.disabled = true;
        try {
            const result = await sendEthereumOpportunity(wallets[Number(picker?.value ?? 0)]?.wallet, post, binding, recovery);
            confirm.hidden = true;
            say(result.swap.status === 'confirmed' ? 'Executed / confirmed. Refresh for details.'
                : result.swap.status === 'failed' ? 'Ethereum transaction failed. Refresh for details.'
                    : 'Transaction submitted / awaiting blockchain confirmation.');
        } catch (error) {
            if (error.attemptUnavailable || (error?.code === 4001 && !recovery.uncertain)) confirm.hidden = true;
            say(error?.code === 4001 && !recovery.pending() ? 'Wallet request rejected. Refresh the opportunity status.' : error.message);
        } finally { refreshControls(); }
    });
    const approval = section.querySelector('[data-opportunity-approval]');
    approval?.addEventListener('submit', (event) => {
        try {
            if (recovery?.pending() || recovery?.uncertain) throw new Error('Resolve the existing transaction report first.');
            approval.querySelector('[name="sell_amount_wei"]').value = ethToWei(approval.querySelector('[data-opportunity-spend]').value);
        } catch (error) { event.preventDefault(); say(error.message); }
    });
    refreshControls();
    recover();
}
