const closeModal = document.querySelector('#close-position-modal');
const closeForm = document.querySelector('#close-position-form');

if (closeModal instanceof HTMLDialogElement && closeForm instanceof HTMLFormElement) {
    document.querySelectorAll('.open-close-modal').forEach((button) => {
        button.addEventListener('click', () => {
            closeForm.action = button.dataset.action ?? '';
            document.querySelector('#modal-symbol').textContent = button.dataset.symbol ?? '';
            document.querySelector('#modal-return').textContent = button.dataset.return ?? '';
            document.querySelector('#modal-remaining').textContent = button.dataset.remaining ?? '';
            document.querySelector('#modal-value').textContent = button.dataset.value ?? '';
            closeModal.showModal();
        });
    });

    document.querySelector('#cancel-close')?.addEventListener('click', () => closeModal.close());

    closeModal.addEventListener('click', (event) => {
        if (event.target === closeModal) {
            closeModal.close();
        }
    });
}

const activitySection = document.querySelector('#system-activity');
const actionForms = document.querySelectorAll('.dashboard-action-form');
const scannerChain = document.querySelector('#scanner-chain');
let latestRunningActions = Array.from(actionForms)
    .filter((form) => form.querySelector('button')?.disabled)
    .map((form) => form.dataset.actionKey ?? form.dataset.action);

actionForms.forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button');
        const state = form.querySelector('.action-state');

        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }

        if (state) {
            state.textContent = 'Queuing…';
        }
    });
});

const relativeTime = (date) => {
    if (!date) {
        return 'Never';
    }

    const seconds = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 1000));

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    return `${Math.floor(minutes / 60)}h ago`;
};

const setText = (selector, value) => {
    const element = document.querySelector(selector);

    if (element) {
        element.textContent = value;
    }
};

const updateTrackerBadge = (status) => {
    const badge = document.querySelector('#tracker-status-badge');
    const dot = document.querySelector('#tracker-status-dot');

    setText('#tracker-status-text', status.toUpperCase());

    if (!badge || !dot) {
        return;
    }

    badge.classList.remove(
        'border-emerald-400/20', 'bg-emerald-400/10', 'text-emerald-300',
        'border-amber-400/20', 'bg-amber-400/10', 'text-amber-300',
        'border-slate-700', 'bg-slate-800', 'text-slate-300',
    );
    dot.classList.remove('bg-emerald-400', 'bg-amber-400', 'bg-slate-500');

    const tones = {
        active: ['border-emerald-400/20', 'bg-emerald-400/10', 'text-emerald-300'],
        stale: ['border-amber-400/20', 'bg-amber-400/10', 'text-amber-300'],
        unknown: ['border-slate-700', 'bg-slate-800', 'text-slate-300'],
    };

    badge.classList.add(...(tones[status] ?? tones.unknown));
    dot.classList.add(status === 'active' ? 'bg-emerald-400' : status === 'stale' ? 'bg-amber-400' : 'bg-slate-500');
};

const updateActionButtons = (runningActions) => {
    latestRunningActions = runningActions;

    actionForms.forEach((form) => {
        const actionKey = form.dataset.actionKey ?? form.dataset.action;
        const isRunning = runningActions.includes(actionKey);
        const button = form.querySelector('button');
        const state = form.querySelector('.action-state');

        if (button instanceof HTMLButtonElement) {
            button.disabled = isRunning;
        }

        if (state) {
            state.textContent = isRunning ? 'Running' : 'Run';
        }
    });
};

if (scannerChain instanceof HTMLSelectElement) {
    scannerChain.addEventListener('change', () => {
        document.querySelectorAll('[data-chain-action]').forEach((form) => {
            const input = form.querySelector('[data-chain-input]');

            if (input instanceof HTMLInputElement) {
                input.value = scannerChain.value;
            }

            form.dataset.actionKey = `${form.dataset.action}:${scannerChain.value}`;
        });

        updateActionButtons(latestRunningActions);
    });
}

const updateCurrentActivity = (activity) => {
    document.querySelector('#current-activity-empty')?.classList.toggle('hidden', activity !== null);
    document.querySelector('#current-activity-content')?.classList.toggle('hidden', activity === null);

    if (!activity) {
        return;
    }

    setText('#current-activity-label', `⚡ ${activity.label}`);
    setText('#current-activity-status', activity.status);
    setText('#current-activity-started', activity.started_at ?? 'Waiting for worker');
    setText('#current-activity-running', activity.running_seconds === null ? 'Pending' : `${activity.running_seconds}s`);
};

const renderRecentActivities = (activities) => {
    const list = document.querySelector('#recent-activity-list');

    if (!list) {
        return;
    }

    list.replaceChildren();

    if (activities.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'rounded-lg border border-dashed border-slate-800 px-4 py-5 text-center text-sm text-slate-600';
        empty.textContent = 'No activity recorded yet.';
        list.append(empty);

        return;
    }

    const statusColors = {
        pending: 'text-amber-300',
        running: 'text-blue-300',
        completed: 'text-emerald-300',
        failed: 'text-red-300',
    };

    activities.forEach((activity) => {
        const details = document.createElement('details');
        details.className = 'group rounded-lg border border-slate-800 bg-slate-950/50';

        const summary = document.createElement('summary');
        summary.className = 'grid cursor-pointer list-none grid-cols-[1fr_auto] items-center gap-3 px-3 py-3 marker:hidden';

        const identity = document.createElement('span');
        identity.className = 'min-w-0';
        const label = document.createElement('span');
        label.className = 'block truncate text-sm font-semibold text-slate-200';
        label.textContent = activity.label;
        const context = document.createElement('span');
        context.className = 'mt-1 block text-xs text-slate-500';
        context.textContent = `${activity.triggered_by.charAt(0).toUpperCase()}${activity.triggered_by.slice(1)} · ${activity.relative_time}`;
        identity.append(label, context);

        const result = document.createElement('span');
        result.className = 'text-right';
        const status = document.createElement('span');
        status.className = `block text-xs font-semibold uppercase tracking-wider ${statusColors[activity.status] ?? 'text-slate-300'}`;
        status.textContent = activity.status;
        const duration = document.createElement('span');
        duration.className = 'mt-1 block text-xs text-slate-500';
        duration.textContent = activity.duration_seconds === null ? '—' : `${activity.duration_seconds}s`;
        result.append(status, duration);

        const output = document.createElement('pre');
        output.className = 'max-h-64 overflow-auto border-t border-slate-800 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-slate-400';
        output.textContent = activity.output || 'No output captured yet.';

        summary.append(identity, result);
        details.append(summary, output);
        list.append(details);
    });
};

const pollActivity = async () => {
    if (!(activitySection instanceof HTMLElement)) {
        return;
    }

    try {
        const response = await fetch(activitySection.dataset.statusUrl, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        updateCurrentActivity(data.current_activity);
        renderRecentActivities(data.recent_activities);
        updateActionButtons(data.running_actions);
        updateTrackerBadge(data.system_status.status);
        setText('#last-tracker-check', relativeTime(data.system_status.last_tracker_check));
        setText('#last-momentum-scan', relativeTime(data.system_status.last_momentum_scan));
        setText('#last-token-scan', relativeTime(data.system_status.last_token_scan));
    } catch {
        setText('#activity-live-indicator', 'Offline');
    }
};

if (activitySection) {
    window.setInterval(pollActivity, 3000);
}
