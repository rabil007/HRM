import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroySeaService,
    store as storeSeaService,
    update as updateSeaService,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import type { RankOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsActionsTdClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import { SeaServiceImportDialog } from '@/pages/organization/_components/sea-service-import-dialog';
import { formatSeaServiceTotalsYmd } from '@/pages/organization/_lib/sum-sea-service-experience';
import type {
    ClientOption,
    SeaServiceItem,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

const SEA_SERVICE_RELOAD = {
    preserveScroll: true,
    only: ['sea_services'],
} as const;

function buildSeaServicePayload(data: {
    vessel_type_id: string;
    vessel_name: string;
    rank_id: string;
    total_months: string;
    total_days: string;
    grt: string;
    bhp: string;
    client_id: string;
    is_offshore: boolean;
}) {
    return {
        vessel_type_id: Number.parseInt(data.vessel_type_id, 10),
        vessel_name: data.vessel_name.trim(),
        rank_id: Number.parseInt(data.rank_id, 10),
        total_months: Math.max(0, Number.parseInt(data.total_months, 10) || 0),
        total_days: Math.max(0, Number.parseInt(data.total_days, 10) || 0),
        grt: data.grt.trim() === '' ? null : Number(data.grt),
        bhp:
            data.bhp.trim() === ''
                ? null
                : Math.max(0, Number.parseInt(data.bhp, 10) || 0),
        client_id:
            data.client_id.trim() === ''
                ? null
                : Number.parseInt(data.client_id, 10),
        is_offshore: !!data.is_offshore,
    };
}

export type EmployeeSeaServiceTabProps = {
    employeeId: number;
    sea_services: SeaServiceItem[];
    vessel_types: VesselTypeOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    employeeRankId: number | null;
    canManage: boolean;
};

export function EmployeeSeaServiceTab({
    employeeId,
    sea_services,
    vessel_types,
    ranks,
    clients,
    employeeRankId,
    canManage,
}: EmployeeSeaServiceTabProps): ReactElement {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [seaServiceImportOpen, setSeaServiceImportOpen] = useState(false);
    const [editingRow, setEditingRow] = useState<SeaServiceItem | null>(null);
    const [deleteRowId, setDeleteRowId] = useState<number | null>(null);

    const employeeForm = useForm({
        vessel_type_id: '',
        vessel_name: '',
        rank_id: '',
        total_months: '0',
        total_days: '0',
        grt: '',
        bhp: '',
        client_id: '',
        is_offshore: false,
    });

    const appliedRankTotals =
        employeeRankId != null
            ? formatSeaServiceTotalsYmd(
                  sea_services,
                  (r) => r.rank_id === employeeRankId,
              )
            : formatSeaServiceTotalsYmd(sea_services);

    const offshoreTotals = formatSeaServiceTotalsYmd(sea_services, (r) => {
        return r.is_offshore;
    });

    return (
        <TabsContent value="sea_service" className="mt-6">
            <div className="mb-4 grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border border-white/5 bg-black/10 px-4 py-3">
                    <div className="text-[11px] font-medium tracking-wide text-zinc-500 uppercase">
                        Total experience in the applied rank (in years)
                    </div>
                    <div className="mt-1 font-mono text-sm font-semibold text-zinc-100">
                        {appliedRankTotals}
                    </div>
                </div>
                <div className="rounded-xl border border-white/5 bg-black/10 px-4 py-3">
                    <div className="text-[11px] font-medium tracking-wide text-zinc-500 uppercase">
                        Offshore experience (in years)
                    </div>
                    <div className="mt-1 font-mono text-sm font-semibold text-zinc-100">
                        {offshoreTotals}
                    </div>
                </div>
            </div>

            <EmployeeRecordsPanel
                title="Sea Service"
                count={sea_services.length}
                isEmpty={sea_services.length === 0}
                emptyMessage="No sea service recorded."
                actions={
                    canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => setSeaServiceImportOpen(true)}
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => {
                                    employeeForm.reset();
                                    employeeForm.clearErrors();
                                    employeeForm.setData({
                                        vessel_type_id: '',
                                        vessel_name: '',
                                        rank_id: '',
                                        total_months: '0',
                                        total_days: '0',
                                        grt: '',
                                        bhp: '',
                                        client_id: '',
                                        is_offshore: false,
                                    });
                                    setEditingRow(null);
                                    setDialogOpen(true);
                                }}
                            >
                                + Add a line
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[1100px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Vessel type</th>
                            <th className={employeeRecordsTableThClass()}>Vessel name</th>
                            <th className={employeeRecordsTableThClass()}>Rank</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-right tabular-nums')}>
                                Total months
                            </th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-right tabular-nums')}>
                                Total days
                            </th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-right tabular-nums')}>GRT</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-right tabular-nums')}>BHP</th>
                            <th className={employeeRecordsTableThClass()}>Client</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Offshore</th>
                            {canManage ? (
                                <EmployeeRecordsActionsHeader className="min-w-[4.5rem]" />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {sea_services.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate font-medium text-zinc-100',
                                    )}
                                    title={row.vessel_type_name ?? ''}
                                >
                                    {row.vessel_type_name ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate text-zinc-300',
                                    )}
                                    title={row.vessel_name ?? ''}
                                >
                                    {row.vessel_name?.trim() ? row.vessel_name : '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[180px] truncate text-zinc-300',
                                    )}
                                    title={row.rank_name ?? ''}
                                >
                                    {row.rank_name ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right tabular-nums text-zinc-300',
                                    )}
                                >
                                    {row.total_months}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right tabular-nums text-zinc-300',
                                    )}
                                >
                                    {row.total_days}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs tabular-nums text-zinc-400',
                                    )}
                                >
                                    {row.grt ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs tabular-nums text-zinc-400',
                                    )}
                                >
                                    {row.bhp ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[160px] truncate text-xs text-zinc-400',
                                    )}
                                    title={row.client_name ?? ''}
                                >
                                    {row.client_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_offshore ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-zinc-600">—</span>
                                    )}
                                </td>
                                {canManage ? (
                                    <td className={employeeRecordsActionsTdClass('min-w-[4.5rem]')}>
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingRow(row);
                                                employeeForm.setData({
                                                    vessel_type_id: String(row.vessel_type_id),
                                                    vessel_name: row.vessel_name ?? '',
                                                    rank_id: String(row.rank_id),
                                                    total_months: String(row.total_months),
                                                    total_days: String(row.total_days),
                                                    grt: row.grt ?? '',
                                                    bhp:
                                                        row.bhp !== null && row.bhp !== undefined
                                                            ? String(row.bhp)
                                                            : '',
                                                    client_id:
                                                        row.client_id != null
                                                            ? String(row.client_id)
                                                            : '',
                                                    is_offshore: row.is_offshore,
                                                });
                                                employeeForm.clearErrors();
                                                setDialogOpen(true);
                                            }}
                                            onDelete={() => setDeleteRowId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <Dialog
                open={dialogOpen}
                onOpenChange={(openDialog) => {
                    setDialogOpen(openDialog);

                    if (!openDialog) {
                        employeeForm.reset();
                        employeeForm.clearErrors();
                        setEditingRow(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow ? 'Edit sea service' : 'Add sea service'}
                        </DialogTitle>
                        <p className="text-xs text-zinc-500">
                            Enter the details of the vessel and the time served.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        {/* Section: Vessel & role */}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Vessel & Role</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vessel name <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. BES SINCERE"
                                    value={employeeForm.data.vessel_name}
                                    onChange={(e) => employeeForm.setData('vessel_name', e.target.value)}
                                />
                                {employeeForm.errors.vessel_name ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.vessel_name}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Name of the ship</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vessel type <span className="text-red-400">*</span></Label>
                                <AppSelect
                                    value={employeeForm.data.vessel_type_id}
                                    onValueChange={(v) => employeeForm.setData('vessel_type_id', v)}
                                    variant="dark"
                                    placeholder="— Select a type —"
                                >
                                    <AppSelectItem value="">— Select a type —</AppSelectItem>
                                    {vessel_types.map((v) => (
                                        <AppSelectItem key={v.id} value={String(v.id)}>
                                            {v.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {employeeForm.errors.vessel_type_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.vessel_type_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Category of the vessel</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Rank <span className="text-red-400">*</span></Label>
                                <AppSelect
                                    value={employeeForm.data.rank_id}
                                    onValueChange={(v) => employeeForm.setData('rank_id', v)}
                                    variant="dark"
                                    placeholder="— Select a rank —"
                                >
                                    <AppSelectItem value="">— Select a rank —</AppSelectItem>
                                    {ranks.map((r) => (
                                        <AppSelectItem key={r.id} value={String(r.id)}>
                                            {r.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {employeeForm.errors.rank_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.rank_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Position held on board</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Client</Label>
                                <AppSelect
                                    value={employeeForm.data.client_id}
                                    onValueChange={(v) => employeeForm.setData('client_id', v)}
                                    variant="dark"
                                    placeholder="— Select a client —"
                                >
                                    <AppSelectItem value="">— Select a client —</AppSelectItem>
                                    {clients.map((c) => (
                                        <AppSelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {employeeForm.errors.client_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.client_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Client or charterer (optional)</p>
                                )}
                            </div>
                        </div>

                        {/* Section: Duration */}
                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Duration</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total months <span className="text-red-400">*</span></Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.total_months}
                                    onChange={(e) => employeeForm.setData('total_months', e.target.value)}
                                />
                                {employeeForm.errors.total_months ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.total_months}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Full months served</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total days <span className="text-red-400">*</span></Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.total_days}
                                    onChange={(e) => employeeForm.setData('total_days', e.target.value)}
                                />
                                {employeeForm.errors.total_days ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.total_days}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Remaining days served</p>
                                )}
                            </div>
                        </div>

                        {/* Section: Specs & Settings */}
                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Specs & Settings</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">GRT</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    step="any"
                                    inputMode="decimal"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    placeholder="e.g. 15000"
                                    value={employeeForm.data.grt}
                                    onChange={(e) => employeeForm.setData('grt', e.target.value)}
                                />
                                {employeeForm.errors.grt ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.grt}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Gross Register Tonnage (optional)</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">BHP</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    placeholder="e.g. 8000"
                                    value={employeeForm.data.bhp}
                                    onChange={(e) => employeeForm.setData('bhp', e.target.value)}
                                />
                                {employeeForm.errors.bhp ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.bhp}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Brake Horsepower (optional)</p>
                                )}
                            </div>
                            <div className="sm:col-span-2">
                                <div className="rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3">
                                    <label className="flex items-center gap-3 text-sm text-zinc-200">
                                        <Checkbox
                                            checked={employeeForm.data.is_offshore}
                                            onCheckedChange={(v) => employeeForm.setData('is_offshore', v === true)}
                                        />
                                        <div>
                                            <div className="font-medium">Offshore experience</div>
                                            <div className="mt-0.5 text-[11px] text-zinc-500">Mark if this sea service was completed offshore</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="border-t border-white/5 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={employeeForm.processing}
                            onClick={() => {
                                employeeForm.clearErrors();

                                const payload = buildSeaServicePayload(
                                    employeeForm.data,
                                );
                                const url = editingRow
                                    ? updateSeaService.url({
                                          employee: employeeId,
                                          seaService: editingRow.id,
                                      })
                                    : storeSeaService.url({
                                          employee: employeeId,
                                      });

                                const options = {
                                    ...SEA_SERVICE_RELOAD,
                                    onSuccess: () => {
                                        setDialogOpen(false);
                                        employeeForm.reset();
                                        setEditingRow(null);
                                        toast.success(
                                            editingRow
                                                ? 'Sea service updated.'
                                                : 'Sea service added.',
                                        );
                                    },
                                    onError: (errors: Record<string, string>) => {
                                        Object.entries(errors).forEach(
                                            ([key, message]) => {
                                                employeeForm.setError(
                                                    key as keyof typeof employeeForm.data,
                                                    message,
                                                );
                                            },
                                        );
                                    },
                                };

                                if (editingRow) {
                                    router.put(url, payload, options);
                                } else {
                                    router.post(url, payload, options);
                                }
                            }}
                        >
                            {employeeForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteRowId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteRowId(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-sm">
                    <AlertDialogHeader>
                        <div className="mb-1 flex items-center gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-red-400">
                                <Trash2 className="size-4" />
                            </span>
                            <AlertDialogTitle>Remove sea service?</AlertDialogTitle>
                        </div>
                        <AlertDialogDescription>
                            This row will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-500"
                            onClick={() => {
                                if (!deleteRowId) {
                                    return;
                                }

                                router.delete(
                                    destroySeaService.url({
                                        employee: employeeId,
                                        seaService: deleteRowId,
                                    }),
                                    {
                                        ...SEA_SERVICE_RELOAD,
                                        onSuccess: () => {
                                            setDeleteRowId(null);
                                            toast.success(
                                                'Sea service removed.',
                                            );
                                        },
                                    },
                                );
                            }}
                        >
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <SeaServiceImportDialog
                open={seaServiceImportOpen}
                onOpenChange={setSeaServiceImportOpen}
                employeeId={employeeId}
            />
        </TabsContent>
    );
}
