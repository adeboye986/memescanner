export const failureState = (lastSuccessfulAt, now, staleAfterMs) => {
    if (lastSuccessfulAt === null) {
        return 'unavailable';
    }

    return now - lastSuccessfulAt >= staleAfterMs ? 'stale' : 'unavailable';
};

export const createDashboardActivityPoller = ({
    endpoint,
    fetchImpl = globalThis.fetch,
    documentRef = globalThis.document,
    setTimeoutFn = globalThis.setTimeout,
    clearTimeoutFn = globalThis.clearTimeout,
    AbortControllerClass = globalThis.AbortController,
    now = () => Date.now(),
    intervalMs = 3000,
    requestTimeoutMs = 8000,
    staleAfterMs = 15000,
    onData = () => {},
    onState = () => {},
}) => {
    let stopped = true;
    let inFlight = false;
    let pollTimer = null;
    let requestTimer = null;
    let controller = null;
    let lastSuccessfulAt = null;

    const notify = (state) => onState(state, lastSuccessfulAt);

    const clearPollTimer = () => {
        if (pollTimer !== null) {
            clearTimeoutFn(pollTimer);
            pollTimer = null;
        }
    };

    const schedule = () => {
        clearPollTimer();

        if (!stopped && !documentRef.hidden) {
            pollTimer = setTimeoutFn(() => {
                void poll();
            }, intervalMs);
        }
    };

    const poll = async () => {
        if (stopped || inFlight || documentRef.hidden) {
            return false;
        }

        inFlight = true;
        controller = new AbortControllerClass();
        requestTimer = setTimeoutFn(() => controller?.abort(), requestTimeoutMs);

        try {
            const response = await fetchImpl(endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (response.status === 401 || response.status === 419 || response.redirected) {
                notify('authentication_expired');
                stop();

                return false;
            }

            if (!response.ok) {
                notify(failureState(lastSuccessfulAt, now(), staleAfterMs));

                return false;
            }

            const data = await response.json();
            onData(data);
            lastSuccessfulAt = now();
            notify('connected');

            return true;
        } catch {
            if (!stopped && !documentRef.hidden) {
                notify(failureState(lastSuccessfulAt, now(), staleAfterMs));
            }

            return false;
        } finally {
            if (requestTimer !== null) {
                clearTimeoutFn(requestTimer);
                requestTimer = null;
            }
            controller = null;
            inFlight = false;
            schedule();
        }
    };

    const visibilityChanged = () => {
        if (documentRef.hidden) {
            clearPollTimer();
            controller?.abort();
            notify('paused');

            return;
        }

        void poll();
    };

    const start = () => {
        if (!stopped) {
            return Promise.resolve(false);
        }

        stopped = false;
        documentRef.addEventListener('visibilitychange', visibilityChanged);


        if (documentRef.hidden) {
            notify('paused');

            return Promise.resolve(false);
        }
        return poll();
    };

    const stop = () => {
        if (stopped) {
            return;
        }

        stopped = true;
        clearPollTimer();
        controller?.abort();
        documentRef.removeEventListener('visibilitychange', visibilityChanged);
    };

    return {
        start,
        stop,
        pollNow: poll,
        isInFlight: () => inFlight,
        lastSuccessfulAt: () => lastSuccessfulAt,
    };
};
