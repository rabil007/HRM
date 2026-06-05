import { Link, router } from '@inertiajs/react';
import { Eye, Info, RefreshCw } from 'lucide-react';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import type { HikvisionDevice } from './types';

type Props = {
    devices: HikvisionDevice[];
    pagination: PaginationMeta;
    isConfigured: boolean;
    lastSyncedAt: string | null;
    can: {
        sync: boolean;
    };
};

function channelCount(detail: HikvisionDevice['detail'], key: string): number {
    if (!detail || !Array.isArray(detail[key as keyof typeof detail])) {
        return 0;
    }

    return (detail[key as keyof typeof detail] as unknown[]).length;
}

export function HikvisionDevicesContent({
    devices,
    pagination,
    isConfigured,
    lastSyncedAt,
    can,
}: Props) {
    const list = useServerPaginationFilters({
        url: '/hikvision/devices',
        search: '',
        filters: {},
        pagination,
    });
    const [syncing, setSyncing] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState<HikvisionDevice | null>(null);

    const handleSync = () => {
        if (!can.sync || !isConfigured || syncing) {
            return;
        }

        setSyncing(true);

        router.post(
            '/hikvision/devices/sync',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Hikvision devices synced successfully.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.sync === 'string'
                            ? errors.sync
                            : 'Failed to sync Hikvision devices.';
                    toast.error(message);
                },
                onFinish: () => {
                    setSyncing(false);
                },
            },
        );
    };

    return (
        <Main>
            <PageHeader
                title="Hikvision Devices"
                description="Access and encoding devices synced from Hik-Connect."
                right={
                    can.sync ? (
                        <Button
                            type="button"
                            className="rounded-xl"
                            disabled={!isConfigured || syncing}
                            onClick={handleSync}
                        >
                            {syncing ? <Spinner className="mr-2" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                            Sync
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
                        before syncing devices.
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="mb-6 text-sm text-muted-foreground">
                    Last synced:{' '}
                    <span className="font-medium text-foreground">
                        {lastSyncedAt ? formatDisplayDateTime(lastSyncedAt) : 'Never synced'}
                    </span>
                </p>
            )}

            {devices.length === 0 ? (
                <EmptyState
                    title="No devices synced yet"
                    description="Click Sync to fetch devices from the Hikvision API."
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[900px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Serial No.</DataTableHead>
                                <DataTableHead>Category</DataTableHead>
                                <DataTableHead>Type</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead>Last synced</DataTableHead>
                                <DataTableHead className="text-right">Detail</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {devices.map((device) => (
                                <TableRow key={device.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellPrimaryClass}>
                                        {device.name ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        <span className="font-mono text-xs">{device.serial_no}</span>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>{device.category ?? '—'}</TableCell>
                                    <TableCell className={dataTableCellClass}>{device.type ?? '—'}</TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {device.online_status === 1 ? (
                                            <Badge variant="outline">Online</Badge>
                                        ) : (
                                            <Badge variant="secondary">Offline</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDateTime(device.synced_at)}
                                    </TableCell>
                                    <TableCell className={`${dataTableCellClass} text-right`}>
                                        {device.detail ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setSelectedDevice(device)}
                                            >
                                                <Eye className="mr-1 h-4 w-4" />
                                                View
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination
                        className="mt-6"
                        pagination={pagination}
                        onPageChange={list.setPage}
                        onPerPageChange={list.setPerPage}
                    />
                </>
            )}

            <Dialog open={selectedDevice !== null} onOpenChange={(open) => !open && setSelectedDevice(null)}>
                <DialogContent className="max-h-[80vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{selectedDevice?.name ?? 'Device detail'}</DialogTitle>
                        <DialogDescription>
                            Serial: {selectedDevice?.serial_no}
                        </DialogDescription>
                    </DialogHeader>
                    {selectedDevice?.detail ? (
                        <div className="space-y-3 text-sm">
                            <p>
                                <span className="font-medium">Camera channels:</span>{' '}
                                {channelCount(selectedDevice.detail, 'cameraChannel')}
                            </p>
                            <p>
                                <span className="font-medium">Door channels:</span>{' '}
                                {channelCount(selectedDevice.detail, 'doorChannel')}
                            </p>
                            <p>
                                <span className="font-medium">Alarm inputs:</span>{' '}
                                {channelCount(selectedDevice.detail, 'alarmInputChannel')}
                            </p>
                            <p>
                                <span className="font-medium">Alarm outputs:</span>{' '}
                                {channelCount(selectedDevice.detail, 'alarmOutputChannel')}
                            </p>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </Main>
    );
}
