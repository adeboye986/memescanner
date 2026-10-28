export const tokenScannerFormState = ({
    scanner,
    network,
    runningActions = [],
    newTokenAction,
    momentumAction,
    maxChasePercent,
    momentumBudget,
}) => {
    const action = scanner === 'momentum-scan' ? 'momentum-scan' : 'token-scan';

    return {
        action,
        actionUrl: action === 'momentum-scan' ? momentumAction : newTokenAction,
        running: runningActions.includes(`${action}:${network}`),
        policy: action === 'momentum-scan'
            ? `Max chase ${maxChasePercent}% · Provider budget ${momentumBudget} (managed)`
            : `Max chase ${maxChasePercent}%`,
        help: action === 'momentum-scan'
            ? 'Momentum enrichment uses the platform-managed provider budget shown above. It cannot be edited here.'
            : 'New-token candidates are evaluated with the current authorized platform policy. Provider budgets are managed server-side and cannot be edited here.',
    };
};

export const mountTokenScanner = (form = document.querySelector('[data-token-scan-form]')) => {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const scanner = form.querySelector('[data-scan-type]');
    const network = form.querySelector('[data-scan-network]');
    const policy = form.querySelector('[data-scan-policy]');
    const help = form.querySelector('[data-scan-help]');
    const submit = form.querySelector('[data-scan-submit]');
    const submitLabel = form.querySelector('[data-scan-submit-label]');
    let runningActions = [];

    try {
        runningActions = JSON.parse(form.dataset.runningActions ?? '[]');
    } catch {
        runningActions = [];
    }

    const update = () => {
        if (!(scanner instanceof HTMLSelectElement) || !(network instanceof HTMLSelectElement)) {
            return;
        }

        const state = tokenScannerFormState({
            scanner: scanner.value,
            network: network.value,
            runningActions,
            newTokenAction: form.dataset.newTokenAction ?? form.action,
            momentumAction: form.dataset.momentumAction ?? form.action,
            maxChasePercent: form.dataset.maxChasePercent,
            momentumBudget: form.dataset.momentumBudget,
        });
        form.action = state.actionUrl;

        if (policy) {
            policy.textContent = state.policy;
        }

        if (help) {
            help.textContent = state.help;
        }

        if (submit instanceof HTMLButtonElement) {
            submit.disabled = state.running;
        }
        if (submitLabel) {
            submitLabel.textContent = state.running ? 'Scan already running' : 'Start Scan';
        }
    };

    scanner?.addEventListener('change', update);
    network?.addEventListener('change', update);
    form.addEventListener('submit', () => {
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
        }
        if (submitLabel) {
            submitLabel.textContent = 'Queuing…';
        }
    });

    update();
};
