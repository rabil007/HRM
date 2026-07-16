import { Filter, Loader2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { buildSeaServiceEmployeeUrl } from '@/features/organization/sea-services/build-sea-service-employee-url';
import { SeaServicesFiltersSheet } from '@/features/organization/sea-services/components/sea-services-filters-sheet';
import type { SeaServiceSheetFilters } from '@/features/organization/sea-services/components/sea-services-filters-sheet';
import { SeaServiceManagementDialogs } from '@/features/organization/sea-services/sea-service-management-dialogs';
import { SeaServicesImportDialog } from '@/features/organization/sea-services/sea-services-import-dialog';
import { SeaServicesSummaryCards } from '@/features/organization/sea-services/sea-services-summary-cards';
import { SeaServicesTableRow } from '@/features/organization/sea-services/sea-services-table-row';
import { buildSeaServiceShowUrl } from '@/features/organization/sea-services/shared/sea-service-show-url';
import type {
    SeaServiceListItem,
    SeaServicesIndexProps,
} from '@/features/organization/sea-services/types';
import { useSeaServicesIndexFilters } from '@/features/organization/sea-services/use-sea-services-index-filters';
import type { SeaServiceSummaryFilter } from '@/features/organization/sea-services/use-sea-services-index-filters';
import { seaServices } from '@/routes/organization';
import { exportMethod as exportSeaServices } from '@/routes/organization/sea-services';

export function SeaServicesContent({
    summary,
    search: initialSearch,
    vessel_id: initialVesselId,
    vessel_type_id: initialVesselTypeId,
    rank_id: initialRankId,
    client_id: initialClientId,
    offshore: initialOffshore,
    active: initialActive,
    start_date: initialStartDate,
    end_date: initialEndDate,
    branch_id: initialBranchId,
    department_id: initialDepartmentId,
    department_tree,
    department_tree_selected_id,
    sea_services: seaServiceRows,
    vessel_types,
    vessels,
    ranks,
    clients,
    pagination,
    can,
}: SeaServicesIndexProps) {
    const [editSeaService, setEditSeaService] =
        useState<SeaServiceListItem | null>(null);
    const [deleteSeaServiceId, setDeleteSeaServiceId] = useState<number | null>(
        null,
    );
    const [managementEmployeeId, setManagementEmployeeId] = useState<
        number | null
    >(null);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);

    const sheetFilters: SeaServiceSheetFilters = {
        vessel_id: initialVesselId,
        vessel_type_id: initialVesselTypeId,
        rank_id: initialRankId,
        client_id: initialClientId,
        start_date: initialStartDate,
        end_date: initialEndDate,
    };

    const activeFiltersCount = [
        initialVesselId,
        initialVesselTypeId,
        initialRankId,
        initialClientId,
        initialStartDate,
        initialEndDate,
    ].filter(Boolean).length;

    const activeSummaryFilter: SeaServiceSummaryFilter =
        initialActive === '1' || initialActive === 'true'
            ? 'active'
            : initialOffshore === 'offshore' || initialOffshore === 'shore'
              ? initialOffshore
              : '';

    const {
        searchInput,
        isSearching,
        onSearchChange,
        onSummaryFilterChange,
        onSheetFiltersChange,
        onDepartmentChange,
        onPageChange,
    } = useSeaServicesIndexFilters({
        url: seaServices.url(),
        initialSearch,
        initialVesselId,
        initialVesselTypeId,
        initialRankId,
        initialClientId,
        initialOffshore,
        initialActive,
        initialStartDate,
        initialEndDate,
        initialBranchId,
        initialDepartmentId,
        perPage: pagination.per_page,
    });

    const backContext = useMemo(
        () => ({
            from: 'index' as const,
            search: initialSearch,
            vessel_id: initialVesselId,
            vessel_type_id: initialVesselTypeId,
            rank_id: initialRankId,
            client_id: initialClientId,
            offshore: initialOffshore,
            active: initialActive,
            start_date: initialStartDate,
            end_date: initialEndDate,
            branch_id: initialBranchId,
            department_id: initialDepartmentId,
            page: pagination.current_page,
        }),
        [
            initialSearch,
            initialVesselId,
            initialVesselTypeId,
            initialRankId,
            initialClientId,
            initialOffshore,
            initialActive,
            initialStartDate,
            initialEndDate,
            initialBranchId,
            initialDepartmentId,
            pagination.current_page,
        ],
    );

    const handleEdit = (row: SeaServiceListItem) => {
        setManagementEmployeeId(row.employee_id);
        setEditSeaService(row);
    };

    const handleDelete = (row: SeaServiceListItem) => {
        setManagementEmployeeId(row.employee_id);
        setDeleteSeaServiceId(row.id);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        return exportSeaServices.url({
            query: {
                search: initialSearch || undefined,
                vessel_id: initialVesselId || undefined,
                vessel_type_id: initialVesselTypeId || undefined,
                rank_id: initialRankId || undefined,
                client_id: initialClientId || undefined,
                offshore: initialOffshore || undefined,
                active: initialActive || undefined,
                start_date: initialStartDate || undefined,
                end_date: initialEndDate || undefined,
                branch_id: initialBranchId || undefined,
                department_id: initialDepartmentId || undefined,
                format,
            },
        });
    };

    return (
        <Main>
            <PageHeader
                title="Sea Services"
                right={
                    <div className="flex items-center gap-2">
                        {can.import ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsImportDialogOpen(true)}
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import
                            </Button>
                        ) : null}
                        <ExportMenu getUrl={getExportUrl} />
                    </div>
                }
            />

            <SeaServicesSummaryCards
                summary={summary}
                activeFilter={activeSummaryFilter}
                onSelect={onSummaryFilterChange}
            />

            <SearchBar
                placeholder="Search employee, vessel, rank or client..."
                value={searchInput}
                onChange={onSearchChange}
                right={
                    <div className="flex items-center gap-3">
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}

                        <DepartmentFilterControls
                            department_tree={department_tree}
                            department_tree_selected_id={
                                department_tree_selected_id
                            }
                            department_tree_selected_position_id={null}
                            onSelectDepartment={onDepartmentChange}
                            onSelectPosition={(_, departmentId) =>
                                onDepartmentChange(departmentId)
                            }
                        />

                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
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
                    </div>
                }
            />

            {seaServiceRows.length === 0 ? (
                <EmptyState
                    title="No sea services found"
                    description="Try adjusting your search or filters."
                />
            ) : (
                <>
                    <OrganizationDataTable
                        minWidth="min-w-[1320px]"
                        tableClassName="table-fixed"
                    >
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-[240px]">
                                    Employee
                                </DataTableHead>
                                <DataTableHead className="w-[180px]">
                                    Vessel
                                </DataTableHead>
                                <DataTableHead className="w-[140px]">
                                    Rank
                                </DataTableHead>
                                <DataTableHead className="w-[140px]">
                                    Client
                                </DataTableHead>
                                <DataTableHead className="w-[110px]">
                                    Start
                                </DataTableHead>
                                <DataTableHead className="w-[110px]">
                                    End
                                </DataTableHead>
                                <DataTableHead className="w-[100px]">
                                    Duration
                                </DataTableHead>
                                <DataTableHead className="w-[110px]">
                                    Type
                                </DataTableHead>
                                <DataTableHead className="w-[140px] text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {seaServiceRows.map((row) => (
                                <SeaServicesTableRow
                                    key={row.id}
                                    seaService={row}
                                    viewHref={buildSeaServiceShowUrl(
                                        row.id,
                                        backContext,
                                    )}
                                    browseHref={buildSeaServiceEmployeeUrl(
                                        row.employee_id,
                                        backContext,
                                    )}
                                    canUpdate={can.update}
                                    canDelete={can.delete}
                                    onEdit={handleEdit}
                                    onDelete={handleDelete}
                                />
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    {pagination.last_page > 1 ? (
                        <div className="mt-4">
                            <Pagination
                                currentPage={pagination.current_page}
                                lastPage={pagination.last_page}
                                from={pagination.from}
                                to={pagination.to}
                                total={pagination.total}
                                perPage={pagination.per_page}
                                onPageChange={onPageChange}
                                label="sea services"
                            />
                        </div>
                    ) : null}
                </>
            )}

            {managementEmployeeId !== null ? (
                <SeaServiceManagementDialogs
                    employeeId={managementEmployeeId}
                    vesselTypes={vessel_types}
                    vessels={vessels}
                    ranks={ranks}
                    clients={clients}
                    editSeaService={editSeaService}
                    onEditSeaServiceChange={(row) =>
                        setEditSeaService(row as SeaServiceListItem | null)
                    }
                    deleteSeaServiceId={deleteSeaServiceId}
                    onDeleteSeaServiceIdChange={setDeleteSeaServiceId}
                    partialReloadKeys={[
                        'sea_services',
                        'summary',
                        'pagination',
                    ]}
                />
            ) : null}

            <SeaServicesFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                vesselTypes={vessel_types}
                vessels={vessels}
                ranks={ranks}
                clients={clients}
                value={sheetFilters}
                onChange={onSheetFiltersChange}
                onReset={() =>
                    onSheetFiltersChange({
                        vessel_id: '',
                        vessel_type_id: '',
                        rank_id: '',
                        client_id: '',
                        start_date: '',
                        end_date: '',
                    })
                }
            />

            <SeaServicesImportDialog
                open={isImportDialogOpen}
                onOpenChange={setIsImportDialogOpen}
            />
        </Main>
    );
}
