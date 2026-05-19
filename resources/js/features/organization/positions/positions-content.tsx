import { router, useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { PositionCard } from './components/position-card';
import { PositionDeleteDialog } from './components/position-delete-dialog';
import { PositionFiltersSheet } from './components/position-filters-sheet';
import type { PositionFilters } from './components/position-filters-sheet';
import { PositionFormSheet } from './components/position-form-sheet';
import type { DepartmentOption, Position, PositionFormData } from './types';

export function PositionsContent({
    positions,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    departments,
}: {
    positions: Position[];
    pagination: PaginationMeta;
    search: string;
    filters: { department_id: string; status: string; grade: string };
    departments: DepartmentOption[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/positions',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const [view, setView] = useViewPreference('positions:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentPosition, setCurrentPosition] = useState<Position | null>(null);

    const filters: PositionFilters = {
        department_id: initialFilters.department_id,
        status: initialFilters.status,
        grade: initialFilters.grade,
    };

    const activeFiltersCount = [
        initialFilters.department_id,
        initialFilters.status,
        initialFilters.grade.trim(),
    ].filter(Boolean).length;

    const form = useForm<PositionFormData>({
        department_id: '',
        title: '',
        grade: '',
        min_salary: '',
        max_salary: '',
        status: 'active',
    });

    const handleAdd = () => {
        setCurrentPosition(null);
        form.reset();
        form.clearErrors();
        form.setData({ department_id: '', title: '', grade: '', min_salary: '', max_salary: '', status: 'active' });
        setIsSheetOpen(true);
    };

    const handleEdit = (position: Position) => {
        setCurrentPosition(position);
        form.reset();
        form.clearErrors();
        form.setData({
            department_id: position.department?.id ?? '',
            title: position.title ?? '',
            grade: position.grade ?? '',
            min_salary: position.min_salary ? String(position.min_salary) : '',
            max_salary: position.max_salary ? String(position.max_salary) : '',
            status: position.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (position: Position) => {
        setCurrentPosition(position);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentPosition) {
return;
}

        router.delete(`/organization/positions/${currentPosition.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentPosition(null);
            },
        });
    };

    const toggleStatus = (position: Position, enabled: boolean) => {
        router.put(
            `/organization/positions/${position.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () => toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (currentPosition) {
            form.put(`/organization/positions/${currentPosition.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/positions', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: PositionFilters) => {
        list.applyFilters(next);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (initialSearch) {
params.set('search', initialSearch);
}

        if (initialFilters.department_id) {
params.set('department_id', initialFilters.department_id);
}

        if (initialFilters.status) {
params.set('status', initialFilters.status);
}

        if (initialFilters.grade) {
params.set('grade', initialFilters.grade);
}

        params.set('format', format);

        return `/organization/positions/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Positions"
                description="Manage job positions and grades."
                right={
                    <>
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                        <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                            <Plus className="mr-2 h-4 w-4" />
                            Add Position
                        </Button>
                    </>
                }
            />

            <SearchBar
                placeholder="Search positions by title, grade, company, or department..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />
                        <Button
                            type="button"
                            variant="secondary"
                            className="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                            onClick={() => setIsFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                            {activeFiltersCount ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {activeFiltersCount}
                                </span>
                            ) : null}
                        </Button>
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {positions.map((position) => (
                        <PositionCard
                            key={position.id}
                            position={position}
                            onEdit={handleEdit}
                            onDelete={handleDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Position</DataTableHead>
                            <DataTableHead>Department</DataTableHead>
                            <DataTableHead>Grade</DataTableHead>
                            <DataTableHead>Min</DataTableHead>
                            <DataTableHead>Max</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {positions.map((position) => (
                                    <TableRow
                                        key={position.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() => router.visit(`/organization/positions/${position.id}`)}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>{position.title}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{position.department?.name ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{position.grade ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{position.min_salary ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>{position.max_salary ?? '—'}</TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch
                                                    checked={position.status === 'active'}
                                                    onCheckedChange={(checked) => toggleStatus(position, checked)}
                                                />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {position.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <ListTableCrudActions
                                                viewHref={`/organization/positions/${position.id}`}
                                                onEdit={(e) => {
                                                    e.stopPropagation();
                                                    handleEdit(position);
                                                }}
                                                onDelete={(e) => {
                                                    e.stopPropagation();
                                                    handleDelete(position);
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                </OrganizationDataTable>
            )}

            {positions.length === 0 ? <EmptyState title="No positions found." /> : null}

            <Pagination {...list.paginationProps} label="positions" />

            <PositionFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                position={currentPosition}
                departments={departments}
                form={form}
                onSubmit={submit}
            />

            <PositionFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                departments={departments}
                value={filters}
                onChange={handleFiltersChange}
                onReset={() => handleFiltersChange({ department_id: '', status: '', grade: '' })}
            />

            <PositionDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                position={currentPosition}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
