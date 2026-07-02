import { cn } from '@/lib/utils';

/** Canonical spacing scale (matches CSS --ds-space-*). */
export const spacing = {
    xs: 'gap-1',
    sm: 'gap-2',
    md: 'gap-4',
    lg: 'gap-6',
    xl: 'gap-8',
} as const;

/** Control heights — use on inputs, selects, default buttons. */
export const controlHeight = {
    sm: 'h-8',
    md: 'h-9',
    lg: 'h-10',
} as const;

export const surfaces = {
    panel: 'ds-panel',
    panelHeader: 'ds-panel-header',
    panelTitle: 'ds-panel-title',
    panelBadge: 'ds-panel-badge',
    glassCard: 'glass-card',
    elevated: 'rounded-xl border border-border/80 bg-card shadow-sm',
} as const;

export const tables = {
    headRow: 'ds-table-head-row',
    th: 'ds-table-th',
    row: 'ds-table-row',
    td: 'ds-table-td',
    tdPrimary: 'ds-table-td-primary',
    headRowLegacy: 'border-b border-border/80 bg-muted/30 hover:bg-muted/30',
    headCell:
        'h-10 px-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap',
    bodyRow:
        'border-b border-border/50 transition-colors duration-150 hover:bg-muted/30',
    cell: 'px-4 py-4 align-middle text-sm text-muted-foreground',
    cellPrimary: 'px-4 py-4 align-middle text-sm font-medium text-foreground',
    actionsCell: 'px-4 py-3.5 align-middle text-right last:pr-5',
} as const;

export const tabs = {
    list: 'ds-tab-list',
    trigger: 'ds-tab-trigger',
} as const;

export const typography = {
    pageTitle: 'text-2xl font-semibold tracking-tight text-foreground',
    sectionTitle: 'text-lg font-semibold tracking-tight text-foreground',
    label: 'text-xs font-semibold uppercase tracking-wider text-muted-foreground',
    sectionLabel:
        'text-[10px] font-semibold uppercase tracking-widest text-muted-foreground',
    muted: 'text-sm text-muted-foreground',
    hint: 'text-[11px] text-muted-foreground',
    tableMuted: 'text-xs text-muted-foreground',
} as const;

/** Shared dialog / form action styles — use with Button or AlertDialogAction. */
export const actions = {
    dialogSecondary:
        'border-border bg-muted/50 text-muted-foreground hover:bg-accent hover:text-foreground',
    dialogPrimary: 'bg-primary text-primary-foreground hover:bg-primary/90',
    formSectionLabel: typography.sectionLabel,
    formHint: typography.hint,
    formLabel: 'text-sm text-foreground',
} as const;

export const radius = {
    control: 'rounded-xl',
    surface: 'rounded-2xl',
    modal: 'rounded-2xl',
} as const;

export function pageStack(className?: string): string {
    return cn('ds-page-stack', className);
}

export function sectionStack(className?: string): string {
    return cn('ds-section-stack', className);
}
