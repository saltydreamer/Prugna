import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { http } from '@/utils/http';

/**
 * Installable app: service worker, "install" prompt, push notifications on this device.
 */
export const pwa = reactive({
    /** The browser can install the app (Android/Chrome "beforeinstallprompt"). */
    installable: false,
    installed: typeof window !== 'undefined' && window.matchMedia?.('(display-mode: standalone)').matches,
});

let installEvent = null;

export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => null);
    });

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent = event;
        pwa.installable = true;
    });

    window.addEventListener('appinstalled', () => {
        installEvent = null;
        pwa.installable = false;
        pwa.installed = true;
    });
}

export async function installApp() {
    if (!installEvent) {
        return false;
    }

    installEvent.prompt();
    const { outcome } = await installEvent.userChoice;
    installEvent = null;
    pwa.installable = false;

    return outcome === 'accepted';
}

/**
 * Sends a message to the active service worker ("warm": refresh the offline pages, "clear": forget them).
 */
export async function tellServiceWorker(message) {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration();
    registration?.active?.postMessage(message);
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function currentPushSubscription() {
    if (!pushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.getRegistration();

    return (await registration?.pushManager.getSubscription()) ?? null;
}

function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

/**
 * Asks the permission, subscribes this device and registers it on the server.
 */
export async function enablePush() {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error(
            permission === 'denied'
                ? 'Le notifiche sono bloccate per questo sito: riattivale dalle impostazioni del browser.'
                : 'Permesso non concesso.',
        );
    }

    const registration = await navigator.serviceWorker.ready;
    const { public_key: publicKey } = await http.get(route('profile.push.key'));
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) }));
    const json = subscription.toJSON();
    const encoding = (window.PushManager.supportedContentEncodings ?? ['aes128gcm'])[0];

    return http.post(route('profile.push.store'), { endpoint: json.endpoint, keys: json.keys, content_encoding: encoding });
}

export async function disablePush() {
    const subscription = await currentPushSubscription();

    if (!subscription) {
        return null;
    }

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();

    return http.delete(route('profile.push.destroy'), { endpoint });
}
