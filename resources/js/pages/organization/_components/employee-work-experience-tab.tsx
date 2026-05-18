import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyWorkExperience,
    store as storeWorkExperience,
    update as updateWorkExperience,
} from '@/actions/App/Http/Controllers/Organization/EmployeeWorkExperienceController';
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
import { toast } from '@/lib/toast';
import { WorkExperienceImportDialog } from '@/pages/organization/_components/work-experience-import-dialog';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { WorkExperienceItem } from '@/pages/organization/employee-page.types';

const WORK_EXPERIENCE_RELOAD = {
    preserveScroll: true,
    only: ['work_experiences'],
} as const;

export type EmployeeWorkExperienceTabProps = {
    employeeId: number;
    work_experiences: WorkExperienceItem[];
    canManage: boolean;
};

export function EmployeeWorkExperienceTab({
    employeeId,
    work_experiences,
    canManage,
}: EmployeeWorkExperienceTabProps): ReactElement {
    const [workExperienceDialogOpen, setWorkExperienceDialogOpen] =
        useState(false);
    const [workExperienceImportOpen, setWorkExperienceImportOpen] =
        useState(false);
    const [editingWorkExperience, setEditingWorkExperience] =
        useState<WorkExperienceItem | null>(null);
    const [deleteWorkExperienceId, setDeleteWorkExperienceId] = useState<
        number | null
    >(null);

    const workExperienceForm = useForm({
        company_name: '',
        job_title: '',
        date_from: '',
        date_to: '',
        responsibility: '',
    });

    return (
        <TabsContent value="work_experience" className="mt-6">
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Work experience
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {work_experiences.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() =>
                                    setWorkExperienceImportOpen(true)
                                }
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => {
                                    workExperienceForm.reset();
                                    workExperienceForm.clearErrors();
                                    setEditingWorkExperience(null);
                                    setWorkExperienceDialogOpen(true);
                                }}
                            >
                                + Add line
                            </Button>
                        </div>
                    ) : null}
                </div>

                {work_experiences.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No work history recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[800px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Company</th>
                                    <th className="py-2 pr-4">Job title</th>
                                    <th className="py-2 pr-4">From</th>
                                    <th className="py-2 pr-4">To</th>
                                    <th className="py-2 pr-4">
                                        Responsibility
                                    </th>
                                    <th className="py-2 pr-4">Added</th>
                                    {canManage ? (
                                        <th className="py-2 pr-4 text-right" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {work_experiences.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="text-sm text-zinc-200"
                                    >
                                        <td
                                            className="max-w-[200px] truncate py-3 pr-4 font-medium"
                                            title={row.company_name}
                                        >
                                            {row.company_name}
                                        </td>
                                        <td
                                            className="max-w-[160px] truncate py-3 pr-4 text-zinc-300"
                                            title={row.job_title}
                                        >
                                            {row.job_title}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-400">
                                            {formatIsoDateDisplay(
                                                row.date_from,
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-400">
                                            {formatIsoDateDisplay(row.date_to)}
                                        </td>
                                        <td
                                            className="max-w-[220px] truncate py-3 pr-4 text-xs text-zinc-400"
                                            title={row.responsibility ?? ''}
                                        >
                                            {row.responsibility?.trim()
                                                ? row.responsibility
                                                : '—'}
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
                                                            setEditingWorkExperience(
                                                                row,
                                                            );
                                                            workExperienceForm.setData(
                                                                {
                                                                    company_name:
                                                                        row.company_name,
                                                                    job_title:
                                                                        row.job_title,
                                                                    date_from:
                                                                        row.date_from ??
                                                                        '',
                                                                    date_to:
                                                                        row.date_to ??
                                                                        '',
                                                                    responsibility:
                                                                        row.responsibility ??
                                                                        '',
                                                                },
                                                            );
                                                            workExperienceForm.clearErrors();
                                                            setWorkExperienceDialogOpen(
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
                                                            setDeleteWorkExperienceId(
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
                open={workExperienceDialogOpen}
                onOpenChange={(openDialog) => {
                    setWorkExperienceDialogOpen(openDialog);

                    if (!openDialog) {
                        workExperienceForm.reset();
                        workExperienceForm.clearErrors();
                        setEditingWorkExperience(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingWorkExperience ? 'Edit work experience' : 'Add work experience'}
                        </DialogTitle>
                        <p className="text-xs text-zinc-500">
                            Add details about the employee's previous employment.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Role details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Company name <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. Ocean Maritime LLC"
                                    value={workExperienceForm.data.company_name}
                                    onChange={(e) => workExperienceForm.setData('company_name', e.target.value)}
                                />
                                {workExperienceForm.errors.company_name ? (
                                    <p className="text-xs text-destructive">{workExperienceForm.errors.company_name}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">The employer's name</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Job title <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. Chief Engineer"
                                    value={workExperienceForm.data.job_title}
                                    onChange={(e) => workExperienceForm.setData('job_title', e.target.value)}
                                />
                                {workExperienceForm.errors.job_title ? (
                                    <p className="text-xs text-destructive">{workExperienceForm.errors.job_title}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">The held position or rank</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Start date <span className="text-red-400">*</span></Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={workExperienceForm.data.date_from}
                                    onChange={(e) => workExperienceForm.setData('date_from', e.target.value)}
                                />
                                {workExperienceForm.errors.date_from ? (
                                    <p className="text-xs text-destructive">{workExperienceForm.errors.date_from}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">When the employment started</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">End date</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={workExperienceForm.data.date_to}
                                    onChange={(e) => workExperienceForm.setData('date_to', e.target.value)}
                                />
                                {workExperienceForm.errors.date_to ? (
                                    <p className="text-xs text-destructive">{workExperienceForm.errors.date_to}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Leave empty if currently employed</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Responsibilities</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Description</Label>
                            <textarea
                                rows={4}
                                placeholder="Describe the main tasks, responsibilities, and achievements..."
                                className="min-h-[88px] w-full resize-y rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-zinc-200 outline-none focus:ring-1 focus:ring-primary"
                                value={workExperienceForm.data.responsibility}
                                onChange={(e) => workExperienceForm.setData('responsibility', e.target.value)}
                            />
                            {workExperienceForm.errors.responsibility ? (
                                <p className="text-xs text-destructive">{workExperienceForm.errors.responsibility}</p>
                            ) : (
                                <p className="text-[11px] text-zinc-500">Optional description of the role</p>
                            )}
                        </div>
                    </div>
                    <DialogFooter className="border-t border-white/5 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => setWorkExperienceDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={workExperienceForm.processing}
                            onClick={() => {
                                workExperienceForm.clearErrors();
                                workExperienceForm.transform((data) => ({
                                    company_name: data.company_name.trim(),
                                    job_title: data.job_title.trim(),
                                    date_from: data.date_from,
                                    date_to:
                                        data.date_to === ''
                                            ? null
                                            : data.date_to,
                                    responsibility:
                                        data.responsibility.trim() === ''
                                            ? null
                                            : data.responsibility.trim(),
                                }));

                                const url = editingWorkExperience
                                    ? updateWorkExperience.url({
                                          employee: employeeId,
                                          workExperience:
                                              editingWorkExperience.id,
                                      })
                                    : storeWorkExperience.url({
                                          employee: employeeId,
                                      });

                                if (editingWorkExperience) {
                                    workExperienceForm.put(url, {
                                        ...WORK_EXPERIENCE_RELOAD,
                                        onSuccess: () => {
                                            setWorkExperienceDialogOpen(false);
                                            workExperienceForm.reset();
                                            setEditingWorkExperience(null);
                                            toast.success(
                                                'Work experience updated.',
                                            );
                                        },
                                    });
                                } else {
                                    workExperienceForm.post(url, {
                                        ...WORK_EXPERIENCE_RELOAD,
                                        onSuccess: () => {
                                            setWorkExperienceDialogOpen(false);
                                            workExperienceForm.reset();
                                            toast.success(
                                                'Work experience added.',
                                            );
                                        },
                                    });
                                }
                            }}
                        >
                            {workExperienceForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteWorkExperienceId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteWorkExperienceId(null);
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
                                Remove work experience?
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
                                if (!deleteWorkExperienceId) {
                                    return;
                                }

                                router.delete(
                                    destroyWorkExperience.url({
                                        employee: employeeId,
                                        workExperience: deleteWorkExperienceId,
                                    }),
                                    {
                                        ...WORK_EXPERIENCE_RELOAD,
                                        onSuccess: () => {
                                            setDeleteWorkExperienceId(null);
                                            toast.success(
                                                'Work experience removed.',
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

            <WorkExperienceImportDialog
                open={workExperienceImportOpen}
                onOpenChange={setWorkExperienceImportOpen}
                employeeId={employeeId}
            />
        </TabsContent>
    );
}
