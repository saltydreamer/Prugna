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

const moneyFormatters = {};

/**
 * "€ 1.234,50" — up to 4 decimals only when needed (unit prices such as 0,1250).
 */
export function formatMoney(value, { currency = 'EUR' } = {}) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    const decimals = Math.round(number * 100) === number * 100 ? 2 : 4;
    const key = `${currency}-${decimals}`;

    moneyFormatters[key] ??= new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: decimals,
    });

    return moneyFormatters[key].format(number);
}

export function formatPercent(value, decimals = 1) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: decimals }).format(value)}%`;
}
