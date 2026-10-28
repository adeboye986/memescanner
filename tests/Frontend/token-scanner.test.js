import assert from 'node:assert/strict';
import test from 'node:test';

import { tokenScannerFormState } from '../../resources/js/token-scanner.js';

const options = {
    network: 'solana',
    runningActions: [],
    newTokenAction: '/dashboard/actions/token-scan',
    momentumAction: '/dashboard/actions/momentum-scan',
    maxChasePercent: '35',
    momentumBudget: '1',
};

test('scanner selection maps only to the existing authorized submission routes', () => {
    const newToken = tokenScannerFormState({ ...options, scanner: 'token-scan' });
    const momentum = tokenScannerFormState({ ...options, scanner: 'momentum-scan' });

    assert.equal(newToken.actionUrl, '/dashboard/actions/token-scan');
    assert.equal(momentum.actionUrl, '/dashboard/actions/momentum-scan');
    assert.match(momentum.policy, /Provider budget 1 \(managed\)/);
    assert.match(momentum.help, /cannot be edited here/);
});

test('scanner state disables only the matching scanner and network duplicate', () => {
    const state = tokenScannerFormState({
        ...options,
        scanner: 'momentum-scan',
        runningActions: ['momentum-scan:solana'],
    });
    const otherNetwork = tokenScannerFormState({
        ...options,
        scanner: 'momentum-scan',
        network: 'ethereum',
        runningActions: ['momentum-scan:solana'],
    });

    assert.equal(state.running, true);
    assert.equal(otherNetwork.running, false);
});
