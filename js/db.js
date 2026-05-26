// ============================================================
// DB.JS - IndexedDB Manager (stockage local hors ligne)
// ============================================================

const DB_NAME = 'CacaoCollectorDB';
const DB_VERSION = 1;

class LocalDB {
    constructor() {
        this.db = null;
    }

    async open() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                // Pending sync queue
                if (!db.objectStoreNames.contains('pendingSync')) {
                    const store = db.createObjectStore('pendingSync', { keyPath: 'localId' });
                    store.createIndex('type', 'type');
                    store.createIndex('timestamp', 'timestamp');
                }
                // Local fiches profilage
                if (!db.objectStoreNames.contains('ficheProfilage')) {
                    const s = db.createObjectStore('ficheProfilage', { keyPath: 'localId' });
                    s.createIndex('statut', 'statut');
                    s.createIndex('producteur_id', 'producteur_id');
                }
                // Local fiches arbres
                if (!db.objectStoreNames.contains('ficheArbres')) {
                    db.createObjectStore('ficheArbres', { keyPath: 'localId' });
                }
                // Local fiches engrais
                if (!db.objectStoreNames.contains('ficheEngrais')) {
                    db.createObjectStore('ficheEngrais', { keyPath: 'localId' });
                }
                // Cache producteurs list
                if (!db.objectStoreNames.contains('producteurs')) {
                    const s = db.createObjectStore('producteurs', { keyPath: 'id' });
                    s.createIndex('code', 'code');
                }
                // Cache cooperatives
                if (!db.objectStoreNames.contains('cooperatives')) {
                    db.createObjectStore('cooperatives', { keyPath: 'id' });
                }
                // User session cache
                if (!db.objectStoreNames.contains('session')) {
                    db.createObjectStore('session', { keyPath: 'key' });
                }
            };
            req.onsuccess = (e) => { this.db = e.target.result; resolve(this); };
            req.onerror = () => reject(req.error);
        });
    }

    async put(storeName, data) {
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(storeName, 'readwrite');
            const req = tx.objectStore(storeName).put(data);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async get(storeName, key) {
        return new Promise((resolve) => {
            try {
                const tx = this.db.transaction(storeName, 'readonly');
                const req = tx.objectStore(storeName).get(key);
                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => resolve(null);
            } catch(e) {
                resolve(null);
            }
        });
    }

    async getAll(storeName, indexName, value) {
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(storeName, 'readonly');
            const store = tx.objectStore(storeName);
            const req = indexName ? store.index(indexName).getAll(value) : store.getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async delete(storeName, key) {
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(storeName, 'readwrite');
            const req = tx.objectStore(storeName).delete(key);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    async clear(storeName) {
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(storeName, 'readwrite');
            const req = tx.objectStore(storeName).clear();
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    // Fiche Profilage
    async saveFicheProfilage(data) {
        const localId = data.localId || 'fp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 6);
        data.localId = localId;
        data._savedAt = new Date().toISOString();
        await this.put('ficheProfilage', data);
        // Also add to pending sync
        await this.put('pendingSync', {
            localId: 'sync-' + localId,
            type: 'ficheProfilage',
            endpoint: '/api/fiches-profilage',
            method: 'POST',
            data: data,
            timestamp: Date.now(),
            status: 'pending'
        });
        return localId;
    }

    async saveFicheArbres(data) {
        const localId = data.localId || 'fa-' + Date.now() + '-' + Math.random().toString(36).substr(2, 6);
        data.localId = localId;
        data._savedAt = new Date().toISOString();
        await this.put('ficheArbres', data);
        await this.put('pendingSync', {
            localId: 'sync-' + localId,
            type: 'ficheArbres',
            endpoint: '/api/fiches-arbres',
            method: 'POST',
            data: data,
            timestamp: Date.now(),
            status: 'pending'
        });
        return localId;
    }

    async saveFicheEngrais(data) {
        const localId = data.localId || 'fe-' + Date.now() + '-' + Math.random().toString(36).substr(2, 6);
        data.localId = localId;
        data._savedAt = new Date().toISOString();
        await this.put('ficheEngrais', data);
        await this.put('pendingSync', {
            localId: 'sync-' + localId,
            type: 'ficheEngrais',
            endpoint: '/api/fiches-engrais',
            method: 'POST',
            data: data,
            timestamp: Date.now(),
            status: 'pending'
        });
        return localId;
    }

    async getPendingCount() {
        const all = await this.getAll('pendingSync');
        return all.filter(i => i.status === 'pending').length;
    }

    async getPending() {
        const all = await this.getAll('pendingSync');
        return all.filter(i => i.status === 'pending').sort((a, b) => a.timestamp - b.timestamp);
    }

    // Cache producteurs from server
    async cacheProducteurs(list) {
        await this.clear('producteurs');
        for (const p of list) await this.put('producteurs', p);
    }

    async cacheCooperatives(list) {
        await this.clear('cooperatives');
        for (const c of list) await this.put('cooperatives', c);
    }

    // Queue any request for offline sync
    async queueRequest(endpoint, method, data) {
        const localId = 'req-' + Date.now() + '-' + Math.random().toString(36).substr(2,6);
        await this.put('pendingSync', {
            localId, type: 'request',
            endpoint, method, data,
            timestamp: Date.now(), status: 'pending'
        });
        return localId;
    }

    async saveSession(user) {
        await this.put('session', { key: 'user', value: user });
    }

    async getSession() {
        const s = await this.get('session', 'user');
        return s ? s.value : null;
    }

    async clearSession() {
        await this.delete('session', 'user');
    }
}

window.localDB = new LocalDB();
