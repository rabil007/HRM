export type ImportPreviewRowFilter =
    | 'all'
    | 'updates'
    | 'creates'
    | 'deletes'
    | 'errors'
    | 'importable'
    | 'skipped'
    | 'valid'
    | 'invalid';

export type ImportPreviewFilterableRow = {
    action?: 'create' | 'update' | 'skip' | 'delete';
    errors?: readonly unknown[] | null;
};

export function rowMatchesImportPreviewFilter(
    row: ImportPreviewFilterableRow,
    filter: ImportPreviewRowFilter,
): boolean {
    const errorCount = row.errors?.length ?? 0;
    const hasErrors = errorCount > 0;

    switch (filter) {
        case 'all':
            return true;
        case 'updates':
            return row.action === 'update';
        case 'creates':
            return row.action === 'create';
        case 'deletes':
            return row.action === 'delete';
        case 'errors':
        case 'invalid':
            return hasErrors;
        case 'valid':
            return !hasErrors;
        case 'skipped':
            return row.action === 'skip';
        case 'importable':
            return (
                (row.action === 'create' || row.action === 'update') &&
                !hasErrors
            );
        default:
            return true;
    }
}

export function toggleImportPreviewFilter(
    current: ImportPreviewRowFilter,
    next: ImportPreviewRowFilter,
): ImportPreviewRowFilter {
    if (next === 'all') {
        return 'all';
    }

    return current === next ? 'all' : next;
}

export function importPreviewEmptyRowsMessage(
    filter: ImportPreviewRowFilter,
    searchQuery: string,
): string {
    if (filter !== 'all' || searchQuery.trim() !== '') {
        return 'No rows match your filters.';
    }

    return 'No rows found.';
}

export type ImportPreviewFilterBadgeItem = {
    key: ImportPreviewRowFilter;
    count: number;
    label: string;
    variant?: 'default' | 'secondary' | 'outline' | 'destructive';
    className?: string;
    hideWhenZero?: boolean;
};

export function importablePreviewBadges(summary: {
    total: number;
    importable: number;
    skipped: number;
    invalid: number;
}): ImportPreviewFilterBadgeItem[] {
    return [
        {
            key: 'all',
            count: summary.total,
            label: 'rows',
            variant: 'secondary',
        },
        {
            key: 'importable',
            count: summary.importable,
            label: 'importable',
            variant: 'default',
        },
        {
            key: 'skipped',
            count: summary.skipped,
            label: 'skipped',
            variant: 'outline',
            hideWhenZero: true,
        },
        {
            key: 'invalid',
            count: summary.invalid,
            label: 'invalid',
            variant: 'destructive',
            hideWhenZero: true,
        },
    ];
}

export function validInvalidPreviewBadges(summary: {
    total: number;
    valid: number;
    invalid: number;
}): ImportPreviewFilterBadgeItem[] {
    return [
        {
            key: 'all',
            count: summary.total,
            label: 'rows',
            variant: 'secondary',
        },
        {
            key: 'valid',
            count: summary.valid,
            label: 'valid',
            variant: 'default',
        },
        {
            key: 'invalid',
            count: summary.invalid,
            label: 'invalid',
            variant: 'destructive',
            hideWhenZero: true,
        },
    ];
}
