export function formatTimelineTime12h(time: string | null | undefined): string {
    if (time === null || time === undefined || time === '') {
        return '—';
    }

    const parts = time.split(':');
    const hours24 = parseInt(parts[0] ?? '', 10);
    const minutes = parseInt(parts[1] ?? '', 10);

    if (Number.isNaN(hours24) || Number.isNaN(minutes)) {
        return time;
    }

    const period = hours24 >= 12 ? 'PM' : 'AM';
    let hours12 = hours24 % 12;

    if (hours12 === 0) {
        hours12 = 12;
    }

    return `${hours12}:${String(minutes).padStart(2, '0')} ${period}`;
}
