import { useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { PositionCard } from './components/position-card';
import { PositionDeleteDialog } from './components/position-delete-dialog';
import { PositionFiltersSheet } from './components/position-filters-sheet';
import type { PositionFilters } from './components/position-filters-sheet';
import { PositionFormSheet } from './components/position-form-sheet';
import type { DepartmentOption, Position, PositionFormData } from './types';

const emptyFilters: PositionFilters = {
    department_id: '',
    status: '',
    grade: '',
};

export function PositionsContent({
    positions,
    departments,
}: {
    positions: Position[];
    departments: DepartmentOption[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentPosition, setCurrentPosition] = useState<Position | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<PositionFilters>(emptyFilters);

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
        form.setData({
            department_id: '',
            title: '',
            grade: '',
            min_salary: '',
            max_salary: '',
            status: 'active',
        });
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

    const filteredPositions = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return positions.filter((p) => {
            if (filters.department_id && String(p.department?.id ?? '') !== filters.department_id) {
                return false;
            }

            if (filters.status && (p.status ?? '') !== filters.status) {
                return false;
            }

            if (filters.grade.trim() && !(p.grade ?? '').toLowerCase().includes(filters.grade.trim().toLowerCase())) {
                return false;
            }

            if (!query) {
                return true;
            }

            return (
                p.title.toLowerCase().includes(query) ||
                (p.grade ?? '').toLowerCase().includes(query) ||
                (p.department?.name ?? '').toLowerCase().includes(query)
            );
        });
    }, [positions, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [filters.department_id, filters.status, filters.grade.trim()].filter(Boolean).length;
    }, [filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.department_id) {
            params.set('department_id', filters.department_id);
        }

        if (filters.status) {
            params.set('status', filters.status);
        }

        if (filters.grade.trim()) {
            params.set('grade', filters.grade.trim());
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
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Position
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search positions by title, grade, company, or department..."
                value={searchQuery}
                onChange={setSearchQuery}
                right={
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            className="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
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

                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
                        />
                    </>
                }
            />

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredPositions.map((position) => (
                    <PositionCard key={position.id} position={position} onEdit={handleEdit} onDelete={handleDelete} />
                ))}
            </div>

            {filteredPositions.length === 0 ? <EmptyState title="No positions found." /> : null}

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
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
            />

            <PositionDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} position={currentPosition} />
        </Main>
    );
}

