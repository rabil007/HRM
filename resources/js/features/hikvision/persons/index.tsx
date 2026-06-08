import { Link, router } from '@inertiajs/react';
import { Filter, Fingerprint, Info, KeyRound, Link2, MoreHorizontal, Pencil, Plus, RefreshCw, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { LinkPersonEmployeeDialog } from './link-person-employee-dialog';
import { HikvisionPersonDeleteDialog } from './person-delete-dialog';
import { HikvisionPersonFormDialog } from './person-form-dialog';
import type {
    EmployeeLinkOption,
    HikvisionPerson,
    HikvisionPersonFilterOption,
    HikvisionPersonFilters,
} from './types';

type Props = {
    persons: HikvisionPerson[];
    pagination: PaginationMeta;
    filters: HikvisionPersonFilters;
    groupOptions: HikvisionPersonFilterOption[];
    credentialOptions: HikvisionPersonFilterOption[];
    employeesForLinking: EmployeeLinkOption[];
    isConfigured: boolean;
    lastSyncedAt: string | null;
    can: {
        sync: boolean;
        create: boolean;
        update: boolean;
        delete: boolean;
        link: boolean;
    };
};

function hasActiveFilters(filters: HikvisionPersonFilters): boolean {
    return Boolean(filters.search || filters.group || filters.credential);
}

export function HikvisionPersonsContent({
    persons,
    pagination,
    filters,
    groupOptions,
    credentialOptions,
    employeesForLinking,
    isConfigured,
    lastSyncedAt,
    can,
}: Props) {
    const list = useServerPaginationFilters({
        url: '/hikvision/persons',
        search: filters.search,
        filters: {
            group: filters.group,
            credential: filters.credential,
        },
        pagination,
    });
    const [syncing, setSyncing] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editingPerson, setEditingPerson] = useState<HikvisionPerson | null>(null);
    const [deletePerson, setDeletePerson] = useState<HikvisionPerson | null>(null);
    const [linkingPerson, setLinkingPerson] = useState<HikvisionPerson | null>(null);

    const activeFilterCount = useMemo(
        () => [filters.search, filters.group, filters.credential].filter(Boolean).length,
        [filters],
    );

    const applyFilters = (next: Partial<HikvisionPersonFilters>) => {
        list.applyFilters({
            search: next.search ?? filters.search,
            group: next.group ?? filters.group,
            credential: next.credential ?? filters.credential,
        });
    };

    const clearFilters = () => {
        list.visit({
            search: null,
            group: null,
            credential: null,
            page: null,
        });
    };

    const handleSync = () => {
        if (!can.sync || !isConfigured || syncing) {
            return;
        }

        setSyncing(true);

        router.post(
            '/hikvision/persons/sync',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Hikvision persons synced successfully.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.sync === 'string'
                            ? errors.sync
                            : 'Failed to sync Hikvision persons.';
                    toast.error(message);
                },
                onFinish: () => {
                    setSyncing(false);
                },
            },
        );
    };

    const openCreateDialog = () => {
        setEditingPerson(null);
        setFormOpen(true);
    };

    const openEditDialog = (person: HikvisionPerson) => {
        setEditingPerson(person);
        setFormOpen(true);
    };

    const confirmDelete = () => {
        if (!deletePerson) {
            return;
        }

        router.delete(`/hikvision/persons/${deletePerson.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeletePerson(null);
                toast.success('Person deleted from Hikvision.');
            },
            onError: (errors) => {
                const message =
                    typeof errors.person === 'string'
                        ? errors.person
                        : 'Failed to delete Hikvision person.';
                toast.error(message);
            },
        });
    };

    return (
        <Main>
            <PageHeader
                title="Hikvision Persons"
                description="Access-control persons and departments synced from Hik-Connect for Teams."
                right={
                    <div className="flex items-center gap-2">
                        {can.create ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-xl"
                                disabled={!isConfigured}
                                onClick={openCreateDialog}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add person
                            </Button>
                        ) : null}
                        {can.sync ? (
                            <Button
                                type="button"
                                className="rounded-xl"
                                disabled={!isConfigured || syncing}
                                onClick={handleSync}
                            >
                                {syncing ? <Spinner className="mr-2" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                                Sync
                            </Button>
                        ) : null}
                    </div>
                }
            />

            {!isConfigured ? (
                <Alert className="mb-6 border-amber-500/20 bg-amber-500/5">
                    <Info className="h-4 w-4" />
                    <AlertTitle>Hikvision not configured</AlertTitle>
                    <AlertDescription>
                        Add your API credentials in{' '}
                        <Link
                            href="/settings/application?tab=hikvision"
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Application settings → Hikvision
                        </Link>{' '}
                        before syncing persons.
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="mb-6 text-sm text-muted-foreground">
                    Last synced:{' '}
                    <span className="font-medium text-foreground">
                        {lastSyncedAt ? formatDisplayDateTime(lastSyncedAt) : 'Never synced'}
                    </span>
                </p>
            )}

            <Card className="mb-6 border-white/5 bg-white/3">
                <CardContent className="p-5">
                    <div className="mb-4 flex items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                            Filters
                        </span>
                        {activeFilterCount > 0 ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                {activeFilterCount} active
                            </Badge>
                        ) : null}
                        {hasActiveFilters(filters) ? (
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

                    <div className="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_12rem_12rem]">
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="persons-search"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                Search
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                                <Input
                                    id="persons-search"
                                    value={list.searchInput}
                                    onChange={(event) => list.onSearchChange(event.target.value)}
                                    placeholder="Name, employee no, email, phone…"
                                    className="h-10 rounded-xl border-white/10 bg-white/5 pl-10 focus-visible:ring-primary/40"
                                />
                            </div>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Department</span>
                            <AppSelect
                                value={filters.group || ''}
                                onValueChange={(value) => applyFilters({ group: value })}
                                variant="dark"
                                placeholder="All departments"
                                className="h-10"
                            >
                                <AppSelectItem value="">All departments</AppSelectItem>
                                {groupOptions.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Credentials</span>
                            <AppSelect
                                value={filters.credential || ''}
                                onValueChange={(value) => applyFilters({ credential: value })}
                                variant="dark"
                                placeholder="All credentials"
                                className="h-10"
                            >
                                <AppSelectItem value="">All credentials</AppSelectItem>
                                {credentialOptions.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {persons.length === 0 ? (
                <EmptyState
                    title={hasActiveFilters(filters) ? 'No persons match your filters' : 'No persons synced yet'}
                    description={
                        hasActiveFilters(filters)
                            ? 'Try adjusting your filters or clear them to see all persons.'
                            : 'Click Sync to fetch persons and departments from the Hikvision API.'
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[960px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Employee no.</DataTableHead>
                                <DataTableHead>Department</DataTableHead>
                                <DataTableHead>Linked employee</DataTableHead>
                                <DataTableHead>Email</DataTableHead>
                                <DataTableHead>Credentials</DataTableHead>
                                <DataTableHead>Last synced</DataTableHead>
                                {can.update || can.delete || can.link ? (
                                    <DataTableHead className="w-12" />
                                ) : null}
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {persons.map((person) => (
                                <TableRow key={person.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        <div className="flex items-center gap-3">
                                            {person.photo_url ? (
                                                <img
                                                    src={person.photo_url}
                                                    alt=""
                                                    className="h-8 w-8 rounded-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
                                                    {(person.full_name ?? '?').slice(0, 1).toUpperCase()}
                                                </div>
                                            )}
                                            <span>{person.full_name ?? '—'}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {person.person_code ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {person.group_name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {person.linked_employee ? (
                                            can.link ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setLinkingPerson(person)}
                                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                                >
                                                    {person.linked_employee.name}
                                                </button>
                                            ) : (
                                                <Link
                                                    href={`/organization/employees/${person.linked_employee.id}`}
                                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                                >
                                                    {person.linked_employee.name}
                                                </Link>
                                            )
                                        ) : can.link ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 px-2 text-muted-foreground hover:text-foreground"
                                                onClick={() => setLinkingPerson(person)}
                                            >
                                                <Link2 className="mr-1.5 h-3.5 w-3.5" />
                                                Link employee
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {person.email ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <div className="flex flex-wrap gap-1">
                                            {person.has_fingerprint ? (
                                                <Badge variant="secondary" className="gap-1">
                                                    <Fingerprint className="h-3 w-3" />
                                                    Fingerprint
                                                </Badge>
                                            ) : null}
                                            {person.has_pin ? (
                                                <Badge variant="secondary" className="gap-1">
                                                    <KeyRound className="h-3 w-3" />
                                                    PIN
                                                </Badge>
                                            ) : null}
                                            {!person.has_fingerprint && !person.has_pin ? (
                                                <span className="text-muted-foreground">—</span>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {person.synced_at ? formatDisplayDateTime(person.synced_at) : '—'}
                                    </TableCell>
                                    {can.update || can.delete || can.link ? (
                                        <TableCell className={dataTableCellClass}>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg"
                                                    >
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {can.link ? (
                                                        <DropdownMenuItem onClick={() => setLinkingPerson(person)}>
                                                            <Link2 className="mr-2 h-4 w-4" />
                                                            {person.linked_employee
                                                                ? 'Change linked employee'
                                                                : 'Link employee'}
                                                        </DropdownMenuItem>
                                                    ) : null}
                                                    {can.update ? (
                                                        <DropdownMenuItem onClick={() => openEditDialog(person)}>
                                                            <Pencil className="mr-2 h-4 w-4" />
                                                            Edit
                                                        </DropdownMenuItem>
                                                    ) : null}
                                                    {can.delete ? (
                                                        <DropdownMenuItem
                                                            className="text-destructive focus:text-destructive"
                                                            onClick={() => setDeletePerson(person)}
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    ) : null}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <div className="mt-6">
                        <Pagination {...list.paginationProps} label="persons" />
                    </div>
                </>
            )}

            <HikvisionPersonFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                person={editingPerson}
                groupOptions={groupOptions}
            />

            <HikvisionPersonDeleteDialog
                open={deletePerson !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletePerson(null);
                    }
                }}
                person={deletePerson}
                onConfirm={confirmDelete}
            />

            <LinkPersonEmployeeDialog
                open={linkingPerson !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setLinkingPerson(null);
                    }
                }}
                person={linkingPerson}
                employeesForLinking={employeesForLinking}
            />
        </Main>
    );
}
