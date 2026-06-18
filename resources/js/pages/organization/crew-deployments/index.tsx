import { Head, router } from '@inertiajs/react';
import { Download, Filter, Info, Plus, Search, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import {
    destroy as destroyDeployment,
    show as showDeployment,
} from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import {
    DEFAULT_DEPLOYMENT_SORT,
    DEFAULT_DEPLOYMENT_SORT_DIRECTION,
} from '@/features/organization/crew-deployments/crew-deployment-sort-options';
// import { CrewDeploymentsBoard } from '@/features/organization/crew-deployments/crew-deployments-board';
import { CrewDeploymentsSummaryCards } from '@/features/organization/crew-deployments/crew-deployments-summary-cards';
import { DeploymentDateCell } from '@/features/organization/crew-deployments/deployment-date-cell';
import { DeploymentFormDialog } from '@/features/organization/crew-deployments/deployment-form-dialog';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import { DeploymentStatusRulesDialog } from '@/features/organization/crew-deployments/deployment-status-rules-dialog';
import { EmployeeProfileLink } from '@/features/organization/crew-deployments/employee-profile-link';
import { SortableDeploymentTableHead } from '@/features/organization/crew-deployments/sortable-deployment-table-head';
import { deploymentHasWriteActions } from '@/features/organization/crew-deployments/types';
import type {
    DeploymentItem,
    DeploymentPagePermissions,
    DeploymentStatusRules,
    DeploymentSummary,
} from '@/features/organization/crew-deployments/types';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { actions } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { PaginationMeta } from '@/types/pagination';

type Option = { id: number; name: string };
type EmployeeOption = {
    id: number;
    employee_no: string;
    name: string;
    rank_id: number | null;
};

type Props = {
    deployments: PaginationMeta & { data: DeploymentItem[] };
    summary: DeploymentSummary;
    filters: {
        status: string | null;
        search: string | null;
        rank_id: number | null;
        client_id: number | null;
        company_visa_type_id: number | null;
        sort: string;
        direction: string;
        view: 'table' | 'board' | null;
    };
    employees: EmployeeOption[];
    ranks: Option[];
    clients: Option[];
    company_visa_types: Option[];
    vessels: Option[];
    can: DeploymentPagePermissions;
    status_rules: DeploymentStatusRules;
};

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function displayNumber(value: number | null | undefined): string {
    return value !== null && value !== undefined ? String(value) : '—';
}

const TABLE_COLUMN_COUNT = 20;

export default function CrewDeploymentsIndex({
    deployments,
    summary,
    filters,
    employees,
    ranks,
    clients,
    company_visa_types,
    vessels,
    can,
    status_rules,
}: Props): ReactElement {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [rulesDialogOpen, setRulesDialogOpen] = useState(false);
    const [editing, setEditing] = useState<DeploymentItem | null>(null);
    const [deleting, setDeleting] = useState<DeploymentItem | null>(null);

    const view = (filters.view ?? 'table') as 'table' | 'board';

    const list = useServerPaginationFilters({
        url: '/organization/crew-deployments',
        search: filters.search ?? '',
        filters: {
            status: filters.status ?? '',
            rank_id: filters.rank_id ? String(filters.rank_id) : '',
            client_id: filters.client_id ? String(filters.client_id) : '',
            company_visa_type_id: filters.company_visa_type_id
                ? String(filters.company_visa_type_id)
                : '',
            sort: filters.sort,
            direction: filters.direction,
            view: filters.view ?? 'table',
        },
        pagination: deployments,
    });

    // const setView = (newView: 'table' | 'board'): void => {
    //     list.applyFilters({ view: newView });
    // };

    const activeSort = filters.sort ?? DEFAULT_DEPLOYMENT_SORT;
    const activeDirection = filters.direction ?? DEFAULT_DEPLOYMENT_SORT_DIRECTION;
    const showInHomeDaysColumn = filters.status === 'in_home';
    const tableColumnCount = TABLE_COLUMN_COUNT + (showInHomeDaysColumn ? 1 : 0);

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (filters.status) {
count++;
}

        if (filters.rank_id) {
count++;
}

        if (filters.client_id) {
count++;
}

        if (filters.company_visa_type_id) {
count++;
}

        if (filters.search) {
count++;
}

        return count;
    }, [filters]);

    const clearFilters = (): void => {
        list.visit({
            status: '',
            rank_id: '',
            client_id: '',
            company_visa_type_id: '',
            search: '',
            sort: DEFAULT_DEPLOYMENT_SORT,
            direction: DEFAULT_DEPLOYMENT_SORT_DIRECTION,
            view: filters.view ?? 'table',
            page: null,
        });
    };

    const listBackQuery = useMemo(() => {
        const query: Record<string, string> = {};

        if (filters.status) {
query.status = filters.status;
}

        if (filters.search) {
query.search = filters.search;
}

        if (filters.rank_id) {
query.rank_id = String(filters.rank_id);
}

        if (filters.client_id) {
query.client_id = String(filters.client_id);
}

        if (filters.company_visa_type_id) {
            query.company_visa_type_id = String(filters.company_visa_type_id);
        }

        if (filters.sort) {
query.sort = filters.sort;
}

        if (filters.direction) {
query.direction = filters.direction;
}

        if (filters.view) {
query.view = filters.view;
}

        if (deployments.per_page) {
query.per_page = String(deployments.per_page);
}

        return query;
    }, [deployments.per_page, filters]);

    const openShow = useCallback(
        (deploymentId: number): void => {
            router.visit(
                showDeployment.url(
                    { deployment: deploymentId },
                    Object.keys(listBackQuery).length > 0 ? { query: listBackQuery } : undefined,
                ),
            );
        },
        [listBackQuery],
    );

    const handleColumnSort = useCallback(
        (sortKey: string): void => {
            if (activeSort === sortKey) {
                list.applyFilters({
                    direction: activeDirection === 'asc' ? 'desc' : 'asc',
                });

                return;
            }

            list.applyFilters({ sort: sortKey, direction: 'desc' });
        },
        [activeDirection, activeSort, list],
    );

    const openCreate = (): void => {
        setEditing(null);
        setDialogOpen(true);
    };

    const openEdit = (deployment: DeploymentItem): void => {
        setEditing(deployment);
        setDialogOpen(true);
    };

    return (
        <Main>
            <Head title="Deployments" />

            <div className="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="min-w-0">
                    <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
                        Crew Operations
                    </p>
                    <div className="flex items-center gap-2">
                        <h1 className="text-3xl font-extrabold tracking-tight sm:text-4xl">
                            Deployments
                        </h1>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 shrink-0 text-muted-foreground hover:text-foreground"
                            aria-label="Deployment status rules"
                            onClick={() => setRulesDialogOpen(true)}
                        >
                            <Info className="h-4 w-4" />
                        </Button>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Track where crew are now — on vessel, join/leave standby, travel, and
                        assignment history.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end">
                    {/* Board view toggle — hidden until board is ready to ship
                    <div className="inline-flex h-9 items-stretch overflow-hidden rounded-lg border border-border bg-muted/40">
                        <Button
                            variant={view === 'table' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => setView('table')}
                            className="h-9 rounded-none px-3 shadow-none"
                        >
                            <LayoutList className="mr-1.5 h-4 w-4" />
                            Table
                        </Button>
                        <Button
                            variant={view === 'board' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => setView('board')}
                            className="h-9 rounded-none border-l border-border/60 px-3 shadow-none"
                        >
                            <LayoutDashboard className="mr-1.5 h-4 w-4" />
                            Board
                        </Button>
                    </div>

                    <div className="hidden h-6 w-px bg-border/60 sm:block" aria-hidden />
                    */}

                    {can.export ? (
                        <Button variant="outline" size="sm" className="h-9" asChild>
                            <a href="/organization/crew-deployments/export">
                                <Download className="mr-2 h-4 w-4" />
                                Export
                            </a>
                        </Button>
                    ) : null}
                    {can.create ? (
                        <Button
                            size="sm"
                            className={cn(actions.primary, 'h-9')}
                            onClick={openCreate}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add deployment
                        </Button>
                    ) : null}
                </div>
            </div>

            {view === 'table' ? (
                <CrewDeploymentsSummaryCards
                    summary={summary}
                    activeStatus={filters.status ?? ''}
                    hasActiveFilters={activeFilterCount > 0}
                    onSelect={(status) => list.applyFilters({ status })}
                    onClearFilters={clearFilters}
                />
            ) : null}

            <Card className="mb-6 border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                <CardContent className="p-5">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                            Filters
                        </span>
                        {activeFilterCount > 0 ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                {activeFilterCount} active
                            </Badge>
                        ) : null}
                        {activeFilterCount > 0 ? (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground/50 transition-colors hover:text-foreground"
                            >
                                <X className="h-3 w-3" />
                                Clear all
                            </button>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 lg:flex-row">
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                            <Input
                                value={list.searchInput}
                                onChange={(event) => list.onSearchChange(event.target.value)}
                                placeholder="Search employee no, name, vessel, remarks…"
                                className="h-10 rounded-xl border-border bg-muted/50 pl-10 focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-2 xl:grid-cols-3 lg:w-auto lg:shrink-0">
                            <AppSelect
                                value={filters.rank_id ? String(filters.rank_id) : ''}
                                onValueChange={(rankId) =>
                                    list.applyFilters({ rank_id: rankId })
                                }
                                placeholder="All ranks"
                                variant="dark"
                                className="h-10"
                            >
                                <AppSelectItem value="">All ranks</AppSelectItem>
                                {ranks.map((rank) => (
                                    <AppSelectItem key={rank.id} value={String(rank.id)}>
                                        {rank.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>

                            <AppSelect
                                value={filters.client_id ? String(filters.client_id) : ''}
                                onValueChange={(clientId) =>
                                    list.applyFilters({ client_id: clientId })
                                }
                                placeholder="All clients"
                                variant="dark"
                                className="h-10"
                            >
                                <AppSelectItem value="">All clients</AppSelectItem>
                                {clients.map((client) => (
                                    <AppSelectItem key={client.id} value={String(client.id)}>
                                        {client.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>

                            <AppSelect
                                value={
                                    filters.company_visa_type_id
                                        ? String(filters.company_visa_type_id)
                                        : ''
                                }
                                onValueChange={(companyVisaTypeId) =>
                                    list.applyFilters({ company_visa_type_id: companyVisaTypeId })
                                }
                                placeholder="All sponsors"
                                variant="dark"
                                className="h-10"
                            >
                                <AppSelectItem value="">All sponsors</AppSelectItem>
                                {company_visa_types.map((companyVisaType) => (
                                    <AppSelectItem
                                        key={companyVisaType.id}
                                        value={String(companyVisaType.id)}
                                    >
                                        {companyVisaType.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Board view disabled — uncomment the ternary below when ready to ship
            {view === 'table' ? ( */}
                <OrganizationDataTable minWidth="min-w-[2600px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead rowSpan={2}>Where now</DataTableHead>
                        <SortableDeploymentTableHead
                            sortKey="employee_no"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Emp. no
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="employee_name"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Name
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="rank"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Rank
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="vessel_name"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Vessel
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="hire_date"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Date of hire
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="arrived_date"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Arrived
                        </SortableDeploymentTableHead>
                        <DataTableHead colSpan={3} className="text-center">
                            Join standby
                        </DataTableHead>
                        <SortableDeploymentTableHead
                            sortKey="joined_date"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Joined
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="disembarked_date"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Disembarked
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="vessel_days"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Vessel days
                        </SortableDeploymentTableHead>
                        <DataTableHead colSpan={3} className="text-center">
                            Leave standby
                        </DataTableHead>
                        <SortableDeploymentTableHead
                            sortKey="travelled_date"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Travelled
                        </SortableDeploymentTableHead>
                        {showInHomeDaysColumn ? (
                            <SortableDeploymentTableHead
                                sortKey="in_home_days"
                                activeSort={activeSort}
                                direction={activeDirection}
                                onSort={handleColumnSort}
                                rowSpan={2}
                            >
                                In home days
                            </SortableDeploymentTableHead>
                        ) : null}
                        <SortableDeploymentTableHead
                            sortKey="sponsor"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Sponsor
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="client"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                            rowSpan={2}
                        >
                            Client
                        </SortableDeploymentTableHead>
                        <DataTableHead rowSpan={2}>Remarks</DataTableHead>
                        {deploymentHasWriteActions(can) ? (
                            <DataTableHead rowSpan={2} className="text-right">
                                Actions
                            </DataTableHead>
                        ) : null}
                    </DataTableHeaderRow>
                    <DataTableHeaderRow>
                        <SortableDeploymentTableHead
                            sortKey="join_standby_from"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            From
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="join_standby_to"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            To
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="join_standby_days"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            Days
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="leave_standby_from"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            From
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="leave_standby_to"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            To
                        </SortableDeploymentTableHead>
                        <SortableDeploymentTableHead
                            sortKey="leave_standby_days"
                            activeSort={activeSort}
                            direction={activeDirection}
                            onSort={handleColumnSort}
                        >
                            Days
                        </SortableDeploymentTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {deployments.data.length === 0 ? (
                        <TableRow>
                            <TableCell
                                colSpan={
                                    deploymentHasWriteActions(can)
                                        ? tableColumnCount + 1
                                        : tableColumnCount
                                }
                                className="py-10 text-center text-muted-foreground"
                            >
                                No deployment records found.
                            </TableCell>
                        </TableRow>
                    ) : (
                        deployments.data.map((deployment) => (
                            <TableRow
                                key={deployment.id}
                                className={dataTableBodyRowClass()}
                                onClick={() => openShow(deployment.id)}
                            >
                                <TableCell>
                                    <DeploymentStatusBadge
                                        status={deployment.status}
                                        label={deployment.status_label}
                                        hint={deployment.status_hint}
                                        stopRowNavigation
                                    />
                                </TableCell>
                                <TableCell>{displayValue(deployment.employee_no)}</TableCell>
                                <TableCell>
                                    {deployment.employee_name ? (
                                        <EmployeeProfileLink
                                            employeeId={deployment.employee_id}
                                            className="text-sm"
                                            stopRowNavigation
                                        >
                                            {deployment.employee_name}
                                        </EmployeeProfileLink>
                                    ) : (
                                        <div className="text-sm">—</div>
                                    )}
                                    <div className="text-xs text-muted-foreground">
                                        {displayValue(deployment.nationality)}
                                    </div>
                                </TableCell>
                                <TableCell>{displayValue(deployment.rank_name)}</TableCell>
                                <TableCell>{displayValue(deployment.vessel_name)}</TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.hire_date)}
                                </TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.arrived_date}
                                        field="arrived_date"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.join_standby_from}
                                        field="join_standby_from"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.join_standby_to}
                                        field="join_standby_to"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>{displayNumber(deployment.join_standby_days)}</TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.joined_date}
                                        field="joined_date"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.disembarked_date}
                                        field="disembarked_date"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>{displayNumber(deployment.vessel_days)}</TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.leave_standby_from}
                                        field="leave_standby_from"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.leave_standby_to}
                                        field="leave_standby_to"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                <TableCell>{displayNumber(deployment.leave_standby_days)}</TableCell>
                                <TableCell>
                                    <DeploymentDateCell
                                        value={deployment.travelled_date}
                                        field="travelled_date"
                                        overdueFields={deployment.overdue_date_fields}
                                        dueSoonFields={deployment.due_soon_date_fields}
                                    />
                                </TableCell>
                                {showInHomeDaysColumn ? (
                                    <TableCell>{displayNumber(deployment.in_home_days)}</TableCell>
                                ) : null}
                                <TableCell>
                                    {displayValue(deployment.company_visa_type_name)}
                                </TableCell>
                                <TableCell>{displayValue(deployment.client_name)}</TableCell>
                                <TableCell
                                    className="max-w-[200px] truncate"
                                    title={deployment.remarks ?? undefined}
                                >
                                    {displayValue(deployment.remarks)}
                                </TableCell>
                                {deploymentHasWriteActions(can) ? (
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <ListTableCrudActions
                                            viewHref={showDeployment.url(
                                                { deployment: deployment.id },
                                                Object.keys(listBackQuery).length > 0
                                                    ? { query: listBackQuery }
                                                    : undefined,
                                            )}
                                            onEdit={can.update ? () => openEdit(deployment) : undefined}
                                            onDelete={can.delete ? () => setDeleting(deployment) : undefined}
                                            showEdit={can.update}
                                            showDelete={can.delete}
                                        />
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>
            {/* ) : (
                <CrewDeploymentsBoard
                    deployments={deployments.data}
                    summary={summary}
                    can={can}
                    vessels={vessels}
                    onEdit={openEdit}
                    onDelete={setDeleting}
                    backQuery={listBackQuery}
                />
            )} */}

            <Pagination {...list.paginationProps} className="mt-4" label="deployments" />

            <DeploymentStatusRulesDialog
                open={rulesDialogOpen}
                onOpenChange={setRulesDialogOpen}
                rules={status_rules}
            />

            <DeploymentFormDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                editing={editing}
                employees={employees}
                ranks={ranks}
                clients={clients}
                companyVisaTypes={company_visa_types}
                vessels={vessels}
            />

            <ConfirmDeleteDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
setDeleting(null);
}
                }}
                title="Delete deployment record?"
                description="This removes the assignment from the crew tracker."
                onConfirm={() => {
                    if (!deleting) {
return;
}

                    router.delete(destroyDeployment.url({ deployment: deleting.id }), {
                        preserveScroll: true,
                        onSuccess: () => setDeleting(null),
                    });
                }}
            />
        </Main>
    );
}
