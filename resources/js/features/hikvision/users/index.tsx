import { Link, router } from '@inertiajs/react';
import { Info, RefreshCw } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';
import type { HikvisionUser } from './types';

type Props = {
    users: HikvisionUser[];
    pagination: PaginationMeta;
    isConfigured: boolean;
    lastSyncedAt: string | null;
    can: {
        sync: boolean;
    };
};

export function HikvisionUsersContent({
    users,
    pagination,
    isConfigured,
    lastSyncedAt,
    can,
}: Props) {
    const list = useServerPaginationFilters({
        url: '/hikvision/users',
        search: '',
        filters: {},
        pagination,
    });
    const [syncing, setSyncing] = useState(false);

    const handleSync = () => {
        if (!can.sync || !isConfigured || syncing) {
            return;
        }

        setSyncing(true);

        router.post(
            '/hikvision/users/sync',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Hikvision users synced successfully.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.sync === 'string'
                            ? errors.sync
                            : 'Failed to sync Hikvision users.';
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
                title="Hikvision Users"
                description="Platform users synced from Hik-Connect for Teams."
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
                        before syncing users.
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

            {users.length === 0 ? (
                <EmptyState
                    title="No users synced yet"
                    description="Click Sync to fetch users from the Hikvision API."
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[640px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Hikvision ID</DataTableHead>
                                <DataTableHead>Name</DataTableHead>
                                <DataTableHead>Last synced</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((user) => (
                                <TableRow key={user.id} className={dataTableBodyRowClass}>
                                    <TableCell className={dataTableCellClass}>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {user.hikvision_id}
                                        </span>
                                    </TableCell>
                                    <TableCell className={dataTableCellPrimaryClass}>{user.name}</TableCell>
                                    <TableCell className={dataTableCellClass}>
                                        {formatDisplayDateTime(user.synced_at)}
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
        </Main>
    );
}
