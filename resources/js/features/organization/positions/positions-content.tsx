import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { ListTableCrudActions } from '@/components/list-table-actions';
import { OrganizationListPageShell } from '@/components/organization-list-page-shell';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useOrganizationCrudList } from '@/hooks/use-organization-crud-list';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { buildListExportUrl } from '@/lib/build-list-export-url';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { PositionCard } from './components/position-card';
import { PositionDeleteDialog } from './components/position-delete-dialog';
import { PositionFiltersSheet } from './components/position-filters-sheet';
import type { PositionFilters } from './components/position-filters-sheet';
import { PositionFormSheet } from './components/position-form-sheet';
import { PositionTreeView } from './components/position-tree-view';
import type { DepartmentOption, Position, PositionFormData } from './types';

export function PositionsContent({
    positions,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    departments,
    tree_departments = [],
    tree_positions = [],
}: {
    positions: Position[];
    pagination: PaginationMeta;
    search: string;
    filters: { department_id: string; status: string; grade: string };
    departments: DepartmentOption[];
    tree_departments?: any[];
    tree_positions?: any[];
}) {
    const list = useServerPaginationFilters({
        url: '/organization/positions',
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });
    const crud = useOrganizationCrudList<Position>({
        viewKey: 'positions:view',
    });

    const filters: PositionFilters = {
        department_id: initialFilters.department_id,
        status: initialFilters.status as PositionFilters['status'],
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
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                department_id: '',
                title: '',
                grade: '',
                min_salary: '',
                max_salary: '',
                status: 'active',
            });
        });
    };

    const handleEdit = (position: Position) => {
        crud.openEdit(position, () => {
            form.reset();
            form.clearErrors();
            form.setData({
                department_id: position.department?.id ?? '',
                title: position.title ?? '',
                grade: position.grade ?? '',
                min_salary: position.min_salary
                    ? String(position.min_salary)
                    : '',
                max_salary: position.max_salary
                    ? String(position.max_salary)
                    : '',
                status: position.status ?? 'active',
            });
        });
    };

    const confirmDelete = () => {
        if (!crud.currentEntity) {
            return;
        }

        router.delete(`/organization/positions/${crud.currentEntity.id}`, {
            onFinish: () => crud.confirmDeleteFinish(),
        });
    };

    const toggleStatus = (position: Position, enabled: boolean) => {
        router.put(
            `/organization/positions/${position.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error('Failed to update status. Please try again.'),
            },
        );
    };

    const submit = () => {
        if (crud.currentEntity) {
            form.put(`/organization/positions/${crud.currentEntity.id}`, {
                preserveScroll: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/positions', {
            preserveScroll: true,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: PositionFilters) => {
        list.applyFilters(next);
    };

    const resetFilters = () => {
        handleFiltersChange({
            department_id: '',
            status: '',
            grade: '',
        });
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') =>
        buildListExportUrl('/organization/positions/export', {
            search: initialSearch,
            department_id: initialFilters.department_id,
            status: initialFilters.status,
            grade: initialFilters.grade,
            format,
        });

    return (
        <OrganizationListPageShell
            title="Positions"
            description="Manage job positions and grades."
            headerRight={
                <>
                    <ExportMenu
                        getUrl={getExportUrl}
                        buttonVariant="secondary"
                        buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                    />
                    <Button
                        onClick={handleAdd}
                        className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                    >
                        <Plus className="mr-2 h-4 w-4" />
                        Add Position
                    </Button>
                </>
            }
            search={{
                placeholder:
                    'Search positions by title, grade, company, or department...',
                value: list.searchInput,
                onChange: list.onSearchChange,
                right:
                    crud.view && crud.setView ? (
                        <ViewToggle
                            value={crud.view}
                            onChange={crud.setView}
                            showTreeView={true}
                        />
                    ) : null,
            }}
            filtersButton={{
                onClick: () => crud.setIsFiltersOpen(true),
                activeFiltersCount,
            }}
            pagination={
                <Pagination {...list.paginationProps} label="positions" />
            }
        >
            {crud.view === 'tree' ? (
                <PositionTreeView
                    departments={tree_departments}
                    positions={tree_positions}
                />
            ) : crud.view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {positions.map((position) => (
                        <PositionCard
                            key={position.id}
                            position={position}
                            onEdit={handleEdit}
                            onDelete={crud.openDelete}
                            onToggleStatus={toggleStatus}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[980px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Position
                            </DataTableHead>
                            <DataTableHead>Department</DataTableHead>
                            <DataTableHead>Grade</DataTableHead>
                            <DataTableHead>Min</DataTableHead>
                            <DataTableHead>Max</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {positions.map((position) => (
                            <TableRow
                                key={position.id}
                                className={dataTableBodyRowClass()}
                                onClick={() =>
                                    router.visit(
                                        `/organization/positions/${position.id}`,
                                    )
                                }
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    {position.title}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.department?.name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.grade ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.min_salary ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.max_salary ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Switch
                                            checked={
                                                position.status === 'active'
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleStatus(position, checked)
                                            }
                                        />
                                        <span className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            {position.status ?? '—'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        viewHref={`/organization/positions/${position.id}`}
                                        onEdit={(e) => {
                                            e.stopPropagation();
                                            handleEdit(position);
                                        }}
                                        onDelete={(e) => {
                                            e.stopPropagation();
                                            crud.openDelete(position);
                                        }}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {positions.length === 0 ? (
                <EmptyState title="No positions found." />
            ) : null}

            <PositionFormSheet
                open={crud.isSheetOpen}
                onOpenChange={crud.setIsSheetOpen}
                position={crud.currentEntity}
                departments={departments}
                form={form}
                onSubmit={submit}
            />

            <PositionFiltersSheet
                open={crud.isFiltersOpen}
                onOpenChange={crud.setIsFiltersOpen}
                departments={departments}
                value={filters}
                onChange={handleFiltersChange}
                onReset={resetFilters}
            />

            <PositionDeleteDialog
                open={crud.isDeleteDialogOpen}
                onOpenChange={crud.setIsDeleteDialogOpen}
                position={crud.currentEntity}
                onConfirm={confirmDelete}
            />
        </OrganizationListPageShell>
    );
}
