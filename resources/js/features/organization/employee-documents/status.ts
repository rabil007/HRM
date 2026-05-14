export const DOCUMENT_STATUS_LABELS: Record<string, string> = {
    valid: 'Valid',
    expiring_soon: 'Expiring Soon',
    expired: 'Expired',
};

export const DOCUMENT_STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    valid: 'default',
    expiring_soon: 'secondary',
    expired: 'destructive',
};

export const DOCUMENT_STATUS_CLASSES: Record<string, string> = {
    valid: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    expiring_soon: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
    expired: 'bg-red-500/10 text-red-400 border-red-500/20',
};

export function documentStatusLabel(status: string | null | undefined): string {
    return DOCUMENT_STATUS_LABELS[status ?? ''] ?? (status ?? '—').replace('_', ' ');
}
