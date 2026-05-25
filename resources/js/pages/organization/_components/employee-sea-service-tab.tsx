import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import {
    destroy as destroySeaService,
    store as storeSeaService,
    update as updateSeaService,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CreatableSelect } from '@/components/ui/creatable-select';
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
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { EmployeeRecordImportDialog } from '@/features/organization/employees/profile/components/employee-record-import-dialog';
import { seaServiceImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { RankOption } from '@/features/organization/employees/types';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { actions } from '@/lib/design-system';
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
import { calculateSeaServiceDuration } from '@/pages/organization/_lib/calculate-sea-service-duration';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { formatSeaServiceTotalsYmd } from '@/pages/organization/_lib/sum-sea-service-experience';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
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
    start_date: string;
    end_date: string;
    grt: string;
    bhp: string;
    client_id: string;
    is_offshore: boolean;
}) {
    return {
        vessel_type_id: Number.parseInt(data.vessel_type_id, 10),
        vessel_name: data.vessel_name.trim(),
        rank_id: Number.parseInt(data.rank_id, 10),
        start_date: data.start_date,
        end_date: data.end_date,
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

function resolveDisplayedDuration(
    startDate: string,
    endDate: string,
    legacyRow: SeaServiceItem | null,
): { months: string; days: string } {
    const calculated = calculateSeaServiceDuration(startDate, endDate);

    if (calculated) {
        return {
            months: String(calculated.months),
            days: String(calculated.days),
        };
    }

    if (
        legacyRow
        && (!startDate || !endDate)
        && legacyRow.start_date === null
        && legacyRow.end_date === null
    ) {
        return {
            months: String(legacyRow.total_months),
            days: String(legacyRow.total_days),
        };
    }

    return { months: '0', days: '0' };
}

export type EmployeeSeaServiceTabProps = {
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    sea_services: SeaServiceItem[];
    vessel_types: VesselTypeOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    employeeRankId: number | null;
    canManage: boolean;
};

export function EmployeeSeaServiceTab({
    employeeId,
    ensureEmployee,
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
        start_date: '',
        end_date: '',
        grt: '',
        bhp: '',
        client_id: '',
        is_offshore: false,
    });

    const {
        selectOptions: vesselTypeSelectOptions,
        appendOption: appendVesselTypeOption,
    } = useMutableSelectOptions(vessel_types);
    const { selectOptions: rankSelectOptions, appendOption: appendRankOption } =
        useMutableSelectOptions(ranks);
    const { selectOptions: clientSelectOptions, appendOption: appendClientOption } =
        useMutableSelectOptions(clients);
    const { canCreate: canCreateVesselType, createConfig: vesselTypeCreateConfig } =
        useCreatableMasterData('vesselType');
    const { canCreate: canCreateRank, createConfig: rankCreateConfig } =
        useCreatableMasterData('rank');
    const { canCreate: canCreateClient, createConfig: clientCreateConfig } =
        useCreatableMasterData('client');

    const displayedDuration = useMemo(
        () =>
            resolveDisplayedDuration(
                employeeForm.data.start_date,
                employeeForm.data.end_date,
                editingRow,
            ),
        [
            employeeForm.data.start_date,
            employeeForm.data.end_date,
            editingRow,
        ],
    );

    const seaServiceImport = seaServiceImportConfig(employeeId);
    const seaServiceImportUrls = useMemo(
        () =>
            resolveRecordImportUrls(
                seaServiceImportConfig(employeeId),
                employeeId,
            ),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;

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
                <div className="rounded-xl border border-border/60 bg-black/10 px-4 py-3">
                    <div className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        Total experience in the applied rank (in years)
                    </div>
                    <div className="mt-1 font-mono text-sm font-semibold text-foreground">
                        {appliedRankTotals}
                    </div>
                </div>
                <div className="rounded-xl border border-border/60 bg-black/10 px-4 py-3">
                    <div className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        Offshore experience (in years)
                    </div>
                    <div className="mt-1 font-mono text-sm font-semibold text-foreground">
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
                                disabled={!canImportRecords}
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
                                        start_date: '',
                                        end_date: '',
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
                <EmployeeRecordsTable className="min-w-[1280px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Vessel type</th>
                            <th className={employeeRecordsTableThClass()}>Vessel name</th>
                            <th className={employeeRecordsTableThClass()}>Rank</th>
                            <th className={employeeRecordsTableThClass()}>Start</th>
                            <th className={employeeRecordsTableThClass()}>End</th>
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
                                        'max-w-[200px] truncate font-medium text-foreground',
                                    )}
                                    title={row.vessel_type_name ?? ''}
                                >
                                    {row.vessel_type_name ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate text-muted-foreground',
                                    )}
                                    title={row.vessel_name ?? ''}
                                >
                                    {row.vessel_name?.trim() ? row.vessel_name : '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[180px] truncate text-muted-foreground',
                                    )}
                                    title={row.rank_name ?? ''}
                                >
                                    {row.rank_name ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-muted-foreground',
                                    )}
                                >
                                    {formatIsoDateDisplay(row.start_date)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-muted-foreground',
                                    )}
                                >
                                    {formatIsoDateDisplay(row.end_date)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right tabular-nums text-muted-foreground',
                                    )}
                                >
                                    {row.total_months}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right tabular-nums text-muted-foreground',
                                    )}
                                >
                                    {row.total_days}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs tabular-nums text-muted-foreground',
                                    )}
                                >
                                    {row.grt ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs tabular-nums text-muted-foreground',
                                    )}
                                >
                                    {row.bhp ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[160px] truncate text-xs text-muted-foreground',
                                    )}
                                    title={row.client_name ?? ''}
                                >
                                    {row.client_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_offshore ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-muted-foreground/50">—</span>
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
                                                    start_date: row.start_date ?? '',
                                                    end_date: row.end_date ?? '',
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
                        <p className="text-xs text-muted-foreground">
                            Enter the details of the vessel and the time served.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        {/* Section: Vessel & role */}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Vessel & Role</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vessel name <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    placeholder="e.g. BES SINCERE"
                                    value={employeeForm.data.vessel_name}
                                    onChange={(e) => employeeForm.setData('vessel_name', e.target.value)}
                                />
                                {employeeForm.errors.vessel_name ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.vessel_name}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Name of the ship</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vessel type <span className="text-red-400">*</span></Label>
                                <CreatableSelect
                                    value={employeeForm.data.vessel_type_id}
                                    onValueChange={(v) => employeeForm.setData('vessel_type_id', v)}
                                    variant="dark"
                                    placeholder="— Select a type —"
                                    options={vesselTypeSelectOptions}
                                    onOptionsChange={(next) => {
                                        const added = next.find(
                                            (option) =>
                                                !vesselTypeSelectOptions.some(
                                                    (existing) => existing.value === option.value,
                                                ),
                                        );

                                        if (added) {
                                            appendVesselTypeOption({
                                                id: added.id,
                                                label: added.label,
                                            });
                                        }
                                    }}
                                    creatable
                                    canCreate={canCreateVesselType}
                                    createConfig={vesselTypeCreateConfig}
                                />
                                {employeeForm.errors.vessel_type_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.vessel_type_id}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Category of the vessel</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Rank <span className="text-red-400">*</span></Label>
                                <CreatableSelect
                                    value={employeeForm.data.rank_id}
                                    onValueChange={(v) => employeeForm.setData('rank_id', v)}
                                    variant="dark"
                                    placeholder="— Select a rank —"
                                    options={rankSelectOptions}
                                    onOptionsChange={(next) => {
                                        const added = next.find(
                                            (option) =>
                                                !rankSelectOptions.some(
                                                    (existing) => existing.value === option.value,
                                                ),
                                        );

                                        if (added) {
                                            appendRankOption({
                                                id: added.id,
                                                label: added.label,
                                            });
                                        }
                                    }}
                                    creatable
                                    canCreate={canCreateRank}
                                    createConfig={rankCreateConfig}
                                />
                                {employeeForm.errors.rank_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.rank_id}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Position held on board</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Client</Label>
                                <CreatableSelect
                                    value={employeeForm.data.client_id}
                                    onValueChange={(v) => employeeForm.setData('client_id', v)}
                                    variant="dark"
                                    placeholder="— Select a client —"
                                    options={clientSelectOptions}
                                    onOptionsChange={(next) => {
                                        const added = next.find(
                                            (option) =>
                                                !clientSelectOptions.some(
                                                    (existing) => existing.value === option.value,
                                                ),
                                        );

                                        if (added) {
                                            appendClientOption({
                                                id: added.id,
                                                label: added.label,
                                            });
                                        }
                                    }}
                                    creatable
                                    canCreate={canCreateClient}
                                    createConfig={clientCreateConfig}
                                />
                                {employeeForm.errors.client_id ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.client_id}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Client or charterer (optional)</p>
                                )}
                            </div>
                        </div>

                        {/* Section: Duration */}
                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Duration</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="sea_service_start_date" className="text-xs">
                                    Start date <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="sea_service_start_date"
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={employeeForm.data.start_date}
                                    onChange={(e) =>
                                        employeeForm.setData('start_date', e.target.value)
                                    }
                                />
                                {employeeForm.errors.start_date ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.start_date}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        First day of service on board
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="sea_service_end_date" className="text-xs">
                                    End date <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="sea_service_end_date"
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={employeeForm.data.end_date}
                                    onChange={(e) =>
                                        employeeForm.setData('end_date', e.target.value)
                                    }
                                />
                                {employeeForm.errors.end_date ? (
                                    <p className="text-xs text-destructive">
                                        {employeeForm.errors.end_date}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        Last day of service on board
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total months</Label>
                                <Input
                                    type="number"
                                    readOnly
                                    tabIndex={-1}
                                    className="h-10 cursor-default rounded-xl border-border/60 bg-muted/30 text-sm tabular-nums text-muted-foreground"
                                    value={displayedDuration.months}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Calculated from service dates
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Total days</Label>
                                <Input
                                    type="number"
                                    readOnly
                                    tabIndex={-1}
                                    className="h-10 cursor-default rounded-xl border-border/60 bg-muted/30 text-sm tabular-nums text-muted-foreground"
                                    value={displayedDuration.days}
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Total days in the service period (inclusive)
                                </p>
                            </div>
                        </div>

                        {/* Section: Specs & Settings */}
                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Specs & Settings</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">GRT</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    step="any"
                                    inputMode="decimal"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm tabular-nums"
                                    placeholder="e.g. 15000"
                                    value={employeeForm.data.grt}
                                    onChange={(e) => employeeForm.setData('grt', e.target.value)}
                                />
                                {employeeForm.errors.grt ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.grt}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Gross Register Tonnage (optional)</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">BHP</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm tabular-nums"
                                    placeholder="e.g. 8000"
                                    value={employeeForm.data.bhp}
                                    onChange={(e) => employeeForm.setData('bhp', e.target.value)}
                                />
                                {employeeForm.errors.bhp ? (
                                    <p className="text-xs text-destructive">{employeeForm.errors.bhp}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Brake Horsepower (optional)</p>
                                )}
                            </div>
                            <div className="sm:col-span-2">
                                <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                    <label className="flex items-center gap-3 text-sm text-foreground">
                                        <Checkbox
                                            checked={employeeForm.data.is_offshore}
                                            onCheckedChange={(v) => employeeForm.setData('is_offshore', v === true)}
                                        />
                                        <div>
                                            <div className="font-medium">Offshore experience</div>
                                            <div className="mt-0.5 text-[11px] text-muted-foreground">Mark if this sea service was completed offshore</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={employeeForm.processing}
                            onClick={async () => {
                                let resolvedEmployeeId: number;

                                try {
                                    resolvedEmployeeId = await resolveEmployeeIdForSave(
                                        employeeId,
                                        ensureEmployee,
                                    );
                                } catch {
                                    return;
                                }

                                employeeForm.clearErrors();

                                const payload = buildSeaServicePayload(
                                    employeeForm.data,
                                );
                                const url = editingRow
                                    ? updateSeaService.url({
                                          employee: resolvedEmployeeId,
                                          seaService: editingRow.id,
                                      })
                                    : storeSeaService.url({
                                          employee: resolvedEmployeeId,
                                      });

                                const options = {
                                    ...SEA_SERVICE_RELOAD,
                                    onSuccess: () => {
                                        setDialogOpen(false);
                                        employeeForm.reset();
                                        setEditingRow(null);
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

            <EmployeeRecordDeleteDialog
                open={!!deleteRowId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteRowId(null);
                    }
                }}
                title="Remove sea service?"
                description="This row will be permanently removed."
                destroyUrl={
                    deleteRowId && employeeId
                        ? destroySeaService.url({
                              employee: employeeId,
                              seaService: deleteRowId,
                          })
                        : null
                }
                reloadOptions={SEA_SERVICE_RELOAD}
            />

            <EmployeeRecordImportDialog
                open={seaServiceImportOpen}
                onOpenChange={setSeaServiceImportOpen}
                inputId={seaServiceImport.inputId}
                title={seaServiceImport.title}
                description={seaServiceImport.description}
                templateHint={seaServiceImport.templateHint}
                columnHelp={seaServiceImport.columnHelp}
                reloadOnly={seaServiceImport.reloadOnly}
                importUrl={seaServiceImportUrls.importUrl}
                templateUrl={seaServiceImportUrls.templateUrl}
            />
        </TabsContent>
    );
}
