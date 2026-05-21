import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useRef, useState } from 'react';
import {
    destroy as destroyTraining,
    store as storeTraining,
    update as updateTraining,
} from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { EmployeeRecordImportDialog } from '@/features/organization/employees/profile/components/employee-record-import-dialog';
import { trainingImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import type { CountryOption } from '@/features/organization/employees/types';
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
import type { CourseOption, TrainingItem } from '@/pages/organization/employee-page.types';

const TRAINING_RELOAD = {
    preserveScroll: true,
    only: ['trainings'],
} as const;

export type EmployeeTrainingTabProps = {
    employeeId: number;
    trainings: TrainingItem[];
    courses: CourseOption[];
    countries: CountryOption[];
    canManage: boolean;
};

export function EmployeeTrainingTab({
    employeeId,
    trainings,
    courses,
    countries,
    canManage,
}: EmployeeTrainingTabProps): ReactElement {
    const [trainingDialogOpen, setTrainingDialogOpen] = useState(false);
    const [trainingImportOpen, setTrainingImportOpen] = useState(false);
    const [editingTraining, setEditingTraining] = useState<TrainingItem | null>(null);
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(null);
    const certificateInputRef = useRef<HTMLInputElement>(null);

    const trainingForm = useForm<{
        course_id: string;
        issue_date: string;
        expiry_date: string;
        institute_center: string;
        country_id: string;
        certificate: File | null;
    }>({
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
        certificate: null,
    });

    const trainingImport = trainingImportConfig(employeeId);

    const { selectOptions: courseSelectOptions, appendOption: appendCourseOption } =
        useMutableSelectOptions(courses);
    const { canCreate: canCreateCourse, createConfig: courseCreateConfig } =
        useCreatableMasterData('course');

    const submitTraining = () => {
        trainingForm.clearErrors();
        trainingForm.transform((data) => ({
            course_id: data.course_id,
            issue_date: data.issue_date,
            expiry_date: data.expiry_date === '' ? null : data.expiry_date,
            institute_center: data.institute_center.trim(),
            country_id: data.country_id === '' ? null : data.country_id,
            certificate: data.certificate,
        }));

        const url = editingTraining
            ? updateTraining.url({
                  employee: employeeId,
                  training: editingTraining.id,
              })
            : storeTraining.url({ employee: employeeId });

        const options = {
            ...TRAINING_RELOAD,
            forceFormData: true,
            onSuccess: () => {
                setTrainingDialogOpen(false);
                trainingForm.reset();
                setEditingTraining(null);

                if (certificateInputRef.current) {
                    certificateInputRef.current.value = '';
                }
            },
        };

        if (editingTraining) {
            trainingForm.put(url, options);
        } else {
            trainingForm.post(url, options);
        }
    };

    return (
        <TabsContent value="training" className="mt-6">
            <EmployeeRecordsPanel
                title="Training"
                count={trainings.length}
                isEmpty={trainings.length === 0}
                emptyMessage="No training records."
                actions={
                    canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => setTrainingImportOpen(true)}
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={() => {
                                    trainingForm.reset();
                                    trainingForm.clearErrors();
                                    setEditingTraining(null);

                                    if (certificateInputRef.current) {
                                        certificateInputRef.current.value = '';
                                    }

                                    setTrainingDialogOpen(true);
                                }}
                            >
                                + Add training
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[960px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Course</th>
                            <th className={employeeRecordsTableThClass()}>Issue date</th>
                            <th className={employeeRecordsTableThClass()}>Expiry date</th>
                            <th className={employeeRecordsTableThClass()}>Institute/Center</th>
                            <th className={employeeRecordsTableThClass()}>Created on</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {trainings.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[280px] truncate font-medium text-zinc-100',
                                    )}
                                    title={row.course_name ?? undefined}
                                >
                                    {row.course_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-zinc-400')}>
                                    {formatIsoDateDisplay(row.issue_date)}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-zinc-400')}>
                                    {formatIsoDateDisplay(row.expiry_date)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate text-zinc-300',
                                    )}
                                    title={row.institute_center}
                                >
                                    {row.institute_center}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-zinc-500')}>
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <div className="flex items-center justify-end gap-2">
                                            {row.certificate_url ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 text-xs"
                                                    asChild
                                                >
                                                    <a
                                                        href={row.certificate_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        Certificate
                                                    </a>
                                                </Button>
                                            ) : null}
                                            <EmployeeRecordRowActions
                                                onEdit={() => {
                                                    setEditingTraining(row);
                                                    trainingForm.setData({
                                                        course_id: String(row.course_id),
                                                        issue_date: row.issue_date,
                                                        expiry_date: row.expiry_date ?? '',
                                                        institute_center: row.institute_center,
                                                        country_id: row.country_id ? String(row.country_id) : '',
                                                        certificate: null,
                                                    });
                                                    trainingForm.clearErrors();

                                                    if (certificateInputRef.current) {
                                                        certificateInputRef.current.value = '';
                                                    }

                                                    setTrainingDialogOpen(true);
                                                }}
                                                onDelete={() => setDeleteTrainingId(row.id)}
                                            />
                                        </div>
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <Dialog
                open={trainingDialogOpen}
                onOpenChange={(openDialog) => {
                    setTrainingDialogOpen(openDialog);

                    if (!openDialog) {
                        trainingForm.reset();
                        trainingForm.clearErrors();
                        setEditingTraining(null);

                        if (certificateInputRef.current) {
                            certificateInputRef.current.value = '';
                        }
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{editingTraining ? 'Edit training' : 'Add training'}</DialogTitle>
                        <DialogDescription className="text-xs text-zinc-500">
                            Record a course completion, dates, and optional certificate.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Course details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label className="text-xs">
                                    Course <span className="text-red-400">*</span>
                                </Label>
                                <CreatableSelect
                                    value={trainingForm.data.course_id}
                                    onValueChange={(v) => trainingForm.setData('course_id', v)}
                                    variant="dark"
                                    placeholder="— Select a course —"
                                    options={courseSelectOptions}
                                    onOptionsChange={(next) => {
                                        const added = next.find(
                                            (option) =>
                                                !courseSelectOptions.some(
                                                    (existing) => existing.value === option.value,
                                                ),
                                        );

                                        if (added) {
                                            appendCourseOption({
                                                id: added.id,
                                                label: added.label,
                                            });
                                        }
                                    }}
                                    creatable
                                    canCreate={canCreateCourse}
                                    createConfig={courseCreateConfig}
                                />
                                {trainingForm.errors.course_id ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.course_id}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">From master data courses</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">
                                    Issue date <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={trainingForm.data.issue_date}
                                    onChange={(e) => trainingForm.setData('issue_date', e.target.value)}
                                />
                                {trainingForm.errors.issue_date ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.issue_date}</p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Expiry date</Label>
                                <Input
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={trainingForm.data.expiry_date}
                                    onChange={(e) => trainingForm.setData('expiry_date', e.target.value)}
                                />
                                {trainingForm.errors.expiry_date ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.expiry_date}</p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label className="text-xs">
                                    Institute/Center <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. BINA SENA MTC"
                                    value={trainingForm.data.institute_center}
                                    onChange={(e) => trainingForm.setData('institute_center', e.target.value)}
                                />
                                {trainingForm.errors.institute_center ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.institute_center}</p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label className="text-xs">Country</Label>
                                <AppSelect
                                    value={trainingForm.data.country_id}
                                    onValueChange={(v) => trainingForm.setData('country_id', v)}
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
                                {trainingForm.errors.country_id ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.country_id}</p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label className="text-xs">Certificate</Label>
                                <Input
                                    ref={certificateInputRef}
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1 file:text-xs file:text-zinc-200"
                                    onChange={(e) =>
                                        trainingForm.setData('certificate', e.target.files?.[0] ?? null)
                                    }
                                />
                                {editingTraining?.certificate_url ? (
                                    <p className="text-[11px] text-zinc-500">
                                        Leave empty to keep the current file.{' '}
                                        <a
                                            href={editingTraining.certificate_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary hover:underline"
                                        >
                                            View current certificate
                                        </a>
                                    </p>
                                ) : null}
                                {trainingForm.errors.certificate ? (
                                    <p className="text-xs text-destructive">{trainingForm.errors.certificate}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">PDF or image, max 5 MB (optional)</p>
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
                            onClick={() => setTrainingDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={trainingForm.processing}
                            onClick={submitTraining}
                        >
                            {trainingForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteTrainingId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteTrainingId(null);
                    }
                }}
                title="Remove training record?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteTrainingId
                        ? destroyTraining.url({
                              employee: employeeId,
                              training: deleteTrainingId,
                          })
                        : null
                }
                reloadOptions={TRAINING_RELOAD}
            />

            <EmployeeRecordImportDialog
                open={trainingImportOpen}
                onOpenChange={setTrainingImportOpen}
                inputId={trainingImport.inputId}
                title={trainingImport.title}
                description={trainingImport.description}
                templateHint={trainingImport.templateHint}
                columnHelp={trainingImport.columnHelp}
                reloadOnly={trainingImport.reloadOnly}
                importUrl={trainingImport.importUrl(employeeId)}
                templateUrl={trainingImport.templateUrl(employeeId)}
            />
        </TabsContent>
    );
}
