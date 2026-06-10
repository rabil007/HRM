import { Head, router } from '@inertiajs/react';
import { Download, Filter, Plus, Search, Upload, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { destroy as destroyDeployment } from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
} from '@/components/data-table';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CrewDeploymentsSummaryCards } from '@/features/organization/crew-deployments/crew-deployments-summary-cards';
import { DeploymentFormDialog } from '@/features/organization/crew-deployments/deployment-form-dialog';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import type {
    DeploymentItem,
    DeploymentSummary,
} from '@/features/organization/crew-deployments/types';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { actions } from '@/lib/design-system';
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
        view: string;
        status: string | null;
        search: string | null;
        rank_id: number | null;
        client_id: number | null;
        company_visa_type_id: number | null;
    };
    employees: EmployeeOption[];
    ranks: Option[];
    clients: Option[];
    company_visa_types: Option[];
    can: { manage: boolean };
};

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function displayNumber(value: number | null | undefined): string {
    return value !== null && value !== undefined ? String(value) : '—';
}

const TABLE_COLUMN_COUNT = 18;

export default function CrewDeploymentsIndex({
    deployments,
    summary,
    filters,
    employees,
    ranks,
    clients,
    company_visa_types,
    can,
}: Props): ReactElement {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<DeploymentItem | null>(null);
    const [deleting, setDeleting] = useState<DeploymentItem | null>(null);

    const list = useServerPaginationFilters({
        url: '/organization/crew-deployments',
        search: filters.search ?? '',
        filters: {
            view: filters.view,
            status: filters.status ?? '',
            rank_id: filters.rank_id ? String(filters.rank_id) : '',
            client_id: filters.client_id ? String(filters.client_id) : '',
            company_visa_type_id: filters.company_visa_type_id
                ? String(filters.company_visa_type_id)
                : '',
        },
        pagination: deployments,
    });

    const activeFilterCount = useMemo(() => {
        let count = 0;
        if (filters.status) count++;
        if (filters.rank_id) count++;
        if (filters.client_id) count++;
        if (filters.company_visa_type_id) count++;
        if (filters.search) count++;
        return count;
    }, [filters]);

    const clearFilters = (): void => {
        list.visit({
            view: filters.view,
            status: '',
            rank_id: '',
            client_id: '',
            company_visa_type_id: '',
            search: '',
            page: null,
        });
    };

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

            <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
                        Crew Operations
                    </p>
                    <h1 className="text-3xl font-extrabold tracking-tight sm:text-4xl">
                        Deployments
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Track where crew are now — on vessel, standby, travel, and assignment
                        history.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href="/organization/crew-deployments/export">
                            <Download className="mr-2 h-4 w-4" />
                            Export
                        </a>
                    </Button>
                    {can.manage ? (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a href="/organization/crew-deployments/import/template">
                                    Template
                                </a>
                            </Button>
                            <label className="inline-flex cursor-pointer">
                                <Button variant="outline" size="sm" asChild>
                                    <span>
                                        <Upload className="mr-2 h-4 w-4" />
                                        Import
                                    </span>
                                </Button>
                                <Input
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    className="sr-only"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0] ?? null;
                                        if (!file) return;
                                        router.post(
                                            '/organization/crew-deployments/import',
                                            { file },
                                            {
                                                forceFormData: true,
                                                preserveScroll: true,
                                            },
                                        );
                                        event.currentTarget.value = '';
                                    }}
                                />
                            </label>
                            <Button size="sm" className={actions.primary} onClick={openCreate}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add deployment
                            </Button>
                        </>
                    ) : null}
                </div>
            </div>

            <CrewDeploymentsSummaryCards
                summary={summary}
                activeStatus={filters.status ?? ''}
                hasActiveFilters={activeFilterCount > 0}
                onSelect={(status) => list.applyFilters({ status })}
                onClearFilters={clearFilters}
            />

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

                    <Tabs
                        value={filters.view}
                        onValueChange={(view) => list.applyFilters({ view })}
                        className="mb-4"
                    >
                        <TabsList>
                            <TabsTrigger value="current">Current crew</TabsTrigger>
                            <TabsTrigger value="all">All assignments</TabsTrigger>
                        </TabsList>
                    </Tabs>

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

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:w-auto lg:shrink-0">
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
                                placeholder="All visa types"
                                variant="dark"
                                className="h-10"
                            >
                                <AppSelectItem value="">All visa types</AppSelectItem>
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

            <OrganizationDataTable minWidth="min-w-[2200px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Where now</DataTableHead>
                        <DataTableHead>Emp. no</DataTableHead>
                        <DataTableHead>Name</DataTableHead>
                        <DataTableHead>Rank</DataTableHead>
                        <DataTableHead>Nationality</DataTableHead>
                        <DataTableHead>Vessel</DataTableHead>
                        <DataTableHead>Hire date</DataTableHead>
                        <DataTableHead>Arrived</DataTableHead>
                        <DataTableHead>Standby from</DataTableHead>
                        <DataTableHead>Standby to</DataTableHead>
                        <DataTableHead>Standby days</DataTableHead>
                        <DataTableHead>Joined</DataTableHead>
                        <DataTableHead>Disembarked</DataTableHead>
                        <DataTableHead>Travelled</DataTableHead>
                        <DataTableHead>Total days</DataTableHead>
                        <DataTableHead>Company visa type</DataTableHead>
                        <DataTableHead>Client</DataTableHead>
                        <DataTableHead>Remarks</DataTableHead>
                        {can.manage ? (
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        ) : null}
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {deployments.data.length === 0 ? (
                        <TableRow>
                            <TableCell
                                colSpan={can.manage ? TABLE_COLUMN_COUNT + 1 : TABLE_COLUMN_COUNT}
                                className="py-10 text-center text-muted-foreground"
                            >
                                No deployment records found.
                            </TableCell>
                        </TableRow>
                    ) : (
                        deployments.data.map((deployment) => (
                            <TableRow key={deployment.id}>
                                <TableCell>
                                    <DeploymentStatusBadge
                                        status={deployment.status}
                                        label={deployment.status_label}
                                    />
                                </TableCell>
                                <TableCell>{displayValue(deployment.employee_no)}</TableCell>
                                <TableCell>{displayValue(deployment.employee_name)}</TableCell>
                                <TableCell>{displayValue(deployment.rank_name)}</TableCell>
                                <TableCell>{displayValue(deployment.nationality)}</TableCell>
                                <TableCell>{displayValue(deployment.vessel_name)}</TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.hire_date)}
                                </TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.arrived_date)}
                                </TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.standby_from)}
                                </TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.standby_to)}
                                </TableCell>
                                <TableCell>{displayNumber(deployment.standby_days)}</TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.joined_date)}
                                </TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.disembarked_date)}
                                </TableCell>
                                <TableCell>
                                    {formatIsoDateDisplay(deployment.travelled_date)}
                                </TableCell>
                                <TableCell>{displayNumber(deployment.total_days)}</TableCell>
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
                                {can.manage ? (
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <ListTableCrudActions
                                            showView={false}
                                            onEdit={() => openEdit(deployment)}
                                            onDelete={() => setDeleting(deployment)}
                                        />
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>

            <Pagination {...list.paginationProps} className="mt-4" label="deployments" />

            <DeploymentFormDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                editing={editing}
                employees={employees}
                ranks={ranks}
                clients={clients}
                companyVisaTypes={company_visa_types}
            />

            <ConfirmDeleteDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) setDeleting(null);
                }}
                title="Delete deployment record?"
                description="This removes the assignment from the crew tracker."
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(destroyDeployment.url({ deployment: deleting.id }), {
                        preserveScroll: true,
                        onSuccess: () => setDeleting(null),
                    });
                }}
            />
        </Main>
    );
}
