import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Clock, FileEdit } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    index as correctionsIndex,
    show as showCorrection,
} from '@/routes/organization/crew-movement-corrections';
import { CrewMovementCorrectionAgeBadge } from './components/crew-movement-correction-age-badge';
import type { CorrectionDecisionMode } from './components/crew-movement-correction-decision-dialog';
import { CrewMovementCorrectionDecisionDialog } from './components/crew-movement-correction-decision-dialog';
import { CrewMovementCorrectionRowActions } from './components/crew-movement-correction-row-actions';
import { CrewMovementCorrectionStatusBadge } from './components/crew-movement-correction-status-badge';
import type {
    CrewMovementCorrectionListItem,
    CrewMovementCorrectionsIndexProps,
} from './types';

type TabKey =
    | 'all'
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled'
    | 'my_requests';

const TABS: { key: TabKey; label: string; status: string; scope: string }[] = [
    { key: 'all', label: 'All', status: '', scope: '' },
    { key: 'pending', label: 'Pending', status: 'pending', scope: '' },
    { key: 'approved', label: 'Approved', status: 'approved', scope: '' },
    { key: 'rejected', label: 'Rejected', status: 'rejected', scope: '' },
    {
        key: 'cancelled',
        label: 'Cancelled',
        status: 'cancelled',
        scope: '',
    },
    {
        key: 'my_requests',
        label: 'My Requests',
        status: '',
        scope: 'my_requests',
    },
];

