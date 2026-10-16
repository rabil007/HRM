export function keyOperationalDateKindLabel(kind: string): string {
    if (kind === 'planned') {
        return 'Planned';
    }

    if (kind === 'recorded') {
        return 'Recorded';
    }

    return 'Actual';
}

export function keyOperationalDateDetail(kind: string): string {
    if (kind === 'planned') {
        return 'Planned date. This is not an actual movement.';
    }

    if (kind === 'recorded') {
        return 'Administrative record. This is not an actual crew movement.';
    }

    return 'Recorded operational date.';
}

export function operatorAttentionBadgeVariant(
    tone: string,
): 'destructive' | 'warning' | 'info' | 'outline' {
    switch (tone) {
        case 'critical':
            return 'destructive';
        case 'warning':
            return 'warning';
        case 'info':
            return 'info';
        default:
            return 'outline';
    }
}
