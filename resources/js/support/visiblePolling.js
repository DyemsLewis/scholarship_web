export function startVisiblePolling(refresh, {
    interval,
    page = document,
    browser = window,
} = {}) {
    let timer = null;
    let running = false;
    let stopped = false;

    const available = () => !stopped && page.visibilityState === 'visible' && browser.navigator?.onLine !== false;
    function cancelTimer() {
        if (timer !== null) browser.clearTimeout(timer);
        timer = null;
    }
    function schedule() {
        if (available() && !running && timer === null) timer = browser.setTimeout(tick, interval);
    }
    async function tick() {
        cancelTimer();
        if (!available() || running) return;
        running = true;
        try {
            await refresh();
        } catch {
            // Background failures stay quiet; the next tick or a manual refresh can retry.
        } finally {
            running = false;
            schedule();
        }
    }

    page.addEventListener('visibilitychange', tick);
    browser.addEventListener('online', tick);
    browser.addEventListener('offline', cancelTimer);
    schedule();

    return () => {
        stopped = true;
        cancelTimer();
        page.removeEventListener('visibilitychange', tick);
        browser.removeEventListener('online', tick);
        browser.removeEventListener('offline', cancelTimer);
    };
}
