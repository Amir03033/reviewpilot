const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function dateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function euro(cents: number): string {
    return new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
}

export function pct(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

export function num(value: number | null | undefined, digits = 1): string {
    return value === null || value === undefined ? '—' : value.toFixed(digits);
}

export function humanize(value: string): string {
    return value.replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}
