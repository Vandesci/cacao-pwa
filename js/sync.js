// ============================================================
// SYNC.JS - Gestionnaire de synchronisation offline → online
// ============================================================

class SyncManager {
    constructor() {
        this.isSyncing = false;
        this.listeners = [];
        // Listen for SW messages
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (e) => {
                if (e.data.type === 'TRIGGER_SYNC') this.sync();
                if (e.data.type === 'QUEUE_OFFLINE') this.handleOfflineQueue(e.data.request);
            });
        }
        // Auto-sync when back online
        window.addEventListener('online', () => {
            this.notify('online');
            setTimeout(() => this.sync(), 1000);
        });
        window.addEventListener('offline', () => this.notify('offline'));
    }

    isOnline() { return navigator.onLine; }

    on(event, callback) { this.listeners.push({ event, callback }); }
    notify(event, data) { this.listeners.filter(l => l.event === event).forEach(l => l.callback(data)); }

    async sync() {
        if (this.isSyncing || !this.isOnline()) return;
        this.isSyncing = true;
        this.notify('sync-start');
        try {
            const pending = await localDB.getPending();
            let synced = 0, errors = 0;
            for (const item of pending) {
                try {
                    const res = await fetch(item.endpoint, {
                        method: item.method,
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(item.data)
                    });
                    if (res.ok) {
                        item.status = 'synced';
                        item.syncedAt = new Date().toISOString();
                        await localDB.put('pendingSync', item);
                        synced++;
                    } else {
                        errors++;
                    }
                } catch { errors++; }
            }
            this.notify('sync-done', { synced, errors, total: pending.length });
        } finally {
            this.isSyncing = false;
        }
    }

    async getPendingCount() {
        return await localDB.getPendingCount();
    }
}

window.syncMgr = new SyncManager();
