import { router, useForm } from '@inertiajs/react';
import { Briefcase, Plus, Upload } from 'lucide-react';
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
import { ListTableCrudActions } from '@/components/list-table-actions';
import { OrganizationListPageShell } from '@/components/organization-list-page-shell';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import { useHasPermission } from '@/hooks/use-has-permission';
import { useOrganizationCrudList } from '@/hooks/use-organization-crud-list';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { buildListExportUrl } from '@/lib/build-list-export-url';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import { PositionActiveFilters } from './components/position-active-filters';
import { PositionCard } from './components/position-card';
import { PositionDeleteDialog } from './components/position-delete-dialog';
import { PositionFiltersSheet } from './components/position-filters-sheet';
import type { PositionFilters } from './components/position-filters-sheet';
import { PositionFormSheet } from './components/position-form-sheet';
import { PositionImportDialog } from './components/position-import-dialog';
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
    department_tree = [],
    department_tree_selected_id = null,
}: {
    positions: Position[];
    pagination: PaginationMeta;
    search: string;
    filters: { department_id: string; status: string; grade: string };
    departments: DepartmentOption[];
    tree_departments?: any[];
    tree_positions?: any[];
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
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
    const canCreate = useHasPermission('positions.create');
    const [importOpen, setImportOpen] = useState(false);

    const filters: PositionFilters = {
        department_id: initialFilters.department_id,
        status: initialFilters.status as PositionFilters['status'],
        grade: initialFilters.grade,
    };

    const activeFiltersCount = [
        initialFilters.status,
        initialFilters.grade.trim(),
    ].filter(Boolean).length;

    const selectedDepartment = departments.find(
        (department) =>
            String(department.id) === String(initialFilters.department_id),
    );

    const hasSearchOrFilters = Boolean(
        initialSearch.trim() ||
        initialFilters.department_id ||
        initialFilters.status ||
        initialFilters.grade.trim(),
    );

    const form = useForm<PositionFormData>({
        department_id: '',
        title: '',
        description: '',
        grade: '',
        min_salary: '',
        max_salary: '',
        status: 'active',
        is_crew_position: true,
        max_tour_of_duty_days: '' as string | number,
        attachment: null,
        remove_attachment: false,
    });

    const handleAdd = () => {
        crud.openCreate(() => {
            form.reset();
            form.clearErrors();
            form.setData({
                department_id: '',
                title: '',
                description: '',
                grade: '',
                min_salary: '',
                max_salary: '',
                status: 'active',
                is_crew_position: true,
                max_tour_of_duty_days: '',
                attachment: null,
                remove_attachment: false,
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
                description: position.description ?? '',
                grade: position.grade ?? '',
                min_salary: position.min_salary
                    ? String(position.min_salary)
                    : '',
                max_salary: position.max_salary
                    ? String(position.max_salary)
                    : '',
                status: position.status ?? 'active',
                is_crew_position: position.is_crew_position ?? true,
                max_tour_of_duty_days: position.max_tour_of_duty_days ?? '',
                attachment: null,
                remove_attachment: false,
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
        const hasAttachment = form.data.attachment instanceof File;

        if (crud.currentEntity) {
            if (hasAttachment) {
                form.transform((data) => ({ ...data, _method: 'put' }));
                form.post(`/organization/positions/${crud.currentEntity.id}`, {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => crud.setIsSheetOpen(false),
                    onFinish: () => form.transform((data) => data),
                });

                return;
            }

            form.put(`/organization/positions/${crud.currentEntity.id}`, {
                preserveScroll: true,
                onSuccess: () => crud.setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/positions', {
            preserveScroll: true,
            forceFormData: hasAttachment,
            onSuccess: () => crud.setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: PositionFilters) => {
        list.applyFilters(next);
    };

    const resetFilters = () => {
        handleFiltersChange({
            ...filters,
            status: '',
            grade: '',
        });
    };

    const setDepartmentFilter = (departmentId: string) => {
        handleFiltersChange({
            ...filters,
            department_id: departmentId,
        });
    };

    const clearAllListFilters = () => {
        router.get(
            '/organization/positions',
            { per_page: pagination.per_page },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
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
            description="Define job roles used for employees and crew manning. Filter by department here, then refine by status or grade."
            headerRight={
                <>
                    <ExportMenu
                        getUrl={getExportUrl}
                        buttonVariant="secondary"
                        buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                    />
                    {canCreate ? (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setImportOpen(true)}
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Import CSV
                        </Button>
                    ) : null}
                    {canCreate ? (
                        <Button
                            onClick={handleAdd}
                            className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Add Position
                        </Button>
                    ) : null}
                </>
            }
            aboveSearch={
                <div className="mb-4 space-y-1">
                    <p className="text-sm font-semibold text-foreground">
                        {pagination.total} position
                        {pagination.total === 1 ? '' : 's'}
                        {selectedDepartment
                            ? ` in ${selectedDepartment.name}`
                            : ''}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {selectedDepartment
                            ? 'Includes positions in this department and its sub-departments.'
                            : hasSearchOrFilters
                              ? 'Results match your current search and filters.'
                              : 'Showing all positions for the active company.'}
                    </p>
                </div>
            }
            search={{
                placeholder:
                    'Search by title, description, grade, or attachment…',
                value: list.searchInput,
                onChange: list.onSearchChange,
                right: (
                    <>
                        {department_tree.length > 0 ? (
                            <DepartmentFilterControls
                                department_tree={department_tree}
                                department_tree_selected_id={
                                    department_tree_selected_id
                                }
                                department_tree_selected_position_id={null}
                                showPositions={false}
                                onSelectDepartment={(id) =>
                                    setDepartmentFilter(
                                        id != null ? String(id) : '',
                                    )
                                }
                            />
                        ) : null}
                        {crud.view && crud.setView ? (
                            <ViewToggle
                                value={crud.view}
                                onChange={crud.setView}
                                showTreeView={true}
                            />
                        ) : null}
                    </>
                ),
            }}
            filtersButton={{
                onClick: () => crud.setIsFiltersOpen(true),
                activeFiltersCount,
            }}
            pagination={
                <Pagination {...list.paginationProps} label="positions" />
            }
        >
            <PositionActiveFilters
                filters={filters}
                search={list.searchInput}
                departmentName={selectedDepartment?.name ?? null}
                onClearSearch={() => list.onSearchChange('')}
                onChange={handleFiltersChange}
                onClearAll={clearAllListFilters}
                className="mb-6"
            />

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
                <OrganizationDataTable minWidth="min-w-[1280px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Position
                            </DataTableHead>
                            <DataTableHead>Department</DataTableHead>
                            <DataTableHead>Type</DataTableHead>
                            <DataTableHead>Grade</DataTableHead>
                            <DataTableHead>Tour days</DataTableHead>
                            <DataTableHead>Min salary</DataTableHead>
                            <DataTableHead>Max salary</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead>Attachment</DataTableHead>
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
                                    <div className="space-y-1">
                                        <div>{position.title}</div>
                                        {position.description ? (
                                            <div className="line-clamp-1 text-xs font-medium text-muted-foreground">
                                                {position.description}
                                            </div>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.department?.name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant="secondary"
                                        className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider uppercase dark:border-white/10 dark:bg-white/5"
                                    >
                                        {position.is_crew_position
                                            ? 'Crew'
                                            : 'Shore'}
                                    </Badge>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.grade ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {position.max_tour_of_duty_days ?? '—'}
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
                                <TableCell className={dataTableCellClass()}>
                                    {position.attachment?.original_name ?? '—'}
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
                <EmptyState
                    icon={
                        <Briefcase className="mx-auto mb-3 h-8 w-8 text-muted-foreground/70" />
                    }
                    title={
                        hasSearchOrFilters
                            ? 'No positions match these filters'
                            : 'No positions yet'
                    }
                    description={
                        hasSearchOrFilters
                            ? 'Try another department, clear filters, or search by a different title.'
                            : 'Create a position or import a CSV export from another company environment.'
                    }
                    action={
                        hasSearchOrFilters ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={clearAllListFilters}
                            >
                                Clear search & filters
                            </Button>
                        ) : canCreate ? (
                            <Button type="button" onClick={handleAdd}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Position
                            </Button>
                        ) : null
                    }
                />
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

            <PositionImportDialog
                open={importOpen}
                onOpenChange={setImportOpen}
            />
        </OrganizationListPageShell>
    );
}
