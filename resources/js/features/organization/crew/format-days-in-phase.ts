export function formatDaysInPhase(days: number | null): string {
    if (days === null) {
        return '';
    }

    if (days === 0) {
        return 'Started today';
    }

    if (days === 1) {
        return '1 day in phase';
    }

    return `${days} days in phase`;
}

export function formatDaysOnboard(days: number | null): string {
    if (days === null) {
        return '';
    }

    if (days === 0) {
        return 'Joined today';
    }

    if (days === 1) {
        return '1 day onboard';
    }

    return `${days} days onboard`;
}

export function formatDaysInTraining(days: number | null): string {
    if (days === null) {
        return '';
    }

    if (days === 0) {
        return 'Started today';
    }

    if (days === 1) {
        return '1 day in training';
    }

    return `${days} days in training`;
}
