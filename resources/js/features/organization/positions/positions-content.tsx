import { router, useForm } from '@inertiajs/react';
import { Edit2, Eye, Filter, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
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
    const [view, setView] = useViewPreference('positions:view', 'grid');
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
                onError: () => {
                    toast.error('Failed to update status. Please try again.');
                },
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

                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {filteredPositions.map((position) => (
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
                <Card className="glass-card w-full overflow-hidden">
                    <CardContent className="w-full p-0 min-h-[360px]">
                        <Table className="min-w-[980px]">
                            <TableHeader>
                                <TableRow className="border-border/60">
                                    <TableHead className="pl-4">Position</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Grade</TableHead>
                                    <TableHead>Min</TableHead>
                                    <TableHead>Max</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredPositions.map((position) => (
                                    <TableRow
                                        key={position.id}
                                        className="border-border/40 cursor-pointer hover:bg-accent/40"
                                        onClick={() => router.visit(`/organization/positions/${position.id}`)}
                                    >
                                        <TableCell className="pl-4 font-semibold">{position.title}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{position.department?.name ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{position.grade ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{position.min_salary ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{position.max_salary ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">
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
                                        <TableCell className="pr-4">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        router.visit(`/organization/positions/${position.id}`);
                                                    }}
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleEdit(position);
                                                    }}
                                                    title="Edit"
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleDelete(position);
                                                    }}
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

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

            <PositionDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                position={currentPosition}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}

