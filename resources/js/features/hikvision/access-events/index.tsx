import { Link, router } from '@inertiajs/react';
import { Download, Info } from 'lucide-react';
import { useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import type { HikvisionAccessEvent } from './types';

type Props = {
    events: HikvisionAccessEvent[];
    pagination: PaginationMeta;
    isConfigured: boolean;
    lastFetchedAt: string | null;
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

export function HikvisionAccessEventsContent({
    events,
    pagination,
    isConfigured,
    lastFetchedAt,
    can,
}: Props) {
    const list = useServerPaginationFilters({
        url: '/hikvision/access-events',
        search: '',
        filters: {},
        pagination,
    });
    const [fetching, setFetching] = useState(false);

    const handleFetch = () => {
        if (!can.fetch || !isConfigured || fetching) {
            return;
        }

        setFetching(true);

        router.post(
            '/hikvision/access-events/fetch',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Hikvision access records fetched successfully.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.fetch === 'string'
                            ? errors.fetch
                            : 'Failed to fetch Hikvision access records.';
                    toast.error(message);
                },
                onFinish: () => {
                    setFetching(false);
                },
            },
        );
    };

    return (
        <Main>
            <PageHeader
                title="Hikvision Access Events"
                description="Successful door check-ins and authentications from access controller devices (today's events)."
                right={
                    can.fetch ? (
                        <Button
                            type="button"
                            className="rounded-xl"
                            disabled={!isConfigured || fetching}
                            onClick={handleFetch}
                        >
                            {fetching ? <Spinner className="mr-2" /> : <Download className="mr-2 h-4 w-4" />}
                            Fetch today
                        </Button>
                    ) : null
                }
            />

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
                </p>
            )}

            {events.length === 0 ? (
                <EmptyState
                    title="No access records yet"
                    description="Click Fetch today to pull access control events from your door devices."
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[1100px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Time</DataTableHead>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Device</DataTableHead>
                                <DataTableHead>Door</DataTableHead>
                                <DataTableHead>Card reader</DataTableHead>
                                <DataTableHead>Authentication</DataTableHead>
                                <DataTableHead>Attendance</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {events.map((event) => (
                                <TableRow key={event.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        {formatDisplayDateTime(event.occurrence_time)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {event.person_name ?? '—'}
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
                                            <Badge variant="outline">{event.attendance_status}</Badge>
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
