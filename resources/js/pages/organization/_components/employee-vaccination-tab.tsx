import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyVaccination,
    store as storeVaccination,
    update as updateVaccination,
} from '@/actions/App/Http/Controllers/Organization/EmployeeVaccinationController';
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
import type { CountryOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import { VaccinationImportDialog } from '@/pages/organization/_components/vaccination-import-dialog';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { VaccinationItem } from '@/pages/organization/employee-page.types';

const VACCINATION_RELOAD = {
    preserveScroll: true,
    only: ['vaccinations'],
} as const;

export type EmployeeVaccinationTabProps = {
    employeeId: number;
    vaccinations: VaccinationItem[];
    countries: CountryOption[];
    canManage: boolean;
};

export function EmployeeVaccinationTab({
    employeeId,
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

    return (
        <TabsContent value="vaccination" className="mt-6">
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Vaccination
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {vaccinations.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
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
                    ) : null}
                </div>

                {vaccinations.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No vaccination records.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[880px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Vaccination</th>
                                    <th className="py-2 pr-4">Country</th>
                                    <th className="py-2 pr-4">1st dose</th>
                                    <th className="py-2 pr-4">2nd dose</th>
                                    <th className="py-2 pr-4">Booster</th>
                                    <th className="py-2 pr-4">Added</th>
                                    {canManage ? (
                                        <th className="py-2 pr-4 text-right" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {vaccinations.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="text-sm text-zinc-200"
                                    >
                                        <td
                                            className="max-w-[200px] truncate py-3 pr-4 font-medium"
                                            title={row.vaccination_name}
                                        >
                                            {row.vaccination_name}
                                        </td>
                                        <td className="py-3 pr-4 text-xs text-zinc-400">
                                            {row.country_name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-400">
                                            {formatIsoDateDisplay(
                                                row.first_dose_date,
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-400">
                                            {formatIsoDateDisplay(
                                                row.second_dose_date,
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-400">
                                            {formatIsoDateDisplay(
                                                row.booster_dose_date,
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-500">
                                            {new Date(
                                                row.created_at,
                                            ).toLocaleString(undefined, {
                                                month: 'short',
                                                day: 'numeric',
                                                hour: 'numeric',
                                                minute: '2-digit',
                                            })}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 pr-0 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                        onClick={() => {
                                                            setEditingVaccination(
                                                                row,
                                                            );
                                                            vaccinationForm.setData(
                                                                {
                                                                    vaccination_name:
                                                                        row.vaccination_name,
                                                                    country_id:
                                                                        row.country_id
                                                                            ? String(
                                                                                  row.country_id,
                                                                              )
                                                                            : '',
                                                                    first_dose_date:
                                                                        row.first_dose_date ??
                                                                        '',
                                                                    second_dose_date:
                                                                        row.second_dose_date ??
                                                                        '',
                                                                    booster_dose_date:
                                                                        row.booster_dose_date ??
                                                                        '',
                                                                },
                                                            );
                                                            vaccinationForm.clearErrors();
                                                            setVaccinationDialogOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                        onClick={() =>
                                                            setDeleteVaccinationId(
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
                        <p className="text-xs text-zinc-500">
                            Log a vaccination record and dose dates.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Vaccine details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Vaccination name <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. COVID-19 (Pfizer), Yellow Fever"
                                    value={vaccinationForm.data.vaccination_name}
                                    onChange={(e) => vaccinationForm.setData('vaccination_name', e.target.value)}
                                />
                                {vaccinationForm.errors.vaccination_name ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.vaccination_name}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">The name or type of the vaccine</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Country</Label>
                                <select
                                    value={vaccinationForm.data.country_id}
                                    onChange={(e) => vaccinationForm.setData('country_id', e.target.value)}
                                    className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                                >
                                    <option value="">— Select a country —</option>
                                    {countries.map((c) => (
                                        <option key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                {vaccinationForm.errors.country_id ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.country_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Where the vaccination was administered (optional)</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Dose dates</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label className="text-xs">1st dose</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={vaccinationForm.data.first_dose_date}
                                    onChange={(e) => vaccinationForm.setData('first_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.first_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.first_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Date of first dose</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">2nd dose</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={vaccinationForm.data.second_dose_date}
                                    onChange={(e) => vaccinationForm.setData('second_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.second_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.second_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Date of second dose</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Booster</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={vaccinationForm.data.booster_dose_date}
                                    onChange={(e) => vaccinationForm.setData('booster_dose_date', e.target.value)}
                                />
                                {vaccinationForm.errors.booster_dose_date ? (
                                    <p className="text-xs text-destructive">{vaccinationForm.errors.booster_dose_date}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Date of booster dose</p>
                                )}
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="border-t border-white/5 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => setVaccinationDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={vaccinationForm.processing}
                            onClick={() => {
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
                                          employee: employeeId,
                                          vaccination: editingVaccination.id,
                                      })
                                    : storeVaccination.url({
                                          employee: employeeId,
                                      });

                                if (editingVaccination) {
                                    vaccinationForm.put(url, {
                                        ...VACCINATION_RELOAD,
                                        onSuccess: () => {
                                            setVaccinationDialogOpen(false);
                                            vaccinationForm.reset();
                                            setEditingVaccination(null);
                                            toast.success(
                                                'Vaccination updated.',
                                            );
                                        },
                                    });
                                } else {
                                    vaccinationForm.post(url, {
                                        ...VACCINATION_RELOAD,
                                        onSuccess: () => {
                                            setVaccinationDialogOpen(false);
                                            vaccinationForm.reset();
                                            toast.success('Vaccination added.');
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

            <AlertDialog
                open={!!deleteVaccinationId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteVaccinationId(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-sm">
                    <AlertDialogHeader>
                        <div className="mb-1 flex items-center gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-red-400">
                                <Trash2 className="size-4" />
                            </span>
                            <AlertDialogTitle>
                                Remove vaccination record?
                            </AlertDialogTitle>
                        </div>
                        <AlertDialogDescription>
                            This entry will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-500"
                            onClick={() => {
                                if (!deleteVaccinationId) {
                                    return;
                                }

                                router.delete(
                                    destroyVaccination.url({
                                        employee: employeeId,
                                        vaccination: deleteVaccinationId,
                                    }),
                                    {
                                        ...VACCINATION_RELOAD,
                                        onSuccess: () => {
                                            setDeleteVaccinationId(null);
                                            toast.success(
                                                'Vaccination removed.',
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

            <VaccinationImportDialog
                open={vaccinationImportOpen}
                onOpenChange={setVaccinationImportOpen}
                employeeId={employeeId}
            />
        </TabsContent>
    );
}
