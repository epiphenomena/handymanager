// offline.js - Offline support for the tech PWA.
//
// Writes made while offline are queued in localStorage and replayed in order
// on the next page load, when connectivity returns, or when the app comes
// back to the foreground. Reads fall back to the last data fetched online.
// Queued creates carry a client UUID so a replay can never duplicate a task.
(function () {
    const QUEUE_KEY = 'hm_queue';
    const CACHE_PREFIX = 'hm_cache_';

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        // Fallback for older WebViews
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function getQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

    function setQueue(queue) {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        updateBanner();
    }

    // Last-known data fetched while online (open jobs, task lists)
    function cacheSet(key, value) {
        try {
            localStorage.setItem(CACHE_PREFIX + key, JSON.stringify(value));
        } catch (e) { /* storage full - reads just won't have a fallback */ }
    }

    function cacheGet(key) {
        try {
            return JSON.parse(localStorage.getItem(CACHE_PREFIX + key));
        } catch (e) {
            return null;
        }
    }

    // Replay the queue in order. Stops at the first network failure (still
    // offline) or token problem (will retry later); drops items the server
    // permanently rejects (e.g. the job was deleted).
    let syncPromise = null;

    function syncQueue() {
        if (syncPromise) return syncPromise;
        syncPromise = doSync().finally(function () { syncPromise = null; });
        return syncPromise;
    }

    async function doSync() {
        const token = localStorage.getItem('handymanager_token');
        if (!token) return;

        let queue = getQueue();
        while (queue.length > 0) {
            const item = queue[0];
            let data;
            try {
                const response = await fetch(item.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({}, item.payload, { token: token, queued: true }))
                });
                data = await response.json();
            } catch (e) {
                break; // still offline - try again next time
            }

            if (data.success) {
                queue.shift();
                setQueue(queue);
            } else if ((data.message || '').toLowerCase().includes('token')) {
                break; // bad token - fix settings, then it retries
            } else {
                // Permanent rejection (e.g. job deleted) - drop and move on
                console.warn('Dropping unsyncable queued item:', item, data.message);
                queue.shift();
                setQueue(queue);
            }
        }
        updateBanner();
    }

    // Queue a write and immediately try to sync (no-op if offline)
    function enqueue(endpoint, payload) {
        const queue = getQueue();
        queue.push({ endpoint: endpoint, payload: payload, queued_at: new Date().toISOString() });
        setQueue(queue);
        syncQueue();
    }

    // Tasks started offline that haven't synced (and aren't completed offline
    // either) - shown in the in-progress list alongside server tasks
    function pendingTasks() {
        const queue = getQueue();
        const completedUuids = new Set(queue
            .filter(i => i.endpoint === 'complete-task.php' && i.payload.task_uuid)
            .map(i => i.payload.task_uuid));
        return queue
            .filter(i => i.endpoint === 'create-task.php' && !completedUuids.has(i.payload.client_uuid))
            .map(i => ({
                uuid: i.payload.client_uuid,
                job_name: i.payload.job_name || 'Job',
                start_time: i.payload.start_time
            }));
    }

    // Whether this offline-created task is still waiting to sync
    function hasPendingCreate(taskUuid) {
        return getQueue().some(i => i.endpoint === 'create-task.php' && i.payload.client_uuid === taskUuid);
    }

    // Server task ids with a completion waiting in the queue - hidden from
    // the in-progress list so they don't look completable twice
    function completedTaskIds() {
        return new Set(getQueue()
            .filter(i => i.endpoint === 'complete-task.php' && i.payload.task_id)
            .map(i => String(i.payload.task_id)));
    }

    // Status banner at the top of <main> while offline or with pending syncs
    function updateBanner() {
        let banner = document.getElementById('hm-offline-banner');
        const pending = getQueue().length;
        const offline = !navigator.onLine;

        if (!offline && pending === 0) {
            if (banner) banner.remove();
            return;
        }

        if (!banner) {
            const main = document.querySelector('main');
            if (!main) return;
            banner = document.createElement('div');
            banner.id = 'hm-offline-banner';
            banner.style.cssText = 'background:#ff9800;color:#fff;text-align:center;' +
                'padding:10px 12px;font-size:14px;font-weight:bold;border-radius:4px;margin-bottom:12px;';
            main.insertBefore(banner, main.firstChild);
        }

        if (offline) {
            banner.textContent = pending > 0
                ? 'Offline — ' + pending + ' update' + (pending === 1 ? '' : 's') + ' saved, will sync when connected'
                : 'Offline — your work will be saved and synced later';
        } else {
            banner.textContent = 'Syncing ' + pending + ' saved update' + (pending === 1 ? '' : 's') + '…';
        }
    }

    window.addEventListener('online', function () {
        updateBanner();
        syncQueue();
    });
    window.addEventListener('offline', updateBanner);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') syncQueue();
    });
    document.addEventListener('DOMContentLoaded', function () {
        updateBanner();
        syncQueue();
    });

    window.hmOffline = {
        uuid: uuid,
        enqueue: enqueue,
        syncQueue: syncQueue,
        cacheSet: cacheSet,
        cacheGet: cacheGet,
        pendingTasks: pendingTasks,
        hasPendingCreate: hasPendingCreate,
        completedTaskIds: completedTaskIds,
        queueLength: function () { return getQueue().length; },
        updateBanner: updateBanner
    };
})();
