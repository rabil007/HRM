import { usePage } from '@inertiajs/react';
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

        list.applyFilters({ status: config.status, scope: config.scope });
    };

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

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                {TABS.map((tab) => {
                    const isActive = activeTab === tab.key;
                    const count = status_counts[tab.key];

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => selectTab(tab.key)}
                            aria-pressed={isActive}
                            className={cn(
                                'group relative overflow-hidden rounded-2xl border glass-card p-4 text-left transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md',
                                isActive
                                    ? 'border-primary/30 bg-primary/5 text-primary ring-1 ring-primary/20'
                                    : 'border-border/60 bg-card/80 hover:border-border hover:bg-card dark:hover:border-white/10',
                            )}
                        >
                            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase group-hover:text-foreground">
                                {tab.label}
                            </span>
                            <div className="mt-2 text-2xl font-extrabold text-foreground tabular-nums">
                                {count}
                            </div>
                        </button>
                    );
                })}
            </div>

            <SearchBar
                placeholder="Search assignment number or employee..."
                value={list.searchInput}
                onChange={list.onSearchChange}
            />

            {corrections.length === 0 ? (
                <EmptyState
                    title="No correction requests found"
                    description="Try adjusting your search or filters."
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[1160px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Assignment</DataTableHead>
                            <DataTableHead>Employee</DataTableHead>
                            <DataTableHead>Phase</DataTableHead>
                            <DataTableHead>Fields</DataTableHead>
                            <DataTableHead>Requested by</DataTableHead>
                            <DataTableHead>Requested at</DataTableHead>
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
                                    className={dataTableBodyRowClass()}
                                >
                                    <TableCell
                                        className={dataTableCellPrimaryClass()}
                                    >
                                        <a
                                            className="text-primary hover:underline"
                                            href={showCorrection.url(
                                                correction.id,
                                            )}
                                        >
                                            {correction.assignment
                                                ?.assignment_no ?? '—'}
                                        </a>
                                        {correction.has_conflict ? (
                                            <span className="ml-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                                                Conflict
                                            </span>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {correction.assignment?.employee
                                            ?.name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {correction.phase
                                            ? `${correction.phase.phase_code.toUpperCase()} · ${correction.phase.phase_label}`
                                            : '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {correction.field_count}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {correction.requester?.name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatDisplayDateTime12h(
                                            correction.requested_at,
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
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
                                                openDecision(item, 'approve')
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
