import { mountEthereumOpportunity } from './ethereum-opportunity';
import { mountWalletCard } from './solana-wallet';
import { mountSolanaOpportunity } from './solana-opportunity';
import { mountEthereumWalletCard } from './ethereum-wallet';
import { createDashboardActivityPoller } from './dashboard-activity-poller';

mountWalletCard(document.querySelector('#solana-wallet'));
mountEthereumWalletCard(document.querySelector('#ethereum-wallet'));
mountEthereumOpportunity(document.querySelector('[data-ethereum-opportunity]'));
mountSolanaOpportunity(document.querySelector('[data-solana-opportunity]'));

const closeModal = document.querySelector('#close-position-modal');
const closeForm = document.querySelector('#close-position-form');
const adminSidebar = document.querySelector('#admin-sidebar');
const adminSidebarToggle = document.querySelector('#admin-sidebar-toggle');
const adminSidebarBackdrop = document.querySelector('#admin-sidebar-backdrop');

const setAdminSidebarOpen = (open) => {
    adminSidebar?.classList.toggle('-translate-x-full', !open);
    adminSidebarBackdrop?.classList.toggle('hidden', !open);
    adminSidebarToggle?.setAttribute('aria-expanded', String(open));
};

adminSidebarToggle?.addEventListener('click', () => setAdminSidebarOpen(adminSidebarToggle.getAttribute('aria-expanded') !== 'true'));
adminSidebarBackdrop?.addEventListener('click', () => setAdminSidebarOpen(false));
adminSidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (window.innerWidth < 1024) {
        setAdminSidebarOpen(false);
    }
}));

const executionModeInputs = document.querySelectorAll('input[name="execution_mode"]');
const entryModeInputs = document.querySelectorAll('input[name="entry_mode"]');
const liveExecutionWarning = document.querySelector('#live-execution-warning');
const liveAutoWarning = document.querySelector('#live-auto-warning');

document.querySelectorAll('.copy-value').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copyValue ?? '');
            button.textContent = 'Copied';
            window.setTimeout(() => { button.textContent = 'Copy'; }, 1500);
        } catch {
            button.textContent = 'Copy failed';
        }
    });
});

const updateLiveWarnings = () => {
    const executionMode = document.querySelector('input[name="execution_mode"]:checked')?.value;
    const entryMode = document.querySelector('input[name="entry_mode"]:checked')?.value;

    liveExecutionWarning?.classList.toggle('hidden', executionMode !== 'live');
    liveAutoWarning?.classList.toggle('hidden', executionMode !== 'live' || entryMode !== 'auto');
};

[...executionModeInputs, ...entryModeInputs].forEach((input) => input.addEventListener('change', updateLiveWarnings));

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
        degraded: ['border-amber-400/20', 'bg-amber-400/10', 'text-amber-300'],
        stale: ['border-amber-400/20', 'bg-amber-400/10', 'text-amber-300'],
        unknown: ['border-slate-700', 'bg-slate-800', 'text-slate-300'],
    };

    badge.classList.add(...(tones[status] ?? tones.unknown));
    dot.classList.add(status === 'active' ? 'bg-emerald-400' : ['stale', 'degraded'].includes(status) ? 'bg-amber-400' : 'bg-slate-500');
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

const activityStatusColors = {
    pending: 'text-amber-300',
    running: 'text-blue-300',
    completed: 'text-emerald-300',
    failed: 'text-red-300',
};

