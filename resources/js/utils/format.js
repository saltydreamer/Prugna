const locale = document.documentElement.lang || 'it';

export function formatDate(value, options = { dateStyle: 'medium' }) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, options).format(new Date(value));
}

export function formatDateTime(value) {
    return formatDate(value, { dateStyle: 'medium', timeStyle: 'short' });
}

export function relativeTime(value) {
    if (!value) {
        return '—';
    }

    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const units = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['week', 60 * 60 * 24 * 7],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
    ];
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(seconds, 'second');
}

/**
 * True when the URL belongs to this application (and can be visited with Inertia).
 */
export function isInternalUrl(url) {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
}
