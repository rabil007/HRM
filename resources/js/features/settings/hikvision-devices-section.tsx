import { router } from '@inertiajs/react';
import { DoorOpen, Eye, RefreshCw } from 'lucide-react';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';

type HikvisionDeviceDetail = {
    baseInfo?: Record<string, unknown>;
    cameraChannel?: Array<Record<string, unknown>>;
    alarmInputChannel?: Array<Record<string, unknown>>;
    alarmOutputChannel?: Array<Record<string, unknown>>;
    doorChannel?: Array<Record<string, unknown>>;
    onlineStatus?: number;
} | null;

type HikvisionDevice = {
    id: number;
    hikvision_id: string;
    serial_no: string;
    name: string | null;
    category: string | null;
    type: string | null;
    online_status: number | null;
    synced_at: string | null;
    detail: HikvisionDeviceDetail;
};

export type HikvisionDevicesSectionProps = {
    devices: {
        items: HikvisionDevice[];
        last_synced_at: string | null;
        can: {
            sync: boolean;
        };
    };
    isConfigured: boolean;
};

function channelCount(detail: HikvisionDevice['detail'], key: string): number {
    if (!detail || !Array.isArray(detail[key as keyof typeof detail])) {
        return 0;
    }

    return (detail[key as keyof typeof detail] as unknown[]).length;
}

export function HikvisionDevicesSection({
    devices,
    isConfigured,
}: HikvisionDevicesSectionProps) {
    const [syncing, setSyncing] = useState(false);
    const [selectedDevice, setSelectedDevice] =
        useState<HikvisionDevice | null>(null);

    const handleSync = () => {
        if (!devices.can.sync || !isConfigured || syncing) {
            return;
        }

        setSyncing(true);

        router.post(
            '/settings/application/hikvision/devices/sync',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Hikvision devices synced successfully.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.devices_sync === 'string'
                            ? errors.devices_sync
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
        <>
            <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="space-y-5 p-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <DoorOpen className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">
                                    Synced devices
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Access and encoding devices fetched from
                                    Hik-Connect.
                                </p>
                            </div>
                        </div>

                        {devices.can.sync ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="shrink-0 rounded-xl"
                                disabled={!isConfigured || syncing}
                                onClick={handleSync}
                            >
                                {syncing ? (
                                    <Spinner className="mr-2" />
                                ) : (
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                )}
                                Sync devices
                            </Button>
                        ) : null}
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Last synced:{' '}
                        <span className="font-medium text-foreground">
                            {devices.last_synced_at
                                ? formatDisplayDateTime(devices.last_synced_at)
                                : 'Never synced'}
                        </span>
                    </p>

                    {devices.items.length === 0 ? (
                        <EmptyState
                            title="No devices synced yet"
                            description={
                                isConfigured
                                    ? 'Click Sync devices to fetch devices from the Hikvision API.'
                                    : 'Save and enable your API credentials above before syncing devices.'
                            }
                        />
                    ) : (
                        <OrganizationDataTable minWidth="min-w-[800px]">
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Name</DataTableHead>
                                    <DataTableHead>Serial No.</DataTableHead>
                                    <DataTableHead>Category</DataTableHead>
                                    <DataTableHead>Type</DataTableHead>
                                    <DataTableHead>Status</DataTableHead>
                                    <DataTableHead>Last synced</DataTableHead>
                                    <DataTableHead className="text-right">
                                        Detail
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {devices.items.map((device) => (
                                    <TableRow
                                        key={device.id}
                                        className={dataTableBodyRowClass()}
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            {device.name ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <span className="font-mono text-xs">
                                                {device.serial_no}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {device.category ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {device.type ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {device.online_status === 1 ? (
                                                <Badge variant="outline">
                                                    Online
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Offline
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {formatDisplayDateTime(
                                                device.synced_at,
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={`${dataTableCellClass} text-right`}
                                        >
                                            {device.detail ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setSelectedDevice(
                                                            device,
                                                        )
                                                    }
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
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={selectedDevice !== null}
                onOpenChange={(open) => !open && setSelectedDevice(null)}
            >
                <DialogContent className="max-h-[80vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedDevice?.name ?? 'Device detail'}
                        </DialogTitle>
                        <DialogDescription>
                            Serial: {selectedDevice?.serial_no}
                        </DialogDescription>
                    </DialogHeader>
                    {selectedDevice?.detail ? (
                        <div className="space-y-3 text-sm">
                            <p>
                                <span className="font-medium">
                                    Camera channels:
                                </span>{' '}
                                {channelCount(
                                    selectedDevice.detail,
                                    'cameraChannel',
                                )}
                            </p>
                            <p>
                                <span className="font-medium">
                                    Door channels:
                                </span>{' '}
                                {channelCount(
                                    selectedDevice.detail,
                                    'doorChannel',
                                )}
                            </p>
                            <p>
                                <span className="font-medium">
                                    Alarm inputs:
                                </span>{' '}
                                {channelCount(
                                    selectedDevice.detail,
                                    'alarmInputChannel',
                                )}
                            </p>
                            <p>
                                <span className="font-medium">
                                    Alarm outputs:
                                </span>{' '}
                                {channelCount(
                                    selectedDevice.detail,
                                    'alarmOutputChannel',
                                )}
                            </p>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}
