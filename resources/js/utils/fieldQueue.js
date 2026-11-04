import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { http } from '@/utils/http';

/**
 * Interventions recorded on the device ("Interventi sul campo"), kept in IndexedDB until the server has them.
 * Each one has a client_uuid: sending it twice never creates a duplicate.
 *
 * Item: { client_uuid, user_id, created_at, status: pending|invalid|synced, errors, data, summary, result, synced_at }
 */
const DATABASE = 'app-offline';
const STORE = 'interventions';
const KEEP_SYNCED_DAYS = 3;

export const fieldQueue = reactive({
    items: [],
    syncing: false,
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    /** The session expired: log in again to sync. */
    needsLogin: false,
    userId: null,
    get pending() {
        return this.items.filter((item) => item.status !== 'synced').length;
    },
});

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'client_uuid' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function transaction(mode, run) {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = database.transaction(STORE, mode);
        const result = run(tx.objectStore(STORE));
        tx.oncomplete = () => resolve(result?.result);
        tx.onerror = () => reject(tx.error);
    });
}

const allItems = () => transaction('readonly', (store) => store.getAll());
const putItem = (item) => transaction('readwrite', (store) => store.put(JSON.parse(JSON.stringify(item))));
const deleteItem = (id) => transaction('readwrite', (store) => store.delete(id));

export function newClientUuid() {
    if (crypto?.randomUUID) {
        return crypto.randomUUID();
    }

    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
        (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16),
    );
}

/**
 * Loads the items of the user (the device may be shared) and drops old synced ones.
 */
export async function loadQueue(userId) {
    if (typeof indexedDB === 'undefined' || !userId) {
        return;
    }

    fieldQueue.userId = userId;
    const limit = Date.now() - KEEP_SYNCED_DAYS * 86_400_000;
    const items = (await allItems()) ?? [];

    for (const item of items) {
        if (item.status === 'synced' && new Date(item.synced_at).getTime() < limit) {
            await deleteItem(item.client_uuid);
        }
    }

    fieldQueue.items = items
        .filter((item) => item.user_id === userId && !(item.status === 'synced' && new Date(item.synced_at).getTime() < limit))
        .sort((a, b) => b.created_at.localeCompare(a.created_at));
}

export async function saveToQueue(item) {
    const existing = fieldQueue.items.findIndex((other) => other.client_uuid === item.client_uuid);
    const stored = { ...item, user_id: fieldQueue.userId, status: 'pending', errors: null };

    await putItem(stored);

    if (existing >= 0) {
        fieldQueue.items.splice(existing, 1, stored);
    } else {
        fieldQueue.items.unshift(stored);
    }

    // Ask the browser not to clear what is waiting to be synced.
    navigator.storage?.persist?.().catch(() => null);
}

export async function removeFromQueue(clientUuid) {
    await deleteItem(clientUuid);
    fieldQueue.items = fieldQueue.items.filter((item) => item.client_uuid !== clientUuid);
}

/**
 * Sends the waiting interventions, one at a time. Offline it does nothing: it runs again when the connection returns.
 */
export async function syncQueue() {
    if (fieldQueue.syncing || !navigator.onLine) {
        return;
    }

    fieldQueue.syncing = true;
    fieldQueue.needsLogin = false;

    try {
        for (const item of fieldQueue.items.filter((entry) => entry.status === 'pending')) {
            try {
                const result = await http.post(route('field.sync'), item.data);
                Object.assign(item, { status: 'synced', result, synced_at: new Date().toISOString(), errors: null });
                await putItem(item);
            } catch (exception) {
                if (exception.status === 422) {
                    Object.assign(item, { status: 'invalid', errors: Object.values(exception.errors ?? {}).flat() });
                    await putItem(item);
                } else if (exception.status === 401 || exception.status === 419) {
                    fieldQueue.needsLogin = true;
                    break;
                } else {
                    // Connection lost or server unavailable: retry later.
                    break;
                }
            }
        }
    } finally {
        fieldQueue.syncing = false;
    }
}

let started = false;

/**
 * Called once by the layout: keeps the online flag and syncs on load, when the connection returns and every minute.
 */
export function startFieldSync(userId) {
    if (typeof window === 'undefined' || typeof indexedDB === 'undefined') {
        return;
    }

    loadQueue(userId).then(syncQueue);

    if (started) {
        return;
    }

    started = true;
    window.addEventListener('online', () => {
        fieldQueue.online = true;
        syncQueue();
    });
    window.addEventListener('offline', () => (fieldQueue.online = false));
    setInterval(() => fieldQueue.pending && syncQueue(), 60_000);
}
