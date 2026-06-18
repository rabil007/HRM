import { Link, router, useForm } from '@inertiajs/react';
import { Anchor, Filter } from 'lucide-react';
import { useMemo, useState } from 'react';
import { show as vesselManningShow, update as updateVesselManning } from '@/actions/App/Http/Controllers/Organization/VesselManningController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { VesselManningFormSheet } from './components/vessel-manning-form-sheet';
import { vesselManningHasWriteActions } from './types';
import type {
    RankOption,
    VesselManningFormData,
    VesselManningItem,
    VesselManningPagePermissions,
    VesselTypeOption,
} from './types';
import { toVesselManningFormData, toVesselManningPayload } from './vessel-manning-form-utils';

export function VesselManningContent({
    vessels,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    ranks,
    vessel_types,
    can,
}: {
    vessels: VesselManningItem[];
    pagination: PaginationMeta;
    search: string;
    filters: { vessel_type_id: number | null };
    ranks: RankOption[];
    vessel_types: VesselTypeOption[];
    can: VesselManningPagePermissions;
}) {
    const list = useServerPaginationFilters({
        url: '/organization/vessel-manning',
        search: initialSearch,
        filters: {
            vessel_type_id: initialFilters.vessel_type_id
                ? String(initialFilters.vessel_type_id)
                : '',
        },
        pagination,
    });

    const [editingVessel, setEditingVessel] = useState<VesselManningItem | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);

    const form = useForm<VesselManningFormData>({
        requirements: [],
    });

    const listBackQuery = useMemo(() => {
        const query: Record<string, string> = {};

        if (initialSearch.trim() !== '') {
            query.search = initialSearch;
        }

        if (initialFilters.vessel_type_id) {
            query.vessel_type_id = String(initialFilters.vessel_type_id);
        }

        if (pagination.current_page > 1) {
            query.page = String(pagination.current_page);
        }

        if (pagination.per_page) {
            query.per_page = String(pagination.per_page);
        }

        return query;
    }, [initialFilters.vessel_type_id, initialSearch, pagination.current_page, pagination.per_page]);

    const openShow = (vesselId: number): void => {
        router.visit(
            vesselManningShow.url(
                { vessel: vesselId },
                Object.keys(listBackQuery).length > 0 ? { query: listBackQuery } : undefined,
            ),
        );
    };

    const openEdit = (vessel: VesselManningItem): void => {
        setEditingVessel(vessel);
        form.clearErrors();
        form.setData(toVesselManningFormData(vessel));
        setSheetOpen(true);
    };

    const closeSheet = (): void => {
        setSheetOpen(false);
        setEditingVessel(null);
    };

    const submit = (): void => {
        if (!editingVessel) {
            return;
        }

        form.transform((data) => toVesselManningPayload(data));
        form.put(updateVesselManning.url({ vessel: editingVessel.id }), {
            preserveScroll: true,
            onSuccess: () => closeSheet(),
        });
    };

    const hasVesselTypeFilter = Boolean(initialFilters.vessel_type_id);
    const hasActiveFilters = hasVesselTypeFilter || initialSearch.trim() !== '';

    return (
        <Main>
            <PageHeader
                kicker="Crew Operations"
                title="Vessel Manning"
                description="Define how many crew of each rank each vessel needs."
            />

            <Card className="mb-6 border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                <CardContent className="p-5">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                            Filters
                        </span>
                        {hasActiveFilters ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                Active
                            </Badge>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 lg:flex-row">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search vessels…"
                            className="mb-0 min-w-0 flex-1"
                        />

                        <AppSelect
                            value={
                                initialFilters.vessel_type_id
                                    ? String(initialFilters.vessel_type_id)
                                    : ''
                            }
                            onValueChange={(vesselTypeId) =>
                                list.applyFilters({ vessel_type_id: vesselTypeId })
                            }
                            placeholder="All vessel types"
                            variant="dark"
                            className="h-10 lg:w-64"
                        >
                            <AppSelectItem value="">All vessel types</AppSelectItem>
                            {vessel_types.map((vesselType) => (
                                <AppSelectItem key={vesselType.id} value={String(vesselType.id)}>
                                    {vesselType.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>
                </CardContent>
            </Card>

            {vessels.length === 0 ? (
                <EmptyState
                    icon={
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Anchor className="h-6 w-6 text-muted-foreground" />
                        </div>
                    }
                    title={hasActiveFilters ? 'No vessels match your filters.' : 'No vessels yet.'}
                    description={
                        hasActiveFilters
                            ? 'Try adjusting your search or vessel type filter.'
                            : 'Add vessels in Settings master data before configuring manning.'
                    }
                    action={
                        hasActiveFilters ? undefined : (
                            <Button variant="outline" asChild>
                                <Link href="/settings/master-data/vessels">Go to vessels</Link>
                            </Button>
                        )
                    }
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[960px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Vessel</DataTableHead>
                            <DataTableHead>Vessel type</DataTableHead>
                            <DataTableHead>Ranks configured</DataTableHead>
                            <DataTableHead>Total required</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {vessels.map((vessel) => (
                            <TableRow
                                key={vessel.id}
                                className={cn(dataTableBodyRowClass(), 'cursor-pointer')}
                                onClick={() => openShow(vessel.id)}
                            >
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    <div className="font-medium">{vessel.name}</div>
                                    {!vessel.is_active ? (
                                        <div className="text-xs text-muted-foreground">Inactive</div>
                                    ) : null}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.vessel_type_name ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.manning.length === 0 ? (
                                        <span className="text-muted-foreground">
                                            No ranks configured
                                        </span>
                                    ) : (
                                        <div className="flex flex-wrap gap-1.5">
                                            {vessel.manning.map((line) => (
                                                <Badge
                                                    key={line.id}
                                                    variant="secondary"
                                                    className="font-normal"
                                                >
                                                    {line.rank_name} ×{line.required_count}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.total_required > 0 ? vessel.total_required : '—'}
                                </TableCell>
                                <TableCell className={dataTableActionsCellClass()}>
                                    <ListTableCrudActions
                                        viewHref={vesselManningShow.url(
                                            { vessel: vessel.id },
                                            Object.keys(listBackQuery).length > 0
                                                ? { query: listBackQuery }
                                                : undefined,
                                        )}
                                        onEdit={
                                            can.update || can.create
                                                ? (event) => {
                                                      event.stopPropagation();
                                                      openEdit(vessel);
                                                  }
                                                : undefined
                                        }
                                        showEdit={can.update || can.create}
                                        showDelete={false}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            <Pagination {...list.paginationProps} label="vessels" />

            {vesselManningHasWriteActions(can) ? (
                <VesselManningFormSheet
                    open={sheetOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            closeSheet();
                        } else {
                            setSheetOpen(true);
                        }
                    }}
                    vessel={editingVessel}
                    ranks={ranks}
                    form={form}
                    onSubmit={submit}
                />
            ) : null}
        </Main>
    );
}