const appendScanResultSummary = (container, activity) => {
    const result = activity.result_summary;

    if (!result || result.version !== 1 || result.kind !== 'scanner_execution') {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'mt-3 rounded-lg border border-slate-800 bg-slate-950/60 p-3';

    const counts = document.createElement('dl');
    counts.className = 'grid grid-cols-3 gap-2 text-center sm:grid-cols-6';

    [
        ['discovered', 'Discovered'],
        ['examined', 'Examined'],
        ['qualified', 'Qualified'],
        ['opportunities_created', 'Created'],
        ['opportunities_existing', 'Existing'],
        ['downstream_failed', 'Downstream failures'],
        ['skipped', 'Skipped'],
        ['failed', 'Candidate failures'],
        ['exports_acknowledged', 'Exported'],
    ].forEach(([field, label]) => {
        const item = document.createElement('div');
        const term = document.createElement('dt');
        term.className = 'text-[10px] uppercase tracking-wider text-slate-600';
        term.textContent = label;
        const value = document.createElement('dd');
        value.className = 'mt-1 font-semibold tabular-nums text-slate-300';
        value.textContent = result.counts?.[field] ?? 0;
        item.append(term, value);
        counts.append(item);
    });

    const exports = document.createElement('p');
    exports.className = 'mt-3 text-[11px] leading-5 text-slate-500';
    const exportStates = Object.entries(result.exports ?? {})
        .map(([state, count]) => `${state.replaceAll('_', ' ')} ${count}`)
        .join(' / ');
    exports.textContent = `Engine exports: ${exportStates || 'unavailable'}`;

    wrapper.append(counts, exports);

    if (Array.isArray(result.provider_failures) && result.provider_failures.length > 0) {
        const warnings = document.createElement('p');
        warnings.className = 'mt-1 text-[11px] leading-5 text-amber-300/80';
        warnings.textContent = `Provider warnings: ${result.provider_failures.map((failure) => `${failure.code} x${failure.count}`).join(', ')}`;
        wrapper.append(warnings);
    }

    if (Array.isArray(result.downstream_failure_codes) && result.downstream_failure_codes.length > 0) {
        const warnings = document.createElement('p');
        warnings.className = 'mt-1 text-[11px] leading-5 text-amber-300/80';
        warnings.textContent = `Downstream warnings: ${result.downstream_failure_codes.map((failure) => `${failure.code} x${failure.count}`).join(', ')}`;
        wrapper.append(warnings);
    }

    const progress = document.createElement('p');
    progress.className = 'mt-1 text-[11px] text-slate-600';
    progress.textContent = `Last structured progress: ${activity.last_progress_at ?? 'Unavailable'}`;
    wrapper.append(progress);
    container.append(wrapper);
};

const renderCurrentActivities = (activities) => {
    const list = document.querySelector('#current-activity-list');
    const empty = document.querySelector('#current-activity-empty');

    empty?.classList.toggle('hidden', activities.length > 0);
    list?.classList.toggle('hidden', activities.length === 0);

    if (!list) {
        return;
    }

    list.replaceChildren();

    activities.forEach((activity) => {
        const card = document.createElement('article');
        card.className = 'rounded-xl border border-blue-400/20 bg-blue-400/5 p-4';

        const heading = document.createElement('div');
        heading.className = 'flex flex-wrap items-center justify-between gap-3';
        const label = document.createElement('h3');
        label.className = 'font-semibold text-white';
        label.textContent = activity.label;
        const status = document.createElement('span');
        status.className = 'rounded-full border border-blue-400/20 bg-blue-400/10 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-blue-300';
        status.textContent = activity.status;
        heading.append(label, status);

        const summary = document.createElement('p');
        summary.className = 'mt-2 text-xs leading-5 text-slate-400';
        summary.textContent = activity.summary;

        const context = document.createElement('p');
        context.className = 'mt-2 text-xs text-slate-500';
        const elapsed = activity.running_seconds === null ? 'Pending' : `${activity.running_seconds}s`;
        const chain = activity.chain ? ` · ${activity.chain.toUpperCase()}` : '';
        context.textContent = `Initiated by ${activity.initiated_by}${chain} · ${activity.started_at ?? 'Waiting for Laravel queue worker'} · ${elapsed}`;

        card.append(heading, summary);
        appendScanResultSummary(card, activity);
        card.append(context);
        list.append(card);
    });
};

const renderRecentActivities = (activities) => {
    const list = document.querySelector('#recent-activity-list');

    if (!list) {
        return;
    }

    list.replaceChildren();
    setText('#recent-activity-count', `Latest ${activities.length}`);

    if (activities.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'rounded-lg border border-dashed border-slate-800 px-4 py-5 text-center text-sm text-slate-600';
        empty.textContent = 'No activity recorded yet.';
        list.append(empty);

        return;
    }

    activities.forEach((activity) => {
        const details = document.createElement('details');
        details.className = 'group rounded-lg border border-slate-800 bg-slate-950/50';

        const heading = document.createElement('summary');
        heading.className = 'grid cursor-pointer list-none grid-cols-[1fr_auto] items-center gap-3 px-3 py-3 marker:hidden';

        const identity = document.createElement('span');
        identity.className = 'min-w-0';
        const label = document.createElement('span');
        label.className = 'block truncate text-sm font-semibold text-slate-200';
        label.textContent = activity.label;
        const context = document.createElement('span');
        context.className = 'mt-1 block text-xs text-slate-500';
        const trigger = activity.triggered_by.charAt(0).toUpperCase() + activity.triggered_by.slice(1);
        const chain = activity.chain ? ` · ${activity.chain.toUpperCase()}` : '';
        context.textContent = `${trigger} · ${activity.initiated_by}${chain} · ${activity.relative_time}`;
        identity.append(label, context);

        const result = document.createElement('span');
        result.className = 'text-right';
        const status = document.createElement('span');
        status.className = `block text-xs font-semibold uppercase tracking-wider ${activityStatusColors[activity.status] ?? 'text-slate-300'}`;
        status.textContent = activity.status;
        const duration = document.createElement('span');
        duration.className = 'mt-1 block text-xs text-slate-500';
        duration.textContent = activity.duration_seconds === null ? '—' : `${activity.duration_seconds}s`;
        result.append(status, duration);

        const safeSummary = document.createElement('p');
        safeSummary.className = 'border-t border-slate-800 p-3 text-xs leading-5 text-slate-400';
        safeSummary.textContent = activity.summary;


        const timing = document.createElement('p');
        timing.className = 'border-t border-slate-800 px-3 py-2 text-xs text-slate-600';
        timing.textContent = `Started: ${activity.started_at ?? 'Not started'} · Finished: ${activity.finished_at ?? 'Not finished'}`;
        heading.append(identity, result);
        details.append(heading, safeSummary);
        appendScanResultSummary(details, activity);
        details.append(timing);
        list.append(details);
    });
};

const updateStateText = (selector, state) => {
    const element = document.querySelector(selector);

    if (!element) {
        return;
    }

    element.textContent = String(state).replaceAll('_', ' ').toUpperCase();
    element.classList.remove('text-emerald-300', 'text-amber-300', 'text-red-300', 'text-slate-300');

    if (['ok', 'up', 'ready', 'reachable', 'healthy', 'active'].includes(state)) {
        element.classList.add('text-emerald-300');
    } else if (['unavailable', 'down', 'stale', 'degraded'].includes(state)) {
        element.classList.add('text-amber-300');
    } else {
        element.classList.add('text-slate-300');
    }
};

const updateEngineStatus = (status) => {
    updateStateText('#engine-connectivity', status.connectivity);
    updateStateText('#engine-liveness', status.liveness);
    updateStateText('#engine-readiness', status.readiness);
    updateStateText('#engine-postgres', status.postgres);
    updateStateText('#engine-workflow-leadership', status.workflow_leadership);
    setText('#engine-last-response', relativeTime(status.last_successful_response_at));
};

const updateOperationalStatus = (status) => {
    updateStateText('#operation-scheduler', status.scheduler.status);
    updateStateText('#operation-queue', status.queue.status);
    updateStateText('#operation-legacy-paper-tracker', status.legacy_paper_tracker.status);
    setText('#operation-pending-jobs', status.pending_jobs);
    setText('#operation-failed-jobs', status.failed_jobs);
};

const updateDashboardStatus = (data) => {
    renderCurrentActivities(data.current_activities ?? (data.current_activity ? [data.current_activity] : []));
    renderRecentActivities(data.recent_activities ?? []);
    updateActionButtons(data.running_actions ?? []);
    updateTrackerBadge(data.system_status.status);
    setText('#last-tracker-check', relativeTime(data.system_status.last_tracker_check));
    setText('#tracker-cycle-duration', data.system_status.cycle_duration_ms === null ? 'N/A' : `${Math.round(data.system_status.cycle_duration_ms)} ms`);
    setText('#tracker-open-positions', data.system_status.open_positions ?? 'N/A');
    setText('#tracker-priced-positions', data.system_status.priced_positions === null ? 'N/A' : `${data.system_status.priced_positions} / ${data.system_status.open_positions}`);
    setText('#tracker-outstanding-unverified', data.system_status.outstanding_unverified_positions ?? 'N/A');
    setText('#tracker-provider-success', relativeTime(data.system_status.last_successful_provider_request));
    setText('#tracker-valid-observation', relativeTime(data.system_status.last_successful_market_observation));
    setText('#tracker-process-lease', data.system_status.process_lock_held ? 'held' : 'not held');
    setText('#tracker-process-heartbeat', relativeTime(data.system_status.last_process_heartbeat));
    setText('#tracker-provider-failures', data.system_status.provider_failures ?? 'N/A');
    setText('#last-momentum-scan', relativeTime(data.system_status.last_momentum_scan));
    setText('#last-token-scan', relativeTime(data.system_status.last_token_scan));
    updateOperationalStatus(data.operational_status);
    updateEngineStatus(data.engine_status);
};

const updatePollingState = (state, lastSuccessfulAt) => {
    const labels = {
        connected: 'Connected',
        unavailable: 'Unavailable',
        stale: 'Stale',
        paused: 'Paused',
        authentication_expired: 'Session expired',
    };
    const indicator = document.querySelector('#activity-live-indicator');

    if (indicator) {
        indicator.textContent = labels[state] ?? 'Unknown';
        indicator.classList.remove('border-emerald-400/20', 'text-emerald-300', 'border-amber-400/20', 'text-amber-300', 'border-red-400/20', 'text-red-300', 'border-slate-700', 'text-slate-400');

        if (state === 'connected') {
            indicator.classList.add('border-emerald-400/20', 'text-emerald-300');
        } else if (state === 'authentication_expired') {
            indicator.classList.add('border-red-400/20', 'text-red-300');
        } else if (['unavailable', 'stale'].includes(state)) {
            indicator.classList.add('border-amber-400/20', 'text-amber-300');
        } else {
            indicator.classList.add('border-slate-700', 'text-slate-400');
        }
    }

    setText('#activity-last-refresh', lastSuccessfulAt === null ? 'Never' : relativeTime(new Date(lastSuccessfulAt).toISOString()));
};

if (activitySection instanceof HTMLElement && activitySection.dataset.statusUrl) {
    createDashboardActivityPoller({
        endpoint: activitySection.dataset.statusUrl,
        onData: updateDashboardStatus,
        onState: updatePollingState,
    }).start();
}
