/**
 * Bytes as B, KB, MB, GB or TB (powers of 1024), one decimal below 10 of a unit. Null (not
 * measured yet) is shown as a dash.
 */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

/**
 * A date and time in the viewer's locale and time zone, with the zone named.
 */
export const formatDateTime = (iso: string): string =>
    new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    });

/**
 * How long ago (or from now) iso is, in the viewer's language: "3 minutes ago", "in 2 hours".
 */
export const formatRelative = (iso: string): string => {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
};
