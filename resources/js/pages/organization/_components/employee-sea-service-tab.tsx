import type { RequestPayload } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useCallback, useMemo, useState } from 'react';
import {
    bulkDestroy as bulkDestroySeaServices,
    destroy as destroySeaService,
    store as storeSeaService,
    update as updateSeaService,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
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
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { EmployeeRecordImportDialog } from '@/features/organization/employees/profile/components/employee-record-import-dialog';
import { seaServiceImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { RankOption } from '@/features/organization/employees/types';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { actions } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
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
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import { calculateSeaServiceDuration } from '@/pages/organization/_lib/calculate-sea-service-duration';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { formatSeaServiceTotalsYmd } from '@/pages/organization/_lib/sum-sea-service-experience';
import { omitHiddenTemplateRecordFields } from '@/pages/organization/_lib/template-field-visibility';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type {
    ClientOption,
    SeaServiceItem,
    TemplateFieldConfig,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

const SEA_SERVICE_RELOAD = {
    preserveScroll: true,
    only: ['sea_services'],
};

function buildSeaServicePayload(
    data: {
        vessel_type_id: string;
        vessel_id: string;
        rank_id: string;
        start_date: string;
        end_date: string;
        client_id: string;
        is_offshore: boolean;
    },
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
) {
    return omitHiddenTemplateRecordFields(
        {
            vessel_type_id:
                data.vessel_type_id === ''
                    ? null
                    : Number.parseInt(data.vessel_type_id, 10),
            vessel_id:
                data.vessel_id === ''
                    ? null
                    : Number.parseInt(data.vessel_id, 10),
            rank_id:
                data.rank_id === '' ? null : Number.parseInt(data.rank_id, 10),
            start_date: data.start_date,
            end_date: data.end_date,
            client_id:
                data.client_id.trim() === ''
                    ? null
                    : Number.parseInt(data.client_id, 10),
            is_offshore: !!data.is_offshore,
        },
        templateFields,
    );
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
        legacyRow &&
        (!startDate || !endDate) &&
        legacyRow.start_date === null &&
        legacyRow.end_date === null
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
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    employeeRankId: number | null;
    canManage: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeSeaServiceTab({
    employeeId,
    ensureEmployee,
    sea_services,
    vessel_types,
    vessels,
    ranks,
    clients,
    employeeRankId,
    canManage,
    templateFields = null,
}: EmployeeSeaServiceTabProps): ReactElement {
    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields:
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_sea_services,
        booleanFields: ['is_offshore'],
    });

    const [dialogOpen, setDialogOpen] = useState(false);
    const [seaServiceImportOpen, setSeaServiceImportOpen] = useState(false);
    const [editingRow, setEditingRow] = useState<SeaServiceItem | null>(null);
    const [deleteRowId, setDeleteRowId] = useState<number | null>(null);
    const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);

    const seaServiceIds = useMemo(
        () => sea_services.map((row) => row.id),
        [sea_services],
    );

    const {
        selectedIds: selectedSeaServiceIds,
        selectedCount: selectedSeaServiceCount,
        isSelected: isSeaServiceSelected,
        isAllSelected: allSeaServicesSelected,
        isPartiallySelected: seaServicesPartiallySelected,
        toggle: toggleSeaService,
        toggleAll: toggleAllSeaServices,
        clear: clearSeaServiceSelection,
    } = useBulkSelection(seaServiceIds);

    const employeeForm = useForm({
        vessel_type_id: '',
        vessel_id: '',
        rank_id: '',
        start_date: '',
        end_date: '',
        client_id: '',
        is_offshore: false,
    });

    useClearMissingOnFormChange(
        employeeForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    const {
        selectOptions: vesselTypeSelectOptions,
        appendOption: appendVesselTypeOption,
    } = useMutableSelectOptions(vessel_types);
    const {
        sourceItems: vesselSourceItems,
        selectOptions: vesselSelectOptions,
        appendOption: appendVesselOption,
    } = useMutableSelectOptions(vessels);
    const { selectOptions: rankSelectOptions, appendOption: appendRankOption } =
        useMutableSelectOptions(ranks);
    const {
        selectOptions: clientSelectOptions,
        appendOption: appendClientOption,
    } = useMutableSelectOptions(clients);
    const {
        canCreate: canCreateVesselType,
        createConfig: vesselTypeCreateConfig,
    } = useCreatableMasterData('vesselType');
    const { canCreate: canCreateVessel, createConfig: vesselCreateConfig } =
        useCreatableMasterData('vessel', {
            vesselTypeId: employeeForm.data.vessel_type_id || null,
        });
    const { canCreate: canCreateRank, createConfig: rankCreateConfig } =
        useCreatableMasterData('rank');
    const { canCreate: canCreateClient, createConfig: clientCreateConfig } =
        useCreatableMasterData('client');

    const filteredVesselSelectOptions = useMemo(() => {
        const typeId = employeeForm.data.vessel_type_id;

        if (!typeId) {
            return vesselSelectOptions;
        }

        return vesselSelectOptions.filter((option) => {
            const vessel = vesselSourceItems.find(
                (item) => String(item.id) === option.value,
            );

            return vessel != null && String(vessel.vessel_type_id) === typeId;
        });
    }, [
        employeeForm.data.vessel_type_id,
        vesselSelectOptions,
        vesselSourceItems,
    ]);

    const handleVesselTypeChange = useCallback(
        (value: string) => {
            const currentVesselId = employeeForm.data.vessel_id;

            if (currentVesselId) {
                const selectedVessel = vesselSourceItems.find(
                    (item) => String(item.id) === currentVesselId,
                );

                if (
                    selectedVessel &&
                    value !== '' &&
                    String(selectedVessel.vessel_type_id) !== value
                ) {
                    employeeForm.setData({
                        vessel_type_id: value,
                        vessel_id: '',
                    });

                    return;
                }
            }

            employeeForm.setData('vessel_type_id', value);
        },
        [employeeForm, vesselSourceItems],
    );

    const handleVesselChange = useCallback(
        (value: string) => {
            if (!value) {
                employeeForm.setData('vessel_id', '');

                return;
            }

            const selectedVessel = vesselSourceItems.find(
                (item) => String(item.id) === value,
            );

            if (selectedVessel) {
                employeeForm.setData({
                    vessel_id: value,
                    vessel_type_id: String(selectedVessel.vessel_type_id),
                });

                return;
            }

            employeeForm.setData('vessel_id', value);
        },
        [employeeForm, vesselSourceItems],
    );

    const displayedDuration = useMemo(
        () =>
            resolveDisplayedDuration(
                employeeForm.data.start_date,
                employeeForm.data.end_date,
                editingRow,
            ),
        [employeeForm.data.start_date, employeeForm.data.end_date, editingRow],
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

    const offshoreTotals = formatSeaServiceTotalsYmd(sea_services);

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

            {canManage && sea_services.length > 0 ? (
                <DocumentsBulkToolbar
                    count={selectedSeaServiceCount}
                    itemLabel="records"
                    onClear={clearSeaServiceSelection}
                    actions={
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            className="h-8 gap-1.5 text-xs"
                            disabled={isBulkDeleting || employeeId === null}
                            onClick={() => setBulkDeleteOpen(true)}
                        >
                            Delete selected
                        </Button>
                    }
                />
            ) : null}

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
                                    clearMissingRequired();
                                    employeeForm.setData({
                                        vessel_type_id: '',
                                        vessel_id: '',
                                        rank_id: '',
                                        start_date: '',
                                        end_date: '',
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
                            {canManage ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'w-10 px-3',
                                    )}
                                >
                                    <Checkbox
                                        checked={
                                            allSeaServicesSelected
                                                ? true
                                                : seaServicesPartiallySelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={toggleAllSeaServices}
                                        aria-label="Select all sea service records"
                                    />
                                </th>
                            ) : null}
                            {showField('vessel_type_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Vessel type
                                </th>
                            ) : null}
                            {showField('vessel_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Vessel
                                </th>
                            ) : null}
                            {showField('rank_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Rank
                                </th>
                            ) : null}
                            {showField('start_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Start
                                </th>
                            ) : null}
                            {showField('end_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    End
                                </th>
                            ) : null}
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'text-right tabular-nums',
                                )}
                            >
                                Total months
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'text-right tabular-nums',
                                )}
                            >
                                Total days
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'text-right tabular-nums',
                                )}
                            >
                                GRT
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'text-right tabular-nums',
                                )}
                            >
                                BHP
                            </th>
                            {showField('client_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Client
                                </th>
                            ) : null}
                            {showField('is_offshore') ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'text-center',
                                    )}
                                >
                                    Offshore
                                </th>
                            ) : null}
                            {canManage ? (
                                <EmployeeRecordsActionsHeader className="min-w-[4.5rem]" />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {sea_services.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                {canManage ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'w-10 px-3',
                                        )}
                                    >
                                        <Checkbox
                                            checked={isSeaServiceSelected(
                                                row.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggleSeaService(row.id)
                                            }
                                            aria-label={`Select sea service record ${row.vessel_name ?? row.id}`}
                                        />
                                    </td>
                                ) : null}
                                {showField('vessel_type_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[200px] truncate font-medium text-foreground',
                                        )}
                                        title={row.vessel_type_name ?? ''}
                                    >
                                        {row.vessel_type_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('vessel_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[200px] truncate text-muted-foreground',
                                        )}
                                        title={row.vessel_name ?? ''}
                                    >
                                        {row.vessel_name?.trim()
                                            ? row.vessel_name
                                            : '—'}
                                    </td>
                                ) : null}
                                {showField('rank_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[180px] truncate text-muted-foreground',
                                        )}
                                        title={row.rank_name ?? ''}
                                    >
                                        {row.rank_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('start_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.start_date)}
                                    </td>
                                ) : null}
                                {showField('end_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.end_date)}
                                    </td>
                                ) : null}
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-muted-foreground tabular-nums',
                                    )}
                                >
                                    {row.total_months}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-muted-foreground tabular-nums',
                                    )}
                                >
                                    {row.total_days}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs text-muted-foreground tabular-nums',
                                    )}
                                >
                                    {row.grt ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-right text-xs text-muted-foreground tabular-nums',
                                    )}
                                >
                                    {row.bhp ?? '—'}
                                </td>
                                {showField('client_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[160px] truncate text-xs text-muted-foreground',
                                        )}
                                        title={row.client_name ?? ''}
                                    >
                                        {row.client_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('is_offshore') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_offshore ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                ✓
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        )}
                                    </td>
                                ) : null}
                                {canManage ? (
                                    <td
                                        className={employeeRecordsActionsTdClass(
                                            'min-w-[4.5rem]',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingRow(row);
                                                clearMissingRequired();
                                                employeeForm.setData({
                                                    vessel_type_id: String(
                                                        row.vessel_type_id,
                                                    ),
                                                    vessel_id:
                                                        row.vessel_id != null
                                                            ? String(
                                                                  row.vessel_id,
                                                              )
                                                            : '',
                                                    rank_id: String(
                                                        row.rank_id,
                                                    ),
                                                    start_date:
                                                        row.start_date ?? '',
                                                    end_date:
                                                        row.end_date ?? '',
                                                    client_id:
                                                        row.client_id != null
                                                            ? String(
                                                                  row.client_id,
                                                              )
                                                            : '',
                                                    is_offshore:
                                                        row.is_offshore,
                                                });
                                                employeeForm.clearErrors();
                                                setDialogOpen(true);
                                            }}
                                            onDelete={() =>
                                                setDeleteRowId(row.id)
                                            }
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
                        clearMissingRequired();
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow
                                ? 'Edit sea service'
                                : 'Add sea service'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Enter the details of the vessel and the time served.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    <div className="space-y-4 py-1">
                        {showField('vessel_id') ||
                        showField('vessel_type_id') ||
                        showField('rank_id') ||
                        showField('client_id') ? (
                            <>
                                <div className="flex items-center gap-2">
                                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                        Vessel & Role
                                    </span>
                                    <div className="h-px flex-1 bg-muted/50" />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {showField('vessel_id') ? (
                                        <RecordFormField
                                            field="vessel_id"
                                            highlightMissing={isMissingRequired(
                                                'vessel_id',
                                            )}
                                        >
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'vessel_id',
                                                    ),
                                                )}
                                            >
                                                Vessel
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'vessel_id',
                                                    )}
                                                />
                                            </Label>
                                            <CreatableSelect
                                                value={
                                                    employeeForm.data.vessel_id
                                                }
                                                onValueChange={
                                                    handleVesselChange
                                                }
                                                variant="dark"
                                                placeholder="— Select a vessel —"
                                                options={
                                                    filteredVesselSelectOptions
                                                }
                                                onOptionsChange={(next) => {
                                                    const added = next.find(
                                                        (option) =>
                                                            !filteredVesselSelectOptions.some(
                                                                (existing) =>
                                                                    existing.value ===
                                                                    option.value,
                                                            ),
                                                    );

                                                    if (added) {
                                                        appendVesselOption({
                                                            id: added.id,
                                                            label: added.label,
                                                        });
                                                    }
                                                }}
                                                creatable
                                                canCreate={canCreateVessel}
                                                createConfig={
                                                    vesselCreateConfig
                                                }
                                            />
                                            {employeeForm.errors.vessel_id ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .vessel_id
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Vessel from master data
                                                    {isFieldRequired(
                                                        'vessel_id',
                                                    )
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                    {showField('vessel_type_id') ? (
                                        <RecordFormField
                                            field="vessel_type_id"
                                            highlightMissing={isMissingRequired(
                                                'vessel_type_id',
                                            )}
                                        >
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'vessel_type_id',
                                                    ),
                                                )}
                                            >
                                                Vessel type
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'vessel_type_id',
                                                    )}
                                                />
                                            </Label>
                                            <CreatableSelect
                                                value={
                                                    employeeForm.data
                                                        .vessel_type_id
                                                }
                                                onValueChange={
                                                    handleVesselTypeChange
                                                }
                                                variant="dark"
                                                placeholder="— Select a type —"
                                                options={
                                                    vesselTypeSelectOptions
                                                }
                                                onOptionsChange={(next) => {
                                                    const added = next.find(
                                                        (option) =>
                                                            !vesselTypeSelectOptions.some(
                                                                (existing) =>
                                                                    existing.value ===
                                                                    option.value,
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
                                                createConfig={
                                                    vesselTypeCreateConfig
                                                }
                                            />
                                            {employeeForm.errors
                                                .vessel_type_id ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .vessel_type_id
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Category of the vessel
                                                    {isFieldRequired(
                                                        'vessel_type_id',
                                                    )
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                    {showField('rank_id') ? (
                                        <RecordFormField
                                            field="rank_id"
                                            highlightMissing={isMissingRequired(
                                                'rank_id',
                                            )}
                                        >
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'rank_id',
                                                    ),
                                                )}
                                            >
                                                Rank
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'rank_id',
                                                    )}
                                                />
                                            </Label>
                                            <CreatableSelect
                                                value={
                                                    employeeForm.data.rank_id
                                                }
                                                onValueChange={(v) =>
                                                    employeeForm.setData(
                                                        'rank_id',
                                                        v,
                                                    )
                                                }
                                                variant="dark"
                                                placeholder="— Select a rank —"
                                                options={rankSelectOptions}
                                                onOptionsChange={(next) => {
                                                    const added = next.find(
                                                        (option) =>
                                                            !rankSelectOptions.some(
                                                                (existing) =>
                                                                    existing.value ===
                                                                    option.value,
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
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .rank_id
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Position held on board
                                                    {isFieldRequired('rank_id')
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                    {showField('client_id') ? (
                                        <RecordFormField
                                            field="client_id"
                                            highlightMissing={isMissingRequired(
                                                'client_id',
                                            )}
                                        >
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'client_id',
                                                    ),
                                                )}
                                            >
                                                Client
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'client_id',
                                                    )}
                                                />
                                            </Label>
                                            <CreatableSelect
                                                value={
                                                    employeeForm.data.client_id
                                                }
                                                onValueChange={(v) =>
                                                    employeeForm.setData(
                                                        'client_id',
                                                        v,
                                                    )
                                                }
                                                variant="dark"
                                                placeholder="— Select a client —"
                                                options={clientSelectOptions}
                                                onOptionsChange={(next) => {
                                                    const added = next.find(
                                                        (option) =>
                                                            !clientSelectOptions.some(
                                                                (existing) =>
                                                                    existing.value ===
                                                                    option.value,
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
                                                createConfig={
                                                    clientCreateConfig
                                                }
                                            />
                                            {employeeForm.errors.client_id ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .client_id
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Client or charterer
                                                    {isFieldRequired(
                                                        'client_id',
                                                    )
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                </div>
                            </>
                        ) : null}

                        {showField('start_date') || showField('end_date') ? (
                            <>
                                <div className="flex items-center gap-2 pt-2">
                                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                        Duration
                                    </span>
                                    <div className="h-px flex-1 bg-muted/50" />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {showField('start_date') ? (
                                        <RecordFormField
                                            field="start_date"
                                            highlightMissing={isMissingRequired(
                                                'start_date',
                                            )}
                                        >
                                            <Label
                                                htmlFor="sea_service_start_date"
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'start_date',
                                                    ),
                                                )}
                                            >
                                                Start date
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'start_date',
                                                    )}
                                                />
                                            </Label>
                                            <Input
                                                id="sea_service_start_date"
                                                type="date"
                                                className={recordFieldInputClass(
                                                    isMissingRequired(
                                                        'start_date',
                                                    ),
                                                )}
                                                value={
                                                    employeeForm.data.start_date
                                                }
                                                onChange={(e) =>
                                                    employeeForm.setData(
                                                        'start_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {employeeForm.errors.start_date ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .start_date
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    First day of service on
                                                    board
                                                    {isFieldRequired(
                                                        'start_date',
                                                    )
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                    {showField('end_date') ? (
                                        <RecordFormField
                                            field="end_date"
                                            highlightMissing={isMissingRequired(
                                                'end_date',
                                            )}
                                        >
                                            <Label
                                                htmlFor="sea_service_end_date"
                                                className={recordFieldLabelClass(
                                                    isMissingRequired(
                                                        'end_date',
                                                    ),
                                                )}
                                            >
                                                End date
                                                <RequiredIndicator
                                                    show={isFieldRequired(
                                                        'end_date',
                                                    )}
                                                />
                                            </Label>
                                            <Input
                                                id="sea_service_end_date"
                                                type="date"
                                                className={recordFieldInputClass(
                                                    isMissingRequired(
                                                        'end_date',
                                                    ),
                                                )}
                                                value={
                                                    employeeForm.data.end_date
                                                }
                                                onChange={(e) =>
                                                    employeeForm.setData(
                                                        'end_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {employeeForm.errors.end_date ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        employeeForm.errors
                                                            .end_date
                                                    }
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Last day of service on board
                                                    {isFieldRequired('end_date')
                                                        ? ''
                                                        : ' (optional)'}
                                                </p>
                                            )}
                                        </RecordFormField>
                                    ) : null}
                                    <div className="space-y-1.5">
                                        <Label className="text-xs">
                                            Total months
                                        </Label>
                                        <Input
                                            type="number"
                                            readOnly
                                            tabIndex={-1}
                                            className="h-10 cursor-default rounded-xl border-border/60 bg-muted/30 text-sm text-muted-foreground tabular-nums"
                                            value={displayedDuration.months}
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Calculated from service dates
                                        </p>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="text-xs">
                                            Total days
                                        </Label>
                                        <Input
                                            type="number"
                                            readOnly
                                            tabIndex={-1}
                                            className="h-10 cursor-default rounded-xl border-border/60 bg-muted/30 text-sm text-muted-foreground tabular-nums"
                                            value={displayedDuration.days}
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Total days in the service period
                                            (inclusive)
                                        </p>
                                    </div>
                                </div>
                            </>
                        ) : null}

                        {showField('is_offshore') ? (
                            <>
                                <div className="flex items-center gap-2 pt-2">
                                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                        Settings
                                    </span>
                                    <div className="h-px flex-1 bg-muted/50" />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {showField('is_offshore') ? (
                                        <RecordFormField
                                            field="is_offshore"
                                            highlightMissing={isMissingRequired(
                                                'is_offshore',
                                            )}
                                            className="sm:col-span-2"
                                        >
                                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                                <label className="flex items-center gap-3 text-sm text-foreground">
                                                    <Checkbox
                                                        checked={
                                                            employeeForm.data
                                                                .is_offshore
                                                        }
                                                        onCheckedChange={(v) =>
                                                            employeeForm.setData(
                                                                'is_offshore',
                                                                v === true,
                                                            )
                                                        }
                                                    />
                                                    <div>
                                                        <div className="font-medium">
                                                            Offshore experience
                                                            <RequiredIndicator
                                                                show={isFieldRequired(
                                                                    'is_offshore',
                                                                )}
                                                            />
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                            Mark if this sea
                                                            service was
                                                            completed offshore
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                </div>
                            </>
                        ) : null}
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
                                    resolvedEmployeeId =
                                        await resolveEmployeeIdForSave(
                                            employeeId,
                                            ensureEmployee,
                                        );
                                } catch {
                                    return;
                                }

                                if (
                                    !validateRequired(
                                        employeeForm.data as Record<
                                            string,
                                            unknown
                                        >,
                                    )
                                ) {
                                    return;
                                }

                                employeeForm.clearErrors();

                                const payload = buildSeaServicePayload(
                                    employeeForm.data,
                                    templateFields,
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
                                        clearMissingRequired();
                                    },
                                    onError: (
                                        errors: Record<string, string>,
                                    ) => {
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
                                    router.put(url, payload as RequestPayload, options);
                                } else {
                                    router.post(url, payload as RequestPayload, options);
                                }
                            }}
                        >
                            {employeeForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDeleteDialog
                open={bulkDeleteOpen}
                onOpenChange={setBulkDeleteOpen}
                title="Remove selected sea service records?"
                description={`${selectedSeaServiceCount} selected ${selectedSeaServiceCount === 1 ? 'record' : 'records'} will be permanently removed.`}
                confirmText={isBulkDeleting ? 'Removing…' : 'Remove'}
                onConfirm={() => {
                    if (
                        selectedSeaServiceIds.length === 0 ||
                        employeeId === null
                    ) {
                        return;
                    }

                    setIsBulkDeleting(true);

                    router.delete(
                        bulkDestroySeaServices.url({ employee: employeeId }),
                        {
                            data: { sea_service_ids: selectedSeaServiceIds },
                            ...SEA_SERVICE_RELOAD,
                            onSuccess: () => {
                                clearSeaServiceSelection();
                                setBulkDeleteOpen(false);
                            },
                            onFinish: () => {
                                setIsBulkDeleting(false);
                            },
                        },
                    );
                }}
                contentClassName="sm:max-w-sm"
                cancelButtonClassName="border-border bg-muted/50 text-muted-foreground hover:bg-accent hover:text-foreground dark:border-white/10 dark:bg-white/5 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                confirmButtonClassName="bg-red-600 text-white hover:bg-red-500"
            />

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