export function CrewMovementCorrectionsContent({
    corrections,
    pagination,
    status_counts,
    summary_counts,
    search: initialSearch,
    filters: initialFilters,
    can,
}: CrewMovementCorrectionsIndexProps): ReactElement {
    const { auth } = usePage().props as unknown as {
        auth?: { user?: { id: number } };
    };
    const currentUserId = auth?.user?.id ?? null;

    const list = useServerPaginationFilters({
        url: correctionsIndex.url(),
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });

    const [decisionMode, setDecisionMode] =
        useState<CorrectionDecisionMode | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [activeCorrection, setActiveCorrection] =
        useState<CrewMovementCorrectionListItem | null>(null);

    const activeTab: TabKey =
        initialFilters.scope === 'my_requests'
            ? 'my_requests'
            : ((initialFilters.status || 'all') as TabKey);

    const selectTab = (tab: TabKey): void => {
        const config = TABS.find((item) => item.key === tab);

        if (!config) {
            return;
        }

        list.applyFilters({
            status: config.status,
            scope: config.scope,
            age_status: '',
        });
    };

    const summaryCards = [
        {
            key: 'pending',
            label: 'Pending',
            count: summary_counts.pending,
            icon: Clock,
            iconColor: 'text-amber-500',
            iconBg: 'bg-amber-500/10 border-amber-500/20',
            accent: 'border-amber-500/20 hover:border-amber-500/30',
            select: () =>
                list.applyFilters({
                    status: 'pending',
                    scope: '',
                    age_status: '',
                }),
        },
        {
            key: 'needs_attention',
            label: 'Needs Attention',
            count: summary_counts.needs_attention,
            icon: CalendarClock,
            iconColor: 'text-orange-500',
            iconBg: 'bg-orange-500/10 border-orange-500/20',
            accent: 'border-orange-500/20 hover:border-orange-500/30',
            select: () =>
                list.applyFilters({
                    status: 'pending',
                    scope: '',
                    age_status: 'needs_attention',
                }),
        },
        {
            key: 'overdue',
            label: 'Overdue',
            count: summary_counts.overdue,
            icon: AlertTriangle,
            iconColor: 'text-red-500',
            iconBg: 'bg-red-500/10 border-red-500/20',
            accent: 'border-red-500/20 hover:border-red-500/30',
            select: () =>
                list.applyFilters({
                    status: 'pending',
                    scope: '',
                    age_status: 'overdue',
                }),
        },
        {
            key: 'my_requests',
            label: 'My Requests',
            count: summary_counts.my_requests,
            icon: FileEdit,
            iconColor: 'text-blue-500',
            iconBg: 'bg-blue-500/10 border-blue-500/20',
            accent: 'border-blue-500/20 hover:border-blue-500/30',
            select: () =>
                list.applyFilters({
                    status: '',
                    scope: 'my_requests',
                    age_status: '',
                }),
        },
    ];

    const openDecision = (
        correction: CrewMovementCorrectionListItem,
        mode: CorrectionDecisionMode,
    ): void => {
        setActiveCorrection(correction);
        setDecisionMode(mode);
        setDialogOpen(true);
    };

    return (
        <Main>
            <PageHeader
                title="Movement Corrections"
                description="Review and decide on crew movement correction requests."
            />

            <div className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {summaryCards.map((card) => {
                    const Icon = card.icon;

                    return (
                        <button
                            key={card.key}
                            type="button"
                            onClick={card.select}
                            className={cn(
                                'group relative overflow-hidden rounded-2xl border glass-card p-5 text-left transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg',
                                card.accent,
                            )}
                        >
                            <div className="flex items-start justify-between">
                                <div className="flex-1">
                                    <span className="text-xs font-semibold tracking-wider text-muted-foreground/80 uppercase">
                                        {card.label}
                                    </span>
                                    <div className="mt-2 text-3xl font-bold tracking-tight tabular-nums">
                                        {card.count}
                                    </div>
                                </div>
                                <div
                                    className={cn(
                                        'flex h-10 w-10 items-center justify-center rounded-xl border transition-transform duration-300 group-hover:scale-110',
                                        card.iconBg,
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'h-5 w-5',
                                            card.iconColor,
                                        )}
                                    />
                                </div>
                            </div>
                        </button>
                    );
                })}
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-2">
                {TABS.map((tab) => (
                    <Button
                        key={tab.key}
                        type="button"
                        size="sm"
                        variant={activeTab === tab.key ? 'secondary' : 'ghost'}
                        onClick={() => selectTab(tab.key)}
                        className={cn(
                            'rounded-xl transition-all duration-200',
                            activeTab === tab.key &&
                                'shadow-sm ring-2 ring-primary/10',
                        )}
                    >
                        {tab.label}
                        <span
                            className={cn(
                                'ml-1.5 rounded-md px-1.5 py-0.5 text-xs font-semibold transition-colors',
                                activeTab === tab.key
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {status_counts[tab.key]}
                        </span>
                    </Button>
                ))}
            </div>

            <div className="mb-6 flex flex-col gap-3 rounded-2xl border glass-card border-border/60 bg-muted/20 p-4 lg:flex-row lg:items-center">
                <div className="min-w-0 flex-1">
                    <SearchBar
                        placeholder="Search assignment number or employee..."
                        value={list.searchInput}
                        onChange={list.onSearchChange}
                    />
                </div>
                <Select
                    value={initialFilters.age_status || 'all'}
                    onValueChange={(value) =>
                        list.applyFilters({
                            status:
                                value === 'all'
                                    ? initialFilters.status
                                    : 'pending',
                            scope: value === 'all' ? initialFilters.scope : '',
                            age_status: value === 'all' ? '' : value,
                        })
                    }
                >
                    <SelectTrigger
                        aria-label="Request Status"
                        className="w-full rounded-xl lg:w-52"
                    >
                        <SelectValue placeholder="Request Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            All Request Statuses
                        </SelectItem>
                        <SelectItem value="on_time">On Time</SelectItem>
                        <SelectItem value="needs_attention">
                            Needs Attention
                        </SelectItem>
                        <SelectItem value="overdue">Overdue</SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    type="button"
                    variant={
                        initialFilters.age_status === 'overdue'
                            ? 'destructive'
                            : 'outline'
                    }
                    className="rounded-xl"
                    onClick={() =>
                        list.applyFilters({
                            status: 'pending',
                            scope: '',
                            age_status:
                                initialFilters.age_status === 'overdue'
                                    ? ''
                                    : 'overdue',
                        })
                    }
                >
                    <AlertTriangle className="mr-2 h-4 w-4" />
                    Overdue only
                </Button>
            </div>

            {corrections.length === 0 ? (
                <EmptyState
                    title="No correction requests found"
                    description="Try adjusting your search or filters."
                />
            ) : (
                <div className="rounded-2xl border border-border/60 bg-card/50 backdrop-blur-sm">
                    <OrganizationDataTable minWidth="min-w-[1280px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Assignment</DataTableHead>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Phase</DataTableHead>
                                <DataTableHead>Fields</DataTableHead>
                                <DataTableHead>Requested by</DataTableHead>
                                <DataTableHead>Requested at</DataTableHead>
                                <DataTableHead>Pending Age</DataTableHead>
                                <DataTableHead>Request Status</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {corrections.map((correction) => {
                                const canCancelRow =
                                    correction.status === 'pending' &&
                                    (can.override ||
                                        (currentUserId !== null &&
                                            correction.requester?.id ===
                                                currentUserId));

                                return (
                                    <TableRow
                                        key={correction.id}
                                        className={cn(
                                            dataTableBodyRowClass(),
                                            'transition-colors hover:bg-muted/30',
                                        )}
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            <Link
                                                className="font-semibold text-primary transition-colors hover:text-primary/80 hover:underline"
                                                href={showCorrection.url(
                                                    correction.id,
                                                )}
                                            >
                                                {correction.assignment
                                                    ?.assignment_no ?? '—'}
                                            </Link>
                                            {correction.has_conflict ? (
                                                <span className="ml-2 inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                                    <AlertTriangle className="h-3 w-3" />
                                                    Conflict
                                                </span>
                                            ) : null}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'font-medium',
                                            )}
                                        >
                                            {correction.assignment?.employee
                                                ?.name ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {correction.phase ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <span className="font-mono text-xs font-bold text-muted-foreground">
                                                        {correction.phase.phase_code.toUpperCase()}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        ·
                                                    </span>
                                                    <span>
                                                        {
                                                            correction.phase
                                                                .phase_label
                                                        }
                                                    </span>
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-muted px-2.5 py-1 text-xs font-semibold">
                                                <FileEdit className="h-3 w-3" />
                                                {correction.field_count}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {correction.requester?.name ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-muted-foreground',
                                            )}
                                        >
                                            {formatDisplayDateTime12h(
                                                correction.requested_at,
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {correction.pending_age_label ? (
                                                <span className="flex items-center gap-1.5 text-sm">
                                                    <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                                    {
                                                        correction.pending_age_label
                                                    }
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {correction.age_status ===
                                                'not_applicable' ||
                                            !correction.age_status_label ? (
                                                '—'
                                            ) : (
                                                <CrewMovementCorrectionAgeBadge
                                                    status={
                                                        correction.age_status
                                                    }
                                                    label={
                                                        correction.age_status_label
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <CrewMovementCorrectionStatusBadge
                                                status={correction.status}
                                                label={correction.status_label}
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={dataTableActionsCellClass()}
                                        >
                                            <CrewMovementCorrectionRowActions
                                                correction={correction}
                                                canApprove={can.approve}
                                                canCancel={canCancelRow}
                                                onApprove={(item) =>
                                                    openDecision(
                                                        item,
                                                        'approve',
                                                    )
                                                }
                                                onReject={(item) =>
                                                    openDecision(item, 'reject')
                                                }
                                                onCancel={(item) =>
                                                    openDecision(item, 'cancel')
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </OrganizationDataTable>
                </div>
            )}

            <Pagination {...list.paginationProps} label="corrections" />

            <CrewMovementCorrectionDecisionDialog
                mode={decisionMode}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                correctionId={activeCorrection?.id ?? null}
                onSuccess={() => setActiveCorrection(null)}
            />
        </Main>
    );
}
