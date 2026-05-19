import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Organization/EmployeeEducationQualificationController';
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
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { TabsContent } from '@/components/ui/tabs';
import type { CountryOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
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
import type { EducationQualificationItem } from '@/pages/organization/employee-page.types';

const EDUCATION_RELOAD = {
    preserveScroll: true,
    only: ['education_qualifications'],
} as const;

export type EmployeeEducationTabProps = {
    employeeId: number;
    education_qualifications: EducationQualificationItem[];
    countries: CountryOption[];
    canManage: boolean;
};

export function EmployeeEducationTab({
    employeeId,
    education_qualifications,
    countries,
    canManage,
}: EmployeeEducationTabProps): ReactElement {
    const [educationDialogOpen, setEducationDialogOpen] = useState(false);
    const [editingEducation, setEditingEducation] =
        useState<EducationQualificationItem | null>(null);
    const [deleteEducationId, setDeleteEducationId] = useState<number | null>(
        null,
    );

    const educationForm = useForm({
        certificate: '',
        issue_date: '',
        university: '',
        country_id: '',
    });

    return (
        <TabsContent value="education" className="mt-6">
            <EmployeeRecordsPanel
                title="Education qualifications"
                count={education_qualifications.length}
                isEmpty={education_qualifications.length === 0}
                emptyMessage="No qualifications recorded."
                actions={
                    canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={() => {
                                educationForm.reset();
                                educationForm.clearErrors();
                                setEditingEducation(null);
                                setEducationDialogOpen(true);
                            }}
                        >
                            + Add qualification
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[680px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Certificate</th>
                            <th className={employeeRecordsTableThClass()}>Issue date</th>
                            <th className={employeeRecordsTableThClass()}>University</th>
                            <th className={employeeRecordsTableThClass()}>Country</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {education_qualifications.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'font-medium text-zinc-100',
                                    )}
                                >
                                    {row.certificate}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'font-mono text-xs text-zinc-400',
                                    )}
                                >
                                    {row.issue_date ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-zinc-300',
                                    )}
                                >
                                    {row.university ?? '—'}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-xs text-zinc-400',
                                    )}
                                >
                                    {row.country_name ?? '—'}
                                </td>
                                {canManage ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingEducation(row);
                                                educationForm.setData({
                                                    certificate: row.certificate,
                                                    issue_date: row.issue_date ?? '',
                                                    university: row.university ?? '',
                                                    country_id: row.country_id
                                                        ? String(row.country_id)
                                                        : '',
                                                });
                                                educationForm.clearErrors();
                                                setEducationDialogOpen(true);
                                            }}
                                            onDelete={() =>
                                                setDeleteEducationId(row.id)
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
                open={educationDialogOpen}
                onOpenChange={(open) => {
                    setEducationDialogOpen(open);

                    if (!open) {
                        educationForm.reset();
                        educationForm.clearErrors();
                        setEditingEducation(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingEducation ? 'Edit qualification' : 'Add qualification'}
                        </DialogTitle>
                        <p className="text-xs text-zinc-500">
                            Enter the details of the educational qualification.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Qualification details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Certificate / Degree <span className="text-red-400">*</span></Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                placeholder="e.g. Bachelor of Science in Marine Engineering"
                                value={educationForm.data.certificate}
                                onChange={(e) => educationForm.setData('certificate', e.target.value)}
                            />
                            {educationForm.errors.certificate ? (
                                <p className="text-xs text-destructive">{educationForm.errors.certificate}</p>
                            ) : (
                                <p className="text-[11px] text-zinc-500">The title of the obtained qualification</p>
                            )}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">University / Institution</Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. Maritime Academy"
                                    value={educationForm.data.university}
                                    onChange={(e) => educationForm.setData('university', e.target.value)}
                                />
                                {educationForm.errors.university ? (
                                    <p className="text-xs text-destructive">{educationForm.errors.university}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">The awarding institution (optional)</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Country</Label>
                                <AppSelect
                                    value={educationForm.data.country_id}
                                    onValueChange={(v) => educationForm.setData('country_id', v)}
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
                                {educationForm.errors.country_id ? (
                                    <p className="text-xs text-destructive">{educationForm.errors.country_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Country of the institution (optional)</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Issue date</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={educationForm.data.issue_date}
                                    onChange={(e) => educationForm.setData('issue_date', e.target.value)}
                                />
                                {educationForm.errors.issue_date ? (
                                    <p className="text-xs text-destructive">{educationForm.errors.issue_date}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">When the certificate was issued (optional)</p>
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
                            onClick={() => setEducationDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={educationForm.processing}
                            onClick={() => {
                                educationForm.clearErrors();
                                educationForm.transform((data) => ({
                                    certificate: data.certificate.trim(),
                                    issue_date:
                                        data.issue_date === ''
                                            ? null
                                            : data.issue_date,
                                    university:
                                        data.university.trim() === ''
                                            ? null
                                            : data.university.trim(),
                                    country_id:
                                        data.country_id === ''
                                            ? null
                                            : Number(data.country_id),
                                }));

                                const url = editingEducation
                                    ? update.url({
                                          employee: employeeId,
                                          qualification: editingEducation.id,
                                      })
                                    : store.url({ employee: employeeId });

                                if (editingEducation) {
                                    educationForm.put(url, {
                                        ...EDUCATION_RELOAD,
                                        onSuccess: () => {
                                            setEducationDialogOpen(false);
                                            educationForm.reset();
                                            setEditingEducation(null);
                                            toast.success(
                                                'Qualification updated.',
                                            );
                                        },
                                    });
                                } else {
                                    educationForm.post(url, {
                                        ...EDUCATION_RELOAD,
                                        onSuccess: () => {
                                            setEducationDialogOpen(false);
                                            educationForm.reset();
                                            toast.success(
                                                'Qualification added.',
                                            );
                                        },
                                    });
                                }
                            }}
                        >
                            {educationForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteEducationId}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteEducationId(null);
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
                                Remove qualification?
                            </AlertDialogTitle>
                        </div>
                        <AlertDialogDescription>
                            This education record will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-500"
                            onClick={() => {
                                if (!deleteEducationId) {
                                    return;
                                }

                                router.delete(
                                    destroy.url({
                                        employee: employeeId,
                                        qualification: deleteEducationId,
                                    }),
                                    {
                                        ...EDUCATION_RELOAD,
                                        onSuccess: () => {
                                            setDeleteEducationId(null);
                                            toast.success(
                                                'Qualification removed.',
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
