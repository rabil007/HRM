import { FolderTree, X } from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import { DepartmentEmployeeTree } from '@/features/organization/employees/components/department-employee-tree';

import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { BulkEmailFilter } from '../types';

type Filters = {
    department_id: string;
    position_id: string;
    company_visa_type_id: string;
    search: string;
};

export function EmployeeFilters({
    searchInput,
    onSearchChange,
    filters,
    onFiltersChange,
    emailFilter,
    onEmailFilterChange,
    companyVisaTypes,
    departmentTree,
    departmentTreeSelectedId,
    departmentTreeSelectedPositionId,
    activeFilterCount,
    onClearFilters,
}: {
    searchInput: string;
    onSearchChange: (value: string) => void;
    filters: Filters;
    onFiltersChange: (filters: Filters) => void;
    emailFilter: BulkEmailFilter;
    onEmailFilterChange: (filter: BulkEmailFilter) => void;
    companyVisaTypes: Array<{ id: number; name: string }>;
    departmentTree: DepartmentTreeNode[];
    departmentTreeSelectedId: number | null;
    departmentTreeSelectedPositionId: number | null;
    activeFilterCount: number;
    onClearFilters: () => void;
}) {
    const [deptPopoverOpen, setDeptPopoverOpen] = useState(false);
    const [deptSheetOpen, setDeptSheetOpen] = useState(false);

    const deptSelectionCount =
        (filters.department_id ? 1 : 0) + (filters.position_id ? 1 : 0);

    const selectedSponsorName = companyVisaTypes.find(
        (sponsor) => String(sponsor.id) === filters.company_visa_type_id,
    )?.name;

    return (
        <div className="space-y-4">
            <h3 className="text-sm font-medium text-foreground">
                Choose Employees
            </h3>

            {/* Search and filters */}
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                <SearchBar
                    placeholder="Search employees by name or employee no…"
                    value={searchInput}
                    onChange={onSearchChange}
                    className="mb-0 flex-1"
                />

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {/* Desktop: Departments popover */}
                    <Popover
                        open={deptPopoverOpen}
                        onOpenChange={setDeptPopoverOpen}
                    >
                        <PopoverTrigger asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                className="hidden h-12 rounded-xl glass-card px-5 hover:bg-accent lg:flex"
                            >
                                <FolderTree className="mr-2 h-4 w-4" />
                                Departments
                                {deptSelectionCount ? (
                                    <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                        {deptSelectionCount}
                                    </span>
                                ) : null}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="start"
                            className="w-72 glass-card border-border p-3 dark:border-white/6"
                        >
                            <DepartmentEmployeeTree
                                nodes={departmentTree}
                                selectedDepartmentId={departmentTreeSelectedId}
                                selectedPositionId={
                                    departmentTreeSelectedPositionId
                                }
                                onSelectDepartment={(
                                    departmentId: number | null,
                                ) => {
                                    onFiltersChange({
                                        ...filters,
                                        department_id: departmentId
                                            ? String(departmentId)
                                            : '',
                                        position_id: '',
                                    });
                                    setDeptPopoverOpen(false);
                                }}
                                onSelectPosition={(
                                    positionId: number,
                                    departmentId: number,
                                ) => {
                                    onFiltersChange({
                                        ...filters,
                                        department_id: String(departmentId),
                                        position_id: String(positionId),
                                    });
                                    setDeptPopoverOpen(false);
                                }}
                            />
                        </PopoverContent>
                    </Popover>

                    {/* Mobile: Departments sheet */}
                    <Sheet open={deptSheetOpen} onOpenChange={setDeptSheetOpen}>
                        <SheetTrigger asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                className="h-12 rounded-xl glass-card px-5 hover:bg-accent lg:hidden"
                            >
                                <FolderTree className="mr-2 h-4 w-4" />
                                Departments
                                {deptSelectionCount ? (
                                    <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                        {deptSelectionCount}
                                    </span>
                                ) : null}
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="bottom" className="max-h-[80dvh]">
                            <div className="overflow-y-auto py-4">
                                <DepartmentEmployeeTree
                                    nodes={departmentTree}
                                    selectedDepartmentId={
                                        departmentTreeSelectedId
                                    }
                                    selectedPositionId={
                                        departmentTreeSelectedPositionId
                                    }
                                    onSelectDepartment={(
                                        departmentId: number | null,
                                    ) => {
                                        onFiltersChange({
                                            ...filters,
                                            department_id: departmentId
                                                ? String(departmentId)
                                                : '',
                                            position_id: '',
                                        });
                                        setDeptSheetOpen(false);
                                    }}
                                    onSelectPosition={(
                                        positionId: number,
                                        departmentId: number,
                                    ) => {
                                        onFiltersChange({
                                            ...filters,
                                            department_id: String(departmentId),
                                            position_id: String(positionId),
                                        });
                                        setDeptSheetOpen(false);
                                    }}
                                />
                            </div>
                        </SheetContent>
                    </Sheet>

                    <AppSelect
                        value={filters.company_visa_type_id}
                        onValueChange={(value) =>
                            onFiltersChange({
                                ...filters,
                                company_visa_type_id: value,
                            })
                        }
                        placeholder="All sponsors"
                        className="h-12 w-full rounded-xl glass-card sm:w-56"
                    >
                        <AppSelectItem value="">All sponsors</AppSelectItem>
                        {companyVisaTypes.map((sponsor) => (
                            <AppSelectItem
                                key={sponsor.id}
                                value={String(sponsor.id)}
                            >
                                {sponsor.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>

                    <AppSelect
                        value={emailFilter}
                        onValueChange={(value) =>
                            onEmailFilterChange(value as BulkEmailFilter)
                        }
                        className="h-12 w-full rounded-xl glass-card sm:w-56"
                    >
                        <AppSelectItem value="all">
                            All email status
                        </AppSelectItem>
                        <AppSelectItem value="emailed">Emailed</AppSelectItem>
                        <AppSelectItem value="not_emailed">
                            Not emailed
                        </AppSelectItem>
                    </AppSelect>

                    {activeFilterCount > 0 ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="h-12 rounded-xl glass-card px-4 hover:bg-accent"
                            onClick={onClearFilters}
                        >
                            <X className="mr-2 h-4 w-4" />
                            Clear all
                        </Button>
                    ) : null}
                </div>
            </div>

            {/* Active filter chips */}
            {activeFilterCount > 0 ? (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-muted-foreground/80">
                        Active filters
                    </span>

                    {filters.department_id || filters.position_id ? (
                        <Badge
                            variant="outline"
                            className="gap-1 pr-1 pl-2.5 font-normal"
                        >
                            {filters.position_id
                                ? 'Department · position'
                                : 'Department'}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() =>
                                    onFiltersChange({
                                        ...filters,
                                        department_id: '',
                                        position_id: '',
                                    })
                                }
                                aria-label="Clear department filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {filters.company_visa_type_id && selectedSponsorName ? (
                        <Badge
                            variant="outline"
                            className="gap-1 pr-1 pl-2.5 font-normal"
                        >
                            Sponsor: {selectedSponsorName}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() =>
                                    onFiltersChange({
                                        ...filters,
                                        company_visa_type_id: '',
                                    })
                                }
                                aria-label="Clear sponsor filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {searchInput.trim() ? (
                        <Badge
                            variant="outline"
                            className="gap-1 pr-1 pl-2.5 font-normal"
                        >
                            Search: {searchInput.trim()}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() => onSearchChange('')}
                                aria-label="Clear search"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {emailFilter === 'emailed' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-sky-500/25 bg-sky-500/5 pr-1 pl-2.5 font-normal"
                        >
                            Emailed only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-sky-500/10"
                                onClick={() => onEmailFilterChange('all')}
                                aria-label="Clear emailed filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {emailFilter === 'not_emailed' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-dashed pr-1 pl-2.5 font-normal"
                        >
                            Not emailed only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() => onEmailFilterChange('all')}
                                aria-label="Clear not emailed filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                        onClick={onClearFilters}
                    >
                        Clear all
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
