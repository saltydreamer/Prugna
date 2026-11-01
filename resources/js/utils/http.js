/**
 * Minimal JSON client for the few endpoints that are not Inertia pages
 * (e.g. Fortify's two factor QR code / recovery codes).
 * For regular navigation and forms use Inertia's router and useForm.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(response, data) {
        super(data?.message ?? response.statusText);
        this.status = response.status;
        this.errors = data?.errors ?? {};
    }
}

export async function request(method, url, data = undefined) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    const body = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        throw new HttpError(response, body);
    }

    return body;
}

export const http = {
    get: (url) => request('GET', url),
    post: (url, data = {}) => request('POST', url, data),
    put: (url, data = {}) => request('PUT', url, data),
    delete: (url, data = undefined) => request('DELETE', url, data),
};
