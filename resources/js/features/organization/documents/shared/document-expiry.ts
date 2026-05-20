import type { DocumentExpiryStatus } from './types';

export type ExpiryFilter = 'all' | DocumentExpiryStatus;

export const EXPIRY_FILTER_LABELS: Record<ExpiryFilter, string> = {
    all: 'All Documents',
    valid: 'Valid',
    expiring_30: 'Expiring in 30 Days',
    expiring_15: 'Expiring in 15 Days',
    expiring_7: 'Expiring in 7 Days',
    expired: 'Expired',
};

export const EXPIRY_STATUS_LABELS: Record<string, string> = {
    valid: 'Valid',
    expiring_30: 'Expiring in 30 Days',
    expiring_15: 'Expiring in 15 Days',
    expiring_7: 'Expiring in 7 Days',
    expired: 'Expired',
};

export const EXPIRY_STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    valid: 'outline',
    expiring_30: 'secondary',
    expiring_15: 'secondary',
    expiring_7: 'destructive',
    expired: 'destructive',
};

export const EXPIRY_STATUS_CLASSES: Record<string, string> = {
    valid: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    expiring_30: 'bg-sky-500/10 text-sky-400 border-sky-500/20',
    expiring_15: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
    expiring_7: 'bg-orange-500/10 text-orange-400 border-orange-500/25',
    expired: 'bg-red-500/15 text-red-400 border-red-500/30',
    none: 'bg-muted/40 text-muted-foreground border-white/10',
};

export function expiryRemainingClass(status: string | null | undefined): string {
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

export function expiryStatusLabel(status: string | null | undefined): string {
    if (!status) {
        return 'No Expiry';
    }

    return EXPIRY_STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}

export function expiryStatusClass(status: string | null | undefined): string {
    if (!status) {
        return EXPIRY_STATUS_CLASSES.none;
    }

    return EXPIRY_STATUS_CLASSES[status] ?? EXPIRY_STATUS_CLASSES.none;
}

export function expiryStatusVariant(
    status: string | null | undefined,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!status) {
        return 'outline';
    }

    return EXPIRY_STATUS_VARIANTS[status] ?? 'outline';
}
