export function mapMovementErrorMessage(message: string): string {
    const normalized = message.trim();

    if (normalized === '') {
        return normalized;
    }

    if (/already has an active assignment/i.test(normalized)) {
        return 'Crew status changed — this employee already has an active assignment. Refresh the assignment before continuing.';
    }

    if (/invalid phase for action/i.test(normalized)) {
        return 'Crew status changed — the assignment phase no longer matches this action. Refresh the assignment before continuing.';
    }

    if (/phase has changed/i.test(normalized)) {
        return 'Crew status changed — refresh the assignment before continuing.';
    }

    return normalized;
}
