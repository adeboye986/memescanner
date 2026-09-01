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
    actionForms.forEach((form) => {
        const action = form.dataset.action;
        const isRunning = runningActions.includes(action);
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

const updateActivity = (activity) => {
    document.querySelector('#activity-empty')?.classList.toggle('hidden', activity !== null);
    document.querySelector('#activity-content')?.classList.toggle('hidden', activity === null);

    if (!activity) {
        return;
    }

    setText('#activity-label', activity.label);
    setText('#activity-status', activity.status);
    const statusBadge = document.querySelector('#activity-status');

    if (statusBadge) {
        statusBadge.classList.remove(
            'border-slate-700', 'text-slate-300',
            'border-emerald-400/20', 'bg-emerald-400/10', 'text-emerald-300',
            'border-red-400/20', 'bg-red-400/10', 'text-red-300',
        );

        const statusTones = {
            completed: ['border-emerald-400/20', 'bg-emerald-400/10', 'text-emerald-300'],
            failed: ['border-red-400/20', 'bg-red-400/10', 'text-red-300'],
            pending: ['border-slate-700', 'text-slate-300'],
            running: ['border-slate-700', 'text-slate-300'],
        };

        statusBadge.classList.add(...(statusTones[activity.status] ?? statusTones.pending));
    }
    setText('#activity-started', activity.started_at ?? '—');
    setText('#activity-finished', activity.finished_at ?? '—');
    setText('#activity-duration', activity.duration_seconds === null ? '—' : `${activity.duration_seconds}s`);
    setText('#activity-exit-code', activity.exit_code === null ? '—' : activity.exit_code);
    setText('#activity-summary', activity.summary || 'No output yet.');
    setText('#activity-output', activity.output || 'No output yet.');
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

        updateActivity(data.activity);
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
