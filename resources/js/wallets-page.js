export const openWalletDialog = (dialog) => {
    if (!dialog) return;

    dialog.hidden = false;

    if (typeof dialog.showModal === 'function' && !dialog.open) {
        dialog.showModal();
    }
};

export const closeWalletDialog = (dialog) => {
    if (!dialog) return;

    if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
    }

    dialog.hidden = true;
};

export const setWalletVerificationStatus = (element, verified) => {
    if (!element) return;

    element.textContent = verified ? 'Verified' : 'Not connected';
    element.classList?.toggle('border-emerald-400/20', verified);
    element.classList?.toggle('bg-emerald-400/10', verified);
    element.classList?.toggle('text-emerald-300', verified);
    element.classList?.toggle('border-slate-700', !verified);
    element.classList?.toggle('text-slate-400', !verified);
};

export const mountWalletsPage = (page) => {
    if (!page) return { destroy() {} };

    const launcher = page.querySelector('[data-wallet-page-connect]');
    const dialog = page.querySelector('[data-wallet-network-dialog]');
    const cancel = page.querySelector('[data-wallet-network-cancel]');
    const networkButtons = Array.from(page.querySelectorAll('[data-wallet-network]'));
    const open = () => openWalletDialog(dialog);
    const close = () => closeWalletDialog(dialog);
    const handleDialogClose = () => { dialog.hidden = true; };
    const networkHandlers = new Map();

    launcher?.addEventListener('click', open);
    cancel?.addEventListener('click', close);
    dialog?.addEventListener('close', handleDialogClose);

    networkButtons.forEach((button) => {
        const handler = () => {
            const chain = button.dataset.walletNetwork;
            close();
            page.querySelector(chain === 'ethereum' ? '[data-eth-connect]' : '[data-wallet-connect]')?.click();
        };

        networkHandlers.set(button, handler);
        button.addEventListener('click', handler);
    });

    return {
        destroy() {
            launcher?.removeEventListener('click', open);
            cancel?.removeEventListener('click', close);
            dialog?.removeEventListener('close', handleDialogClose);
            networkHandlers.forEach((handler, button) => button.removeEventListener('click', handler));
        },
    };
};
