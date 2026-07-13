import { Loader2 } from 'lucide-react';
import { useMemo } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { buildTrainingEmployeeUrl } from '@/features/organization/training/build-training-employee-url';
import { buildTrainingShowUrl } from '@/features/organization/training/shared/training-show-url';
import { TrainingSummaryCards } from '@/features/organization/training/training-summary-cards';
import { TrainingTableRow } from '@/features/organization/training/training-table-row';
import type { TrainingsIndexProps } from '@/features/organization/training/types';
import { useTrainingIndexFilters } from '@/features/organization/training/use-training-index-filters';
import { training } from '@/routes/organization';

export function TrainingContent({
    summary,
    expiry: initialExpiry,
    search: initialSearch,
    branch_id: initialBranchId,
    department_id: initialDepartmentId,
    department_tree,
    department_tree_selected_id,
    trainings: trainingRows,
    pagination,
}: TrainingsIndexProps) {
    const {
        searchInput,
        isSearching,
        onSearchChange,
        onExpiryChange,
        onDepartmentChange,
        onPageChange,
    } = useTrainingIndexFilters({
        url: training.url(),
        initialSearch,
        initialExpiry,
        initialBranchId,
        initialDepartmentId,
        perPage: pagination.per_page,
    });

    const backContext = useMemo(
        () => ({
            from: 'index' as const,
            search: initialSearch,
            expiry: initialExpiry,
            branch_id: initialBranchId,
            department_id: initialDepartmentId,
            page: pagination.current_page,
        }),
        [
            initialSearch,
            initialExpiry,
            initialBranchId,
            initialDepartmentId,
            pagination.current_page,
        ],
    );

    return (
        <Main>
            <PageHeader title="Training" />

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
                        minWidth="min-w-[1180px]"
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
                                <DataTableHead className="w-[100px]">
                                    Certificate
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
        </Main>
    );
}
