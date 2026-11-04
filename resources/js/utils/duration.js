/**
 * "1 h 30 min", "45 min", "2 h".
 */
export function formatMinutes(minutes) {
    const value = Math.max(0, Math.round(Number(minutes) || 0));
    const hours = Math.floor(value / 60);
    const rest = value % 60;

    if (!hours) {
        return `${rest} min`;
    }

    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}

/**
 * Decimal hours with a comma: 1,5 h.
 */
export function formatHours(minutes) {
    return `${(Math.round((Number(minutes) / 60) * 100) / 100).toLocaleString('it-IT')} h`;
}

/**
 * Elapsed time of a running timer: "1:05:09".
 */
export function formatElapsed(fromIso, now = Date.now()) {
    const seconds = Math.max(0, Math.floor((now - new Date(fromIso).getTime()) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    return `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

/**
 * Minutes rounded up to the billing block (as on the server).
 */
export function roundUp(minutes, block) {
    const value = Number(minutes) || 0;

    return value <= 0 ? 0 : Math.ceil(value / block) * block;
}

export const ROUNDING_LABELS = { 1: 'al minuto', 15: "al quarto d'ora", 30: "alla mezz'ora", 60: "all'ora" };
