/**
 * Service worker of the installable app (served by PwaController at /sw.js).
 *
 * - Built files (scripts, styles, fonts) are cached at install: the app opens without connection.
 * - Pages listed in OFFLINE_PAGES ("Interventi sul campo") are kept as last loaded, HTML and Inertia
 *   JSON, so they work offline; other pages show offline.html when there is no connection.
 * - Push notifications from the server are shown here; a tap opens the related page.
 */
const VERSION = '__VERSION__';
const ASSETS = __ASSETS__;
const ASSET_CACHE = `assets-${VERSION}`;
const PAGE_CACHE = 'pages-v1';
const STATIC_FILES = ['/offline.html', '/icons/icon-192.png', '/icons/badge-96.png'];
const OFFLINE_PAGES = ['/field'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(ASSET_CACHE);
            // One file failing (e.g. a deploy in progress) must not block the installation.
            await Promise.all([...STATIC_FILES, ...ASSETS].map((url) => cache.add(url).catch(() => null)));
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // The offline pages reference the new build: refresh the ones in use before dropping the old files
            // (kept only while offline, when the cached pages still need them).
            const result = await warmOfflinePages(true);
            const names = await caches.keys();

            if (result !== 'offline') {
                await Promise.all(names.filter((name) => name.startsWith('assets-') && name !== ASSET_CACHE).map((name) => caches.delete(name)));
            }

            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'warm') {
        event.waitUntil(warmOfflinePages());
    }

    // Logout: nothing of the user stays on the device.
    if (event.data === 'clear') {
        event.waitUntil(caches.delete(PAGE_CACHE));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname === '/offline.html') {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (OFFLINE_PAGES.includes(url.pathname)) {
        event.respondWith(offlinePage(request, url));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => (await caches.match('/offline.html')) ?? Response.error()));
    }
});

self.addEventListener('push', (event) => {
    let message;

    try {
        message = event.data ? event.data.json() : {};
    } catch {
        message = { title: event.data?.text() ?? '' };
    }

    event.waitUntil(
        self.registration.showNotification(message.title || 'Notifica', {
            body: message.body ?? '',
            icon: message.icon ?? '/icons/icon-192.png',
            badge: message.badge ?? '/icons/badge-96.png',
            tag: message.tag,
            data: message.data ?? {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url ?? '/notifications', self.location.origin).href;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (open) {
                await open.focus();

                return open.navigate(target);
            }

            return self.clients.openWindow(target);
        })(),
    );
});

async function cacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        const cache = await caches.open(ASSET_CACHE);
        cache.put(request, response.clone());
    }

    return response;
}

/**
 * Network first; the last good copy (HTML or Inertia JSON, kept apart) when offline.
 */
async function offlinePage(request, url) {
    const isInertia = request.headers.get('X-Inertia') === 'true';
    const key = isInertia ? `${url.pathname}?__inertia` : url.pathname;
    const cache = await caches.open(PAGE_CACHE);

    try {
        const response = await fetch(request);

        if (isCacheable(response, url.pathname)) {
            await cache.put(key, response.clone());
        }

        return response;
    } catch {
        const cached = await cache.match(key);

        if (cached) {
            return cached;
        }

        return isInertia ? Response.error() : ((await caches.match('/offline.html')) ?? Response.error());
    }
}

/**
 * Only the real page: not a redirect to the login, not an error.
 */
function isCacheable(response, pathname) {
    return response.ok && !response.redirected && new URL(response.url).pathname === pathname;
}

/**
 * @param {boolean} onlyCached Refresh only pages already kept (the user used them).
 * @returns {Promise<'ok'|'unavailable'|'offline'>} "unavailable": not logged in or not allowed.
 */
async function warmOfflinePages(onlyCached = false) {
    try {
        const cache = await caches.open(PAGE_CACHE);

        for (const pathname of OFFLINE_PAGES) {
            if (onlyCached && !(await cache.match(pathname))) {
                continue;
            }

            // The header tells the server not to treat it as a visit (no login redirect, no "intended" page).
            const response = await fetch(pathname, { credentials: 'include', headers: { 'X-SW-Warm': '1' } });

            if (!isCacheable(response, pathname)) {
                return 'unavailable';
            }

            await cache.put(pathname, response);
        }

        return 'ok';
    } catch {
        return 'offline';
    }
}
