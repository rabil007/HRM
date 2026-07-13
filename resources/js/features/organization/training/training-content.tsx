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
import { buildTrainingEmployeeUrl } from '@/features/organization/training/build-training-employee-url';
import {
    TrainingFiltersSheet,
    type TrainingSheetFilters,
} from '@/features/organization/training/components/training-filters-sheet';
import { buildTrainingShowUrl } from '@/features/organization/training/shared/training-show-url';
import { TrainingManagementDialogs } from '@/features/organization/training/training-management-dialogs';
import { TrainingSummaryCards } from '@/features/organization/training/training-summary-cards';
import { TrainingTableRow } from '@/features/organization/training/training-table-row';
import { TrainingsImportDialog } from '@/features/organization/training/trainings-import-dialog';
import type {
    TrainingListItem,
    TrainingsIndexProps,
} from '@/features/organization/training/types';
import { useTrainingIndexFilters } from '@/features/organization/training/use-training-index-filters';
import type { TrainingItem } from '@/pages/organization/employee-page.types';
import { training } from '@/routes/organization';
import { exportMethod as exportTrainings } from '@/routes/organization/training';

function toTrainingItem(row: TrainingListItem): TrainingItem {
    return {
        id: row.id,
        course_id: row.course_id,
        course_name: row.course_name,
        issue_date: row.issue_date,
        expiry_date: row.expiry_date,
        institute_center: row.institute_center,
        country_id: row.country_id,
        country_name: row.country_name,
        certificate_url: row.certificate_url,
        created_at: row.created_at ?? '',
    };
}

export function TrainingContent({
    summary,
    expiry: initialExpiry,
    search: initialSearch,
    issue_date: initialIssueDate,
    course_id: initialCourseId,
    institute: initialInstitute,
    country_id: initialCountryId,
    branch_id: initialBranchId,
    department_id: initialDepartmentId,
    department_tree,
    department_tree_selected_id,
    trainings: trainingRows,
    pagination,
    courses,
    countries,
    can,
}: TrainingsIndexProps) {
    const [editTraining, setEditTraining] = useState<TrainingItem | null>(null);
    const [replaceTraining, setReplaceTraining] =
        useState<TrainingItem | null>(null);
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(
        null,
    );
    const [managementEmployeeId, setManagementEmployeeId] = useState<
        number | null
    >(null);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);

    const sheetFilters: TrainingSheetFilters = {
        course_id: initialCourseId,
        institute: initialInstitute,
        country_id: initialCountryId,
        issue_date: initialIssueDate,
    };

    const activeFiltersCount = [
        initialCourseId,
        initialInstitute,
        initialCountryId,
        initialIssueDate,
    ].filter(Boolean).length;

    const {
        searchInput,
        isSearching,
        onSearchChange,
        onExpiryChange,
        onSheetFiltersChange,
        onDepartmentChange,
        onPageChange,
    } = useTrainingIndexFilters({
        url: training.url(),
        initialSearch,
        initialExpiry,
        initialIssueDate,
        initialCourseId,
        initialInstitute,
        initialCountryId,
        initialBranchId,
        initialDepartmentId,
        perPage: pagination.per_page,
    });

    const backContext = useMemo(
        () => ({
            from: 'index' as const,
            search: initialSearch,
            expiry: initialExpiry,
            issue_date: initialIssueDate,
            course_id: initialCourseId,
            institute: initialInstitute,
            country_id: initialCountryId,
            branch_id: initialBranchId,
            department_id: initialDepartmentId,
            page: pagination.current_page,
        }),
        [
            initialSearch,
            initialExpiry,
            initialIssueDate,
            initialCourseId,
            initialInstitute,
            initialCountryId,
            initialBranchId,
            initialDepartmentId,
            pagination.current_page,
        ],
    );

    const bindManagementTraining = (row: TrainingListItem) => {
        setManagementEmployeeId(row.employee_id);

        return toTrainingItem(row);
    };

    const handleEdit = (row: TrainingListItem) => {
        setEditTraining(bindManagementTraining(row));
    };

    const handleReplace = (row: TrainingListItem) => {
        setReplaceTraining(bindManagementTraining(row));
    };

    const handleDelete = (row: TrainingListItem) => {
        bindManagementTraining(row);
        setDeleteTrainingId(row.id);
    };

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        return exportTrainings.url({
            query: {
                search: initialSearch || undefined,
                expiry:
                    initialExpiry !== 'all' ? initialExpiry : undefined,
                issue_date: initialIssueDate || undefined,
                course_id: initialCourseId || undefined,
                institute: initialInstitute || undefined,
                country_id: initialCountryId || undefined,
                branch_id: initialBranchId || undefined,
                department_id: initialDepartmentId || undefined,
                format,
            },
        });
    };

    return (
        <Main>
            <PageHeader
                title="Training"
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

            <TrainingSummaryCards
                summary={summary}
                activeExpiry={initialExpiry}
                onSelect={onExpiryChange}
            />

            <SearchBar
                placeholder="Search employee, course or institute..."
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

            {trainingRows.length === 0 ? (
                <EmptyState
                    title="No training found"
                    description="Try adjusting your search or filters."
                />
            ) : (
                <>
                    <OrganizationDataTable
                        minWidth="min-w-[1220px]"
                        tableClassName="table-fixed"
                    >
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-[240px]">
                                    Employee
                                </DataTableHead>
                                <DataTableHead className="w-[200px]">
                                    Course
                                </DataTableHead>
                                <DataTableHead className="w-[120px]">
                                    Issue date
                                </DataTableHead>
                                <DataTableHead className="w-[160px]">
                                    Expiry
                                </DataTableHead>
                                <DataTableHead className="w-[180px]">
                                    Institute
                                </DataTableHead>
                                <DataTableHead className="w-[120px]">
                                    Country
                                </DataTableHead>
                                <DataTableHead className="w-[180px] text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {trainingRows.map((row) => (
                                <TrainingTableRow
                                    key={row.id}
                                    training={row}
                                    viewHref={buildTrainingShowUrl(
                                        row.employee_id,
                                        row.id,
                                        backContext,
                                    )}
                                    browseHref={buildTrainingEmployeeUrl(
                                        row.employee_id,
                                        backContext,
                                    )}
                                    canUpdate={can.update}
                                    canDelete={can.delete}
                                    onEdit={handleEdit}
                                    onReplace={handleReplace}
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
                                label="trainings"
                            />
                        </div>
                    ) : null}
                </>
            )}

            {managementEmployeeId !== null ? (
                <TrainingManagementDialogs
                    employeeId={managementEmployeeId}
                    courses={courses}
                    countries={countries}
                    editTraining={editTraining}
                    onEditTrainingChange={setEditTraining}
                    replaceTraining={replaceTraining}
                    onReplaceTrainingChange={setReplaceTraining}
                    deleteTrainingId={deleteTrainingId}
                    onDeleteTrainingIdChange={setDeleteTrainingId}
                    partialReloadKeys={[
                        'trainings',
                        'summary',
                        'pagination',
                    ]}
                />
            ) : null}

            <TrainingFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                courses={courses}
                countries={countries}
                value={sheetFilters}
                onChange={onSheetFiltersChange}
                onReset={() =>
                    onSheetFiltersChange({
                        course_id: '',
                        institute: '',
                        country_id: '',
                        issue_date: '',
                    })
                }
            />

            <TrainingsImportDialog
                open={isImportDialogOpen}
                onOpenChange={setIsImportDialogOpen}
            />
        </Main>
    );
}
