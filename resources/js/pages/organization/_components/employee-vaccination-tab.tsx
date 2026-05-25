import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import {
    destroy as destroyVaccination,
    store as storeVaccination,
    update as updateVaccination,
} from '@/actions/App/Http/Controllers/Organization/EmployeeVaccinationController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
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
import { vaccinationImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { CountryOption } from '@/features/organization/employees/types';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import type { VaccinationItem } from '@/pages/organization/employee-page.types';

const VACCINATION_RELOAD = {
    preserveScroll: true,
    only: ['vaccinations'],
} as const;

export type EmployeeVaccinationTabProps = {
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    vaccinations: VaccinationItem[];
    countries: CountryOption[];
    canManage: boolean;
};

export function EmployeeVaccinationTab({
    employeeId,
    ensureEmployee,
    vaccinations,
    countries,
    canManage,
}: EmployeeVaccinationTabProps): ReactElement {
    const [vaccinationDialogOpen, setVaccinationDialogOpen] = useState(false);
    const [vaccinationImportOpen, setVaccinationImportOpen] = useState(false);
    const [editingVaccination, setEditingVaccination] =
        useState<VaccinationItem | null>(null);
    const [deleteVaccinationId, setDeleteVaccinationId] = useState<
        number | null
    >(null);

    const vaccinationForm = useForm({
        vaccination_name: '',
        country_id: '',
        first_dose_date: '',
        second_dose_date: '',
        booster_dose_date: '',
    });

    const vaccinationImport = vaccinationImportConfig(employeeId);
    const vaccinationImportUrls = useMemo(
        () =>
            resolveRecordImportUrls(
                vaccinationImportConfig(employeeId),
                employeeId,
            ),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;

    return (
        <TabsContent value="vaccination" className="mt-6">
            <EmployeeRecordsPanel
                title="Vaccination"
                count={vaccinations.length}
                isEmpty={vaccinations.length === 0}
                emptyMessage="No vaccination records."
                actions={
                    canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                disabled={!canImportRecords}
                                onClick={() => setVaccinationImportOpen(true)}
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => {
                                    vaccinationForm.reset();
                                    vaccinationForm.clearErrors();
                                    setEditingVaccination(null);
                                    setVaccinationDialogOpen(true);
                                }}
                            >
                                + Add line
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[880px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Vaccination</th>
                            <th className={employeeRecordsTableThClass()}>Country</th>
                            <th className={employeeRecordsTableThClass()}>1st dose</th>
                            <th className={employeeRecordsTableThClass()}>2nd dose</th>
                            <th className={employeeRecordsTableThClass()}>Booster</th>
                            <th className={employeeRecordsTableThClass()}>Added</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {vaccinations.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate font-medium text-foreground',
                                    )}
                                    title={row.vaccination_name}
                                >
                                    {row.vaccination_name}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                    {row.country_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-muted-foreground')}>
                                    {formatIsoDateDisplay(row.first_dose_date)}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-muted-foreground')}>
                                    {formatIsoDateDisplay(row.second_dose_date)}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-muted-foreground')}>
                                    {formatIsoDateDisplay(row.booster_dose_date)}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-muted-foreground')}>
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingVaccination(row);
                                                vaccinationForm.setData({
                                                    vaccination_name: row.vaccination_name,
                                                    country_id: row.country_id ? String(row.country_id) : '',
                                                    first_dose_date: row.first_dose_date ?? '',
                                                    second_dose_date: row.second_dose_date ?? '',
                                                    booster_dose_date: row.booster_dose_date ?? '',
                                                });
                                                vaccinationForm.clearErrors();
                                                setVaccinationDialogOpen(true);
                                            }}
                                            onDelete={() => setDeleteVaccinationId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
            <Dialog
                open={vaccinationDialogOpen}
                onOpenChange={(openDialog) => {
                    setVaccinationDialogOpen(openDialog);

                    if (!openDialog) {
                        vaccinationForm.reset();
                        vaccinationForm.clearErrors();
                        setEditingVaccination(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingVaccination ? 'Edit vaccination' : 'Add vaccination'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Log a vaccination record and dose dates.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Vaccine details</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vaccination name <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    placeholder="e.g. COVID-19 (Pfizer), Yellow Fever"
                                    value={vaccinationForm.data.vaccination_name}
                                    onChange={(e) => vaccinationForm.setData('vaccination_name', e.target.value)}
                                />
                                {vaccinationForm.errors.vaccination_name ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.vaccination_name}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">The name or type of the vaccine</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Country</Label>
                                <AppSelect
                                    value={vaccinationForm.data.country_id}
                                    onValueChange={(v) => vaccinationForm.setData('country_id', v)}
                                    variant="dark"
                                    placeholder="— Select a country —"
                                >
                                    <AppSelectItem value="">— Select a country —</AppSelectItem>
                                    {countries.map((c) => (
                                        <AppSelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {vaccinationForm.errors.country_id ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.country_id}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Where the vaccination was administered (optional)</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Dose dates</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label className="text-xs">1st dose</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={vaccinationForm.data.first_dose_date}
                                    onChange={(e) => vaccinationForm.setData('first_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.first_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.first_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Date of first dose</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">2nd dose</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={vaccinationForm.data.second_dose_date}
                                    onChange={(e) => vaccinationForm.setData('second_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.second_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.second_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Date of second dose</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Booster</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                    value={vaccinationForm.data.booster_dose_date}
                                    onChange={(e) => vaccinationForm.setData('booster_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.booster_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.booster_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">Date of booster dose</p>
                                )}
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setVaccinationDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={vaccinationForm.processing}
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

                                vaccinationForm.clearErrors();
                                vaccinationForm.transform((data) => ({
                                    vaccination_name:
                                        data.vaccination_name.trim(),
                                    country_id:
                                        data.country_id === ''
                                            ? null
                                            : Number(data.country_id),
                                    first_dose_date:
                                        data.first_dose_date === ''
                                            ? null
                                            : data.first_dose_date,
                                    second_dose_date:
                                        data.second_dose_date === ''
                                            ? null
                                            : data.second_dose_date,
                                    booster_dose_date:
                                        data.booster_dose_date === ''
                                            ? null
                                            : data.booster_dose_date,
                                }));

                                const url = editingVaccination
                                    ? updateVaccination.url({
                                          employee: resolvedEmployeeId,
                                          vaccination: editingVaccination.id,
                                      })
                                    : storeVaccination.url({
                                          employee: resolvedEmployeeId,
                                      });

                                if (editingVaccination) {
                                    vaccinationForm.put(url, {
                                        ...VACCINATION_RELOAD,
                                        onSuccess: () => {
                                            setVaccinationDialogOpen(false);
                                            vaccinationForm.reset();
                                            setEditingVaccination(null);
                                        },
                                    });
                                } else {
                                    vaccinationForm.post(url, {
                                        ...VACCINATION_RELOAD,
                                        onSuccess: () => {
                                            setVaccinationDialogOpen(false);
                                            vaccinationForm.reset();
                                        },
                                    });
                                }
                            }}
                        >
                            {vaccinationForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteVaccinationId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteVaccinationId(null);
                    }
                }}
                title="Remove vaccination record?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteVaccinationId && employeeId
                        ? destroyVaccination.url({
                              employee: employeeId,
                              vaccination: deleteVaccinationId,
                          })
                        : null
                }
                reloadOptions={VACCINATION_RELOAD}
            />

            <EmployeeRecordImportDialog
                open={vaccinationImportOpen}
                onOpenChange={setVaccinationImportOpen}
                inputId={vaccinationImport.inputId}
                title={vaccinationImport.title}
                description={vaccinationImport.description}
                templateHint={vaccinationImport.templateHint}
                columnHelp={vaccinationImport.columnHelp}
                reloadOnly={vaccinationImport.reloadOnly}
                importUrl={vaccinationImportUrls.importUrl}
                templateUrl={vaccinationImportUrls.templateUrl}
            />
        </TabsContent>
    );
}
