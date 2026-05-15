export function formatIsoDateDisplay(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const parts = iso.split('-');

    if (parts.length !== 3) {
        return iso;
    }

    const y = Number(parts[0]);
    const m = Number(parts[1]);
    const d = Number(parts[2]);

    if (!y || !m || !d) {
        return iso;
    }

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
