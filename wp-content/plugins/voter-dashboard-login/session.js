(function () {
    'use strict';
    const config = window.pimaVoterSession;
    if (!config) return;
    let deadline = Date.now() + config.remainingMs;
    let busy = false;
    let stopped = false;
    let lastActivity = 0;
    let lastSent = 0;
    let pending = false;
    let logoutRequested = false;
    let activityTimer;
    let expiryTimer;

    function redirect() {
        stopped = true;
        clearTimeout(activityTimer);
        clearTimeout(expiryTimer);
        window.location.replace(config.loginUrl);
    }

    function scheduleExpiry() {
        clearTimeout(expiryTimer);
        expiryTimer = setTimeout(function () { request('status'); }, Math.max(50, deadline - Date.now()));
    }

    async function request(operation) {
        if (busy || stopped) return;
        busy = true;
        const started = Date.now();
        const controller = new AbortController();
        const timeout = setTimeout(function () { controller.abort(); }, 5000);
        try {
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal,
                body: new URLSearchParams({
                    action: 'pima_voter_session', operation: operation, csrf: config.csrf,
                    ageMs: String(Math.max(0, started - lastActivity))
                })
            });
            if (response.status === 401 || response.status === 403) {
                redirect();
                return;
            }
            if (!response.ok) throw new Error('Session check failed');
            const result = await response.json();
            if (!result.success) throw new Error('Invalid session response');
            if (operation === 'logout') {
                redirect();
                return;
            }
            if (!Number.isFinite(result.data.remainingMs)) throw new Error('Invalid expiry');
            deadline = started + result.data.remainingMs;
        } catch (error) {
            if (Date.now() >= deadline || operation === 'logout') redirect();
        } finally {
            clearTimeout(timeout);
            busy = false;
            if (!stopped) {
                if (logoutRequested) {
                    logoutRequested = false;
                    request('logout');
                    return;
                }
                scheduleExpiry();
                if (pending) scheduleActivity();
            }
        }
    }

    function scheduleActivity() {
        clearTimeout(activityTimer);
        activityTimer = setTimeout(flushActivity, Math.max(0, 5000 - (Date.now() - lastSent)));
    }

    function flushActivity() {
        if (!pending || busy || stopped) return;
        pending = false;
        if (Date.now() >= deadline || Date.now() - lastActivity > 5000) {
            request('status');
            return;
        }
        lastSent = Date.now();
        request('activity');
    }

    function activity(event) {
        if (stopped || logoutRequested || Date.now() >= deadline) {
            event.preventDefault();
            event.stopImmediatePropagation();
            request('status');
            return;
        }
        if (!event.isTrusted) return;
        const logout = event.target instanceof Element && event.target.closest('[data-pima-logout]');
        if (logout && event.type === 'click') {
            event.preventDefault();
            pending = false;
            clearTimeout(activityTimer);
            if (busy) {
                logoutRequested = true;
            } else {
                request('logout');
            }
            return;
        }
        lastActivity = Date.now();
        pending = true;
        scheduleActivity();
    }

    ['pointerdown', 'click', 'keydown', 'input', 'wheel', 'touchstart', 'scroll', 'submit'].forEach(function (name) {
        window.addEventListener(name, activity, { capture: true, passive: false });
    });
    window.addEventListener('pageshow', function () { request('status'); });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) request('status');
    });
    setInterval(function () { request('status'); }, 15000);
    scheduleExpiry();
}());