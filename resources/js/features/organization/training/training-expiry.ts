export type TrainingExpiryFilter =
    | 'all'
    | 'valid'
    | 'expiring_30'
    | 'expiring_15'
    | 'expiring_7'
    | 'expired';

export const TRAINING_EXPIRY_FILTER_LABELS: Record<
    TrainingExpiryFilter,
    string
> = {
    all: 'All Training',
    valid: 'Valid',
    expiring_30: 'Within 30 Days',
    expiring_15: 'Within 15 Days',
    expiring_7: 'Within 7 Days',
    expired: 'Expired',
};

export const TRAINING_EXPIRY_STATUS_LABELS: Record<string, string> = {
    valid: 'Valid',
    expiring_30: 'Within 30 Days',
    expiring_15: 'Within 15 Days',
    expiring_7: 'Within 7 Days',
    expired: 'Expired',
};

export const TRAINING_EXPIRY_STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    valid: 'outline',
    expiring_30: 'secondary',
    expiring_15: 'secondary',
    expiring_7: 'destructive',
    expired: 'destructive',
};

export const TRAINING_EXPIRY_STATUS_CLASSES: Record<string, string> = {
    valid: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    expiring_30: 'bg-sky-500/10 text-sky-400 border-sky-500/20',
    expiring_15: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
    expiring_7: 'bg-orange-500/10 text-orange-400 border-orange-500/25',
    expired: 'bg-red-500/15 text-red-400 border-red-500/30',
    none: 'bg-muted/40 text-muted-foreground border-white/10',
};

export function trainingExpiryRemainingClass(
    status: string | null | undefined,
): string {
    if (!status) {
        return 'text-muted-foreground';
    }

    if (status === 'expired') {
        return 'text-red-400/90';
    }

    if (status === 'expiring_7') {
        return 'text-orange-400/90';
    }

    if (status === 'expiring_15') {
        return 'text-amber-400/90';
    }

    if (status === 'expiring_30') {
        return 'text-sky-400/90';
    }

    return 'text-muted-foreground';
}

export function trainingExpiryStatusLabel(
    status: string | null | undefined,
): string {
    if (!status) {
        return 'No Expiry';
    }

    return TRAINING_EXPIRY_STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}

export function trainingExpiryStatusClass(
    status: string | null | undefined,
): string {
    if (!status) {
        return TRAINING_EXPIRY_STATUS_CLASSES.none;
    }

    return (
        TRAINING_EXPIRY_STATUS_CLASSES[status] ??
        TRAINING_EXPIRY_STATUS_CLASSES.none
    );
}

export function trainingExpiryStatusVariant(
    status: string | null | undefined,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!status) {
        return 'outline';
    }

    return TRAINING_EXPIRY_STATUS_VARIANTS[status] ?? 'outline';
}
