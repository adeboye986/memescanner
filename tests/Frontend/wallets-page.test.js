import assert from 'node:assert/strict';
import test from 'node:test';

import {
    closeWalletDialog,
    mountWalletsPage,
    openWalletDialog,
    setWalletVerificationStatus,
} from '../../resources/js/wallets-page.js';

const fakeElement = (dataset = {}) => {
    const listeners = new Map();

    return {
        dataset,
        hidden: true,
        open: false,
        clicks: 0,
        textContent: '',
        classes: new Set(),
        classList: {
            toggle(name, enabled) {
                enabled ? this.owner.classes.add(name) : this.owner.classes.delete(name);
            },
            owner: null,
        },
        addEventListener(type, handler) {
            listeners.set(type, handler);
        },
        removeEventListener(type, handler) {
            if (listeners.get(type) === handler) listeners.delete(type);
        },
        dispatch(type) {
            listeners.get(type)?.();
        },
        click() {
            this.clicks += 1;
            this.dispatch('click');
        },
        showModal() {
            this.open = true;
        },
        close() {
            this.open = false;
            this.dispatch('close');
        },
    };
};

const withClassListOwner = (element) => {
    element.classList.owner = element;

    return element;
};

test('wallet dialog helpers preserve native dialog state', () => {
    const dialog = fakeElement();

    openWalletDialog(dialog);
    assert.equal(dialog.hidden, false);
    assert.equal(dialog.open, true);

    closeWalletDialog(dialog);
    assert.equal(dialog.hidden, true);
    assert.equal(dialog.open, false);
});

test('network selection delegates to the existing chain-specific connect control', () => {
    const launcher = fakeElement();
    const dialog = fakeElement();
    const cancel = fakeElement();
    const solanaChoice = fakeElement({ walletNetwork: 'solana' });
    const ethereumChoice = fakeElement({ walletNetwork: 'ethereum' });
    const solanaConnect = fakeElement();
    const ethereumConnect = fakeElement();
    const elements = {
        '[data-wallet-page-connect]': launcher,
        '[data-wallet-network-dialog]': dialog,
        '[data-wallet-network-cancel]': cancel,
        '[data-wallet-connect]': solanaConnect,
        '[data-eth-connect]': ethereumConnect,
    };
    const page = {
        querySelector: (selector) => elements[selector] ?? null,
        querySelectorAll: (selector) => selector === '[data-wallet-network]'
            ? [solanaChoice, ethereumChoice]
            : [],
    };

    const mounted = mountWalletsPage(page);

    launcher.click();
    assert.equal(dialog.open, true);

    ethereumChoice.click();
    assert.equal(dialog.hidden, true);
    assert.equal(ethereumConnect.clicks, 1);
    assert.equal(solanaConnect.clicks, 0);

    launcher.click();
    cancel.click();
    assert.equal(dialog.hidden, true);

    mounted.destroy();
    launcher.click();
    assert.equal(dialog.hidden, true);
});

test('verification status uses text and semantic classes instead of color alone', () => {
    const status = withClassListOwner(fakeElement());

    setWalletVerificationStatus(status, true);
    assert.equal(status.textContent, 'Verified');
    assert.equal(status.classes.has('text-emerald-300'), true);

    setWalletVerificationStatus(status, false);
    assert.equal(status.textContent, 'Not connected');
    assert.equal(status.classes.has('text-emerald-300'), false);
    assert.equal(status.classes.has('text-slate-400'), true);
});
