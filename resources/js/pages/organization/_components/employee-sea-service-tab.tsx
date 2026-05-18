import { router, useForm } from '@inertiajs/react';
import { GripVertical } from 'lucide-react';
import type { ReactElement } from 'react';
import { useRef, useState } from 'react';
import {
    destroy as destroySeaService,
    reorder as reorderSeaServices,
    store as storeSeaService,
    update as updateSeaService,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
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
import { formatSeaServiceTotalsYmd } from '@/pages/organization/_lib/sum-sea-service-experience';
import type {
    ClientOption,
    SeaServiceItem,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

function reorderByIndex<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [removed] = next.splice(from, 1);
    next.splice(to, 0, removed);

    return next;
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
    const [editingRow, setEditingRow] = useState<SeaServiceItem | null>(null);
    const [deleteRowId, setDeleteRowId] = useState<number | null>(null);
    const dragSourceIdRef = useRef<number | null>(null);

    const employeeForm = useForm({
        vessel_type_id: '',
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

    const persistReorder = (order: number[]) => {
        router.post(
            reorderSeaServices.url({ employee: employeeId }),
            { order },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Order saved.'),
                onError: () => toast.error('Could not save order.'),
            },
        );
    };

    const handleDropOnRow = (targetRow: SeaServiceItem) => {
        const dragId = dragSourceIdRef.current;

        dragSourceIdRef.current = null;

        if (!dragId || dragId === targetRow.id || !canManage) {
            return;
        }

        const ids = sea_services.map((r) => r.id);
        const from = ids.indexOf(dragId);
        const to = ids.indexOf(targetRow.id);

        if (from < 0 || to < 0) {
            return;
        }

        const nextOrder = reorderByIndex(ids, from, to);
        persistReorder(nextOrder);
    };

    return (
        <TabsContent value="sea_service" className="mt-6">
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Sea Service
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {sea_services.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={() => {
                                employeeForm.reset();
                                employeeForm.clearErrors();
                                employeeForm.setData({
                                    vessel_type_id: '',
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
                    ) : null}
                </div>

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

                {sea_services.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No sea service recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    {canManage ? (
                                        <th
                                            className="w-8 py-2 pr-1"
                                            aria-label="Reorder"
                                        />
                                    ) : null}
                                    <th className="py-2 pr-4">Vessel type</th>
                                    <th className="py-2 pr-4">Rank</th>
                                    <th className="py-2 pr-4 text-right tabular-nums">
                                        Total months
                                    </th>
                                    <th className="py-2 pr-4 text-right tabular-nums">
                                        Total days
                                    </th>
                                    <th className="py-2 pr-4 text-right tabular-nums">
                                        GRT
                                    </th>
                                    <th className="py-2 pr-4 text-right tabular-nums">
                                        BHP
                                    </th>
                                    <th className="py-2 pr-4">Client</th>
                                    <th className="py-2 pr-4 text-center text-xs font-normal normal-case">
                                        Offshore
                                    </th>
                                    {canManage ? (
                                        <th className="py-2 pr-0 text-right" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {sea_services.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            canManage
                                                ? 'cursor-default'
                                                : undefined
                                        }
                                        onDragOver={
                                            canManage
                                                ? (e) => e.preventDefault()
                                                : undefined
                                        }
                                        onDrop={
                                            canManage
                                                ? () => handleDropOnRow(row)
                                                : undefined
                                        }
                                    >
                                        {canManage ? (
                                            <td className="py-2 pr-1 align-middle text-zinc-500">
                                                <button
                                                    type="button"
                                                    draggable
                                                    className="flex cursor-grab items-center rounded p-1 active:cursor-grabbing"
                                                    aria-label="Drag to reorder"
                                                    onDragStart={() => {
                                                        dragSourceIdRef.current =
                                                            row.id;
                                                    }}
                                                    onDragEnd={() => {
                                                        dragSourceIdRef.current =
                                                            null;
                                                    }}
                                                >
                                                    <GripVertical className="size-4" />
                                                </button>
                                            </td>
                                        ) : null}
                                        <td
                                            className="max-w-[200px] truncate py-3 pr-4 font-medium text-zinc-200"
                                            title={row.vessel_type_name ?? ''}
                                        >
                                            {row.vessel_type_name ?? '—'}
                                        </td>
                                        <td
                                            className="max-w-[180px] truncate py-3 pr-4 text-sm text-zinc-300"
                                            title={row.rank_name ?? ''}
                                        >
                                            {row.rank_name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-right text-sm text-zinc-300 tabular-nums">
                                            {row.total_months}
                                        </td>
                                        <td className="py-3 pr-4 text-right text-sm text-zinc-300 tabular-nums">
                                            {row.total_days}
                                        </td>
                                        <td className="py-3 pr-4 text-right text-xs text-zinc-400 tabular-nums">
                                            {row.grt ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-right text-xs text-zinc-400 tabular-nums">
                                            {row.bhp ?? '—'}
                                        </td>
                                        <td
                                            className="max-w-[160px] truncate py-3 pr-4 text-xs text-zinc-400"
                                            title={row.client_name ?? ''}
                                        >
                                            {row.client_name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_offshore ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 pr-0 text-right align-middle">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                        onClick={() => {
                                                            setEditingRow(row);
                                                            employeeForm.setData(
                                                                {
                                                                    vessel_type_id:
                                                                        String(
                                                                            row.vessel_type_id,
                                                                        ),
                                                                    rank_id:
                                                                        String(
                                                                            row.rank_id,
                                                                        ),
                                                                    total_months:
                                                                        String(
                                                                            row.total_months,
                                                                        ),
                                                                    total_days:
                                                                        String(
                                                                            row.total_days,
                                                                        ),
                                                                    grt:
                                                                        row.grt ??
                                                                        '',
                                                                    bhp:
                                                                        row.bhp !==
                                                                            null &&
                                                                        row.bhp !==
                                                                            undefined
                                                                            ? String(
                                                                                  row.bhp,
                                                                              )
                                                                            : '',
                                                                    client_id:
                                                                        row.client_id !=
                                                                        null
                                                                            ? String(
                                                                                  row.client_id,
                                                                              )
                                                                            : '',
                                                                    is_offshore:
                                                                        row.is_offshore,
                                                                },
                                                            );
                                                            employeeForm.clearErrors();
                                                            setDialogOpen(true);
                                                        }}
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                        onClick={() =>
                                                            setDeleteRowId(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow
                                ? 'Edit sea service'
                                : 'Add sea service'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-4 py-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs">Vessel type</Label>
                            <select
                                value={employeeForm.data.vessel_type_id}
                                onChange={(e) =>
                                    employeeForm.setData(
                                        'vessel_type_id',
                                        e.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option value="">— Select —</option>
                                {vessel_types.map((v) => (
                                    <option key={v.id} value={String(v.id)}>
                                        {v.name}
                                    </option>
                                ))}
                            </select>
                            {employeeForm.errors.vessel_type_id ? (
                                <p className="text-xs text-destructive">
                                    {employeeForm.errors.vessel_type_id}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Rank</Label>
                            <select
                                value={employeeForm.data.rank_id}
                                onChange={(e) =>
                                    employeeForm.setData(
                                        'rank_id',
                                        e.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option value="">— Select —</option>
                                {ranks.map((r) => (
                                    <option key={r.id} value={String(r.id)}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                            {employeeForm.errors.rank_id ? (
                                <p className="text-xs text-destructive">
                                    {employeeForm.errors.rank_id}
                                </p>
                            ) : null}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total months</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.total_months}
                                    onChange={(e) =>
                                        employeeForm.setData(
                                            'total_months',
                                            e.target.value,
                                        )
                                    }
                                />
                                {employeeForm.errors.total_months ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.total_months}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total days</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.total_days}
                                    onChange={(e) =>
                                        employeeForm.setData(
                                            'total_days',
                                            e.target.value,
                                        )
                                    }
                                />
                                {employeeForm.errors.total_days ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.total_days}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">GRT</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    step="any"
                                    inputMode="decimal"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.grt}
                                    onChange={(e) =>
                                        employeeForm.setData(
                                            'grt',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Optional"
                                />
                                {employeeForm.errors.grt ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.grt}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">BHP</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm tabular-nums"
                                    value={employeeForm.data.bhp}
                                    onChange={(e) =>
                                        employeeForm.setData(
                                            'bhp',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Optional"
                                />
                                {employeeForm.errors.bhp ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.bhp}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Client</Label>
                            <select
                                value={employeeForm.data.client_id}
                                onChange={(e) =>
                                    employeeForm.setData(
                                        'client_id',
                                        e.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option value="">—</option>
                                {clients.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            {employeeForm.errors.client_id ? (
                                <p className="text-xs text-destructive">
                                    {employeeForm.errors.client_id}
                                </p>
                            ) : null}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-zinc-200">
                            <Checkbox
                                checked={employeeForm.data.is_offshore}
                                onCheckedChange={(v) =>
                                    employeeForm.setData(
                                        'is_offshore',
                                        v === true,
                                    )
                                }
                            />
                            Offshore
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            disabled={employeeForm.processing}
                            onClick={() => {
                                employeeForm.clearErrors();
                                employeeForm.transform((data) => ({
                                    vessel_type_id: Number.parseInt(
                                        data.vessel_type_id,
                                        10,
                                    ),
                                    rank_id: Number.parseInt(data.rank_id, 10),
                                    total_months: Math.max(
                                        0,
                                        Number.parseInt(
                                            data.total_months,
                                            10,
                                        ) || 0,
                                    ),
                                    total_days: Math.max(
                                        0,
                                        Number.parseInt(data.total_days, 10) ||
                                            0,
                                    ),
                                    grt:
                                        data.grt.trim() === ''
                                            ? null
                                            : Number(data.grt),
                                    bhp:
                                        data.bhp.trim() === ''
                                            ? null
                                            : Math.max(
                                                  0,
                                                  Number.parseInt(
                                                      data.bhp,
                                                      10,
                                                  ) || 0,
                                              ),
                                    client_id:
                                        data.client_id.trim() === ''
                                            ? null
                                            : Number.parseInt(
                                                  data.client_id,
                                                  10,
                                              ),
                                    is_offshore: !!data.is_offshore,
                                }));

                                const url = editingRow
                                    ? updateSeaService.url({
                                          employee: employeeId,
                                          seaService: editingRow.id,
                                      })
                                    : storeSeaService.url({
                                          employee: employeeId,
                                      });

                                if (editingRow) {
                                    employeeForm.put(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            employeeForm.reset();
                                            setEditingRow(null);
                                            toast.success(
                                                'Sea service updated.',
                                            );
                                        },
                                    });
                                } else {
                                    employeeForm.post(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            employeeForm.reset();
                                            toast.success('Sea service added.');
                                        },
                                    });
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
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove sea service?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This row will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="text-destructive-foreground bg-destructive hover:bg-destructive/90"
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
                                        preserveScroll: true,
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
        </TabsContent>
    );
}
