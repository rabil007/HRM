import { Link, router, usePoll } from '@inertiajs/react';
import { Download, Filter, Info, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import type {
    HikvisionAccessEvent,
    HikvisionAccessEventFilters,
    HikvisionAttendanceStatusOption,
    HikvisionEventsFetchStatus,
} from './types';

type Props = {
    events: HikvisionAccessEvent[];
    pagination: PaginationMeta;
    filters: HikvisionAccessEventFilters;
    attendanceStatusOptions: HikvisionAttendanceStatusOption[];
    deviceOptions: HikvisionAttendanceStatusOption[];
    isConfigured: boolean;
    lastFetchedAt: string | null;
    fetchStatus: HikvisionEventsFetchStatus;
    fetchMessage: string | null;
    can: {
        fetch: boolean;
    };
};

function formatVerifyMode(mode: string | null): string {
    if (!mode) {
        return '—';
    }

    return mode
        .replace(/([A-Z])/g, ' $1')
        .replace(/^./, (c) => c.toUpperCase())
        .trim();
}

function formatTransactionSource(source: string | null): string {
    if (!source) {
        return '—';
    }

    if (source === 'device') {
        return 'Device';
    }

    if (source === 'mobile_app') {
        return 'Mobile App';
    }

    if (source === 'correction') {
        return 'Correction';
    }

    if (source === 'not_required') {
        return 'Not required';
    }

    return 'Unknown';
}

function formatAttendanceStatus(status: string | null): string {
    if (!status) {
        return '—';
    }

    if (status === 'checkIn') {
        return 'Check in';
    }

    if (status === 'checkOut') {
        return 'Check out';
    }

    return status;
}

function isFetchProcessing(status: HikvisionEventsFetchStatus): boolean {
    return status === 'queued' || status === 'running';
}

function hasActiveFilters(filters: HikvisionAccessEventFilters): boolean {
    return Boolean(
        filters.search ||
            filters.date_from ||
            filters.date_to ||
            filters.attendance_status ||
            filters.device,
    );
}

export function HikvisionAccessEventsContent({
    events,
    pagination,
    filters,
    attendanceStatusOptions,
    deviceOptions,
    isConfigured,
    lastFetchedAt,
    fetchStatus,
    fetchMessage,
    can,
}: Props) {
    const list = useServerPaginationFilters({
        url: '/hikvision/access-events',
        search: filters.search,
        filters: {
            date_from: filters.date_from,
            date_to: filters.date_to,
            attendance_status: filters.attendance_status,
            device: filters.device,
        },
        pagination,
    });
    const isProcessing = isFetchProcessing(fetchStatus);
    const previousFetchStatus = useRef(fetchStatus);
    const filtersActive = hasActiveFilters(filters);

    const activeFilterCount = useMemo(
        () =>
            [
                filters.search,
                filters.date_from,
                filters.date_to,
                filters.attendance_status,
                filters.device,
            ].filter(Boolean).length,
        [filters],
    );

    const { start, stop } = usePoll(
        5000,
        {
            only: [
                'events',
                'pagination',
                'filters',
                'device_options',
                'last_fetched_at',
                'fetch_status',
                'fetch_message',
            ],
        },
        {
            autoStart: false,
        },
    );

    useEffect(() => {
        if (!isProcessing) {
            stop();

            return;
        }

        start();

        return () => {
            stop();
        };
    }, [isProcessing, start, stop]);

    useEffect(() => {
        const previousStatus = previousFetchStatus.current;

        if (isFetchProcessing(previousStatus) && fetchStatus === 'completed' && fetchMessage) {
            toast.success(fetchMessage);
        }

        if (isFetchProcessing(previousStatus) && fetchStatus === 'failed' && fetchMessage) {
            toast.error(fetchMessage);
        }

        previousFetchStatus.current = fetchStatus;
    }, [fetchStatus, fetchMessage]);

    const applyFilters = (next: Partial<HikvisionAccessEventFilters>) => {
        list.applyFilters({
            search: next.search ?? filters.search,
            date_from: next.date_from ?? filters.date_from,
            date_to: next.date_to ?? filters.date_to,
            attendance_status: next.attendance_status ?? filters.attendance_status,
            device: next.device ?? filters.device,
        });
    };

    const clearFilters = () => {
        list.visit({
            search: null,
            date_from: null,
            date_to: null,
            attendance_status: null,
            device: null,
            page: null,
        });
    };

    const handleFetch = () => {
        if (!can.fetch || !isConfigured || isProcessing) {
            return;
        }

        router.post(
            '/hikvision/access-events/fetch',
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    const message =
                        typeof errors.fetch === 'string'
                            ? errors.fetch
                            : 'Failed to start Hikvision access records fetch.';
                    toast.error(message);
                },
            },
        );
    };

    return (
        <Main>
            <PageHeader
                title="Hikvision Access Events"
                description="Door check-ins and mobile app attendance from Hik-Connect."
                right={
                    can.fetch ? (
                        <Button
                            type="button"
                            className="rounded-xl"
                            disabled={!isConfigured || isProcessing}
                            onClick={handleFetch}
                        >
                            {isProcessing ? <Spinner className="mr-2" /> : <Download className="mr-2 h-4 w-4" />}
                            {isProcessing ? 'Fetching…' : 'Fetch today'}
                        </Button>
                    ) : null
                }
            />

            {isConfigured ? (
                <Alert className="mb-6 border-sky-500/20 bg-sky-500/5">
                    <Info className="h-4 w-4" />
                    <AlertTitle>How records are fetched</AlertTitle>
                    <AlertDescription className="space-y-2">
                        <p>
                            Fetching loads <span className="font-medium text-foreground">today&apos;s</span>{' '}
                            records only: door device check-ins and mobile app check-in/out from
                            Hik-Connect.
                        </p>
                        <p>
                            Mobile app attendance for the current day is processed by Hik-Connect
                            after the working day ends. If today&apos;s mobile records are missing,
                            fetch again later or the next day once Hik-Connect has processed them.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            {!isConfigured ? (
                <Alert className="mb-6 border-amber-500/20 bg-amber-500/5">
                    <Info className="h-4 w-4" />
                    <AlertTitle>Hikvision not configured</AlertTitle>
                    <AlertDescription>
                        Add your API credentials in{' '}
                        <Link
                            href="/settings/application?tab=hikvision"
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Application settings → Hikvision
                        </Link>{' '}
                        before fetching records.
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="mb-6 text-sm text-muted-foreground">
                    Last fetched:{' '}
                    <span className="font-medium text-foreground">
                        {lastFetchedAt ? formatDisplayDateTime(lastFetchedAt) : 'Never fetched'}
                    </span>
                    {isProcessing ? (
                        <span className="ml-2 text-primary">Fetching in background…</span>
                    ) : null}
                </p>
            )}

            <Card className="mb-6 border-white/5 bg-white/3">
                <CardContent className="p-5">
                    <div className="mb-4 flex items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                            Filters
                        </span>
                        {activeFilterCount > 0 ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                {activeFilterCount} active
                            </Badge>
                        ) : null}
                        {activeFilterCount > 0 ? (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground/50 transition-colors hover:text-foreground"
                            >
                                <X className="h-3 w-3" />
                                Clear all
                            </button>
                        ) : null}
                    </div>

                    <div className="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_9.5rem_9.5rem_10rem_11rem]">
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="access-events-search"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                Name
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                                <Input
                                    id="access-events-search"
                                    value={list.searchInput}
                                    onChange={(e) => list.onSearchChange(e.target.value)}
                                    placeholder="Search by name…"
                                    className="h-10 rounded-xl border-white/10 bg-white/5 pl-10 focus-visible:ring-primary/40"
                                />
                            </div>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="access-events-date-from"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                From
                            </label>
                            <Input
                                id="access-events-date-from"
                                type="date"
                                value={filters.date_from}
                                onChange={(e) => applyFilters({ date_from: e.target.value })}
                                className="h-10 w-full rounded-xl border-white/10 bg-white/5 px-3 text-sm focus-visible:ring-primary/40"
                            />
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label
                                htmlFor="access-events-date-to"
                                className="text-[11px] font-medium text-muted-foreground/60"
                            >
                                To
                            </label>
                            <Input
                                id="access-events-date-to"
                                type="date"
                                value={filters.date_to}
                                onChange={(e) => applyFilters({ date_to: e.target.value })}
                                className="h-10 w-full rounded-xl border-white/10 bg-white/5 px-3 text-sm focus-visible:ring-primary/40"
                            />
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Device</span>
                            <AppSelect
                                value={filters.device || ''}
                                onValueChange={(value) => applyFilters({ device: value })}
                                variant="dark"
                                placeholder="All devices"
                                className="h-10"
                            >
                                <AppSelectItem value="">All devices</AppSelectItem>
                                {deviceOptions.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="text-[11px] font-medium text-muted-foreground/60">Status</span>
                            <AppSelect
                                value={filters.attendance_status || ''}
                                onValueChange={(value) =>
                                    applyFilters({ attendance_status: value })
                                }
                                variant="dark"
                                placeholder="All statuses"
                                className="h-10"
                            >
                                <AppSelectItem value="">All statuses</AppSelectItem>
                                {attendanceStatusOptions.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {events.length === 0 ? (
                <EmptyState
                    title={filtersActive ? 'No matching access records' : 'No access records yet'}
                    description={
                        filtersActive
                            ? 'Try adjusting your filters or fetch today’s records from your door devices.'
                            : 'Click Fetch today to pull access control events from your door devices.'
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[1200px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Time</DataTableHead>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Photo</DataTableHead>
                                <DataTableHead>Device</DataTableHead>
                                <DataTableHead>Door</DataTableHead>
                                <DataTableHead>Card reader</DataTableHead>
                                <DataTableHead>Authentication</DataTableHead>
                                <DataTableHead>Attendance</DataTableHead>
                                <DataTableHead>Source</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {events.map((event) => (
                                <TableRow key={event.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        {formatDisplayDateTime(event.occurrence_time)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <div className="flex flex-col gap-0.5">
                                            <span>{event.person_name ?? '—'}</span>
                                            {event.employee_name ? (
                                                <Link
                                                    href={`/organization/employees/${event.employee_id}`}
                                                    className="text-xs text-primary hover:underline"
                                                >
                                                    {event.employee_name}
                                                </Link>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.snap_urls.length > 0 ? (
                                            <img
                                                src={event.snap_urls[0]}
                                                alt=""
                                                className="h-10 w-10 rounded-md object-cover"
                                            />
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.device_name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.resource_name ?? (event.door_no ? `Door ${event.door_no}` : '—')}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.card_reader_no ? `Reader ${event.card_reader_no}` : '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <span className="text-xs">
                                            {formatVerifyMode(event.verify_mode)}
                                        </span>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.attendance_status ? (
                                            <Badge variant="outline">
                                                {formatAttendanceStatus(event.attendance_status)}
                                            </Badge>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.transaction_source ? (
                                            <Badge variant="secondary">
                                                {formatTransactionSource(event.transaction_source)}
                                            </Badge>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <div className="mt-6">
                        <Pagination {...list.paginationProps} label="access records" />
                    </div>
                </>
            )}
        </Main>
    );
}
