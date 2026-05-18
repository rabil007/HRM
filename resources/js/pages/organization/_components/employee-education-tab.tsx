import { router, useForm } from '@inertiajs/react';
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
import { TabsContent } from '@/components/ui/tabs';
import type { CountryOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
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
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Education qualifications
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {education_qualifications.length} total
                        </span>
                    </h3>
                    {canManage && (
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
                    )}
                </div>

                {education_qualifications.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No qualifications recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Certificate</th>
                                    <th className="py-2 pr-4">Issue date</th>
                                    <th className="py-2 pr-4">University</th>
                                    <th className="py-2 pr-4">Country</th>
                                    {canManage ? (
                                        <th className="py-2 pr-4" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {education_qualifications.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="text-sm text-zinc-200"
                                    >
                                        <td className="py-3 pr-4 font-medium">
                                            {row.certificate}
                                        </td>
                                        <td className="py-3 pr-4 font-mono text-xs text-zinc-400">
                                            {row.issue_date ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-zinc-300">
                                            {row.university ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-xs text-zinc-400">
                                            {row.country_name ?? '—'}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 pr-4">
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                        onClick={() => {
                                                            setEditingEducation(
                                                                row,
                                                            );
                                                            educationForm.setData(
                                                                {
                                                                    certificate:
                                                                        row.certificate,
                                                                    issue_date:
                                                                        row.issue_date ??
                                                                        '',
                                                                    university:
                                                                        row.university ??
                                                                        '',
                                                                    country_id:
                                                                        row.country_id
                                                                            ? String(
                                                                                  row.country_id,
                                                                              )
                                                                            : '',
                                                                },
                                                            );
                                                            educationForm.clearErrors();
                                                            setEducationDialogOpen(
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
                                                            setDeleteEducationId(
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
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingEducation
                                ? 'Edit qualification'
                                : 'Add qualification'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs">Certificate</Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                value={educationForm.data.certificate}
                                onChange={(e) =>
                                    educationForm.setData(
                                        'certificate',
                                        e.target.value,
                                    )
                                }
                            />
                            {educationForm.errors.certificate ? (
                                <p className="text-xs text-destructive">
                                    {educationForm.errors.certificate}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Issue date</Label>
                            <Input
                                type="date"
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                value={educationForm.data.issue_date}
                                onChange={(e) =>
                                    educationForm.setData(
                                        'issue_date',
                                        e.target.value,
                                    )
                                }
                            />
                            {educationForm.errors.issue_date ? (
                                <p className="text-xs text-destructive">
                                    {educationForm.errors.issue_date}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">
                                University / institution
                            </Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                value={educationForm.data.university}
                                onChange={(e) =>
                                    educationForm.setData(
                                        'university',
                                        e.target.value,
                                    )
                                }
                            />
                            {educationForm.errors.university ? (
                                <p className="text-xs text-destructive">
                                    {educationForm.errors.university}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Country</Label>
                            <select
                                value={educationForm.data.country_id}
                                onChange={(e) =>
                                    educationForm.setData(
                                        'country_id',
                                        e.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option value="">—</option>
                                {countries.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            {educationForm.errors.country_id ? (
                                <p className="text-xs text-destructive">
                                    {educationForm.errors.country_id}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={() => setEducationDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
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
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove qualification?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This education record will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="text-destructive-foreground bg-destructive hover:bg-destructive/90"
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
