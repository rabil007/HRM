import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useMemo, useRef, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    destroy as destroyTraining,
    store as storeTraining,
    update as updateTraining,
} from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { CreatableSelect } from '@/components/ui/creatable-select';
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
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { CountryOption } from '@/features/organization/employees/types';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
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
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type {
    CourseOption,
    TemplateFieldConfig,
    TrainingItem,
} from '@/pages/organization/employee-page.types';

const TRAINING_RELOAD = {
    preserveScroll: true,
    only: ['trainings'],
} as const;

const CERTIFICATE_TEMPLATE_FIELD = 'certificate_path';

export type EmployeeTrainingTabProps = {
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    trainings: TrainingItem[];
    courses: CourseOption[];
    countries: CountryOption[];
    canManage: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeTrainingTab({
    employeeId,
    ensureEmployee,
    trainings,
    courses,
    countries,
    canManage,
    templateFields = null,
}: EmployeeTrainingTabProps): ReactElement {
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
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
    });

    const [trainingDialogOpen, setTrainingDialogOpen] = useState(false);
    const [trainingImportOpen, setTrainingImportOpen] = useState(false);
    const [editingTraining, setEditingTraining] = useState<TrainingItem | null>(null);
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(null);
    const [removeCertificate, setRemoveCertificate] = useState(false);
    const certificateInputRef = useRef<HTMLInputElement>(null);

    const trainingForm = useForm<{
        course_id: string;
        issue_date: string;
        expiry_date: string;
        institute_center: string;
        country_id: string;
        certificate: File | null;
        remove_certificate: boolean;
    }>({
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
        certificate: null,
        remove_certificate: false,
    });

    const trainingFormDataForTemplate = useMemo(() => {
        const hasExistingCertificate =
            !removeCertificate && Boolean(editingTraining?.certificate_url);

        return {
            ...trainingForm.data,
            certificate_path:
                trainingForm.data.certificate ??
                (hasExistingCertificate ? 'existing' : null),
        };
    }, [editingTraining?.certificate_url, removeCertificate, trainingForm.data]);

    useClearMissingOnFormChange(trainingFormDataForTemplate, syncMissingFromFormData);

    const trainingImport = trainingImportConfig(employeeId);
    const trainingImportUrls = useMemo(
        () =>
            resolveRecordImportUrls(trainingImportConfig(employeeId), employeeId),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;

    const { selectOptions: courseSelectOptions, appendOption: appendCourseOption } =
        useMutableSelectOptions(courses);
    const { canCreate: canCreateCourse, createConfig: courseCreateConfig } =
        useCreatableMasterData('course');

    const openCreateDialog = () => {
        trainingForm.reset();
        trainingForm.clearErrors();
        clearMissingRequired();
        setEditingTraining(null);
        setRemoveCertificate(false);

        if (certificateInputRef.current) {
            certificateInputRef.current.value = '';
        }

        setTrainingDialogOpen(true);
    };

    const openEditDialog = (row: TrainingItem) => {
        setEditingTraining(row);
        setRemoveCertificate(false);
        trainingForm.setData({
            course_id: String(row.course_id),
            issue_date: row.issue_date,
            expiry_date: row.expiry_date ?? '',
            institute_center: row.institute_center,
            country_id: row.country_id ? String(row.country_id) : '',
            certificate: null,
            remove_certificate: false,
        });
        trainingForm.clearErrors();
        clearMissingRequired();

        if (certificateInputRef.current) {
            certificateInputRef.current.value = '';
        }

        setTrainingDialogOpen(true);
    };

    const submitTraining = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        if (!validateRequired(trainingFormDataForTemplate)) {
            return;
        }

        trainingForm.clearErrors();
        trainingForm.transform((data) => ({
            course_id: data.course_id,
            issue_date: data.issue_date,
            expiry_date: data.expiry_date === '' ? null : data.expiry_date,
            institute_center: data.institute_center.trim(),
            country_id: data.country_id === '' ? null : data.country_id,
            certificate: data.certificate,
            remove_certificate: data.remove_certificate,
        }));

        const url = editingTraining
            ? updateTraining.url({
                  employee: resolvedEmployeeId,
                  training: editingTraining.id,
              })
            : storeTraining.url({ employee: resolvedEmployeeId });

        const options = {
            ...TRAINING_RELOAD,
            forceFormData: true,
            onSuccess: () => {
                setTrainingDialogOpen(false);
                trainingForm.reset();
                setEditingTraining(null);
                setRemoveCertificate(false);
                clearMissingRequired();

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

    const showCourseSection =
        showField('course_id') ||
        showField('issue_date') ||
        showField('expiry_date') ||
        showField('institute_center') ||
        showField('country_id') ||
        showField(CERTIFICATE_TEMPLATE_FIELD);

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
                                disabled={!canImportRecords}
                                onClick={() => setTrainingImportOpen(true)}
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={openCreateDialog}
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
                            {showField('course_id') ? (
                                <th className={employeeRecordsTableThClass()}>Course</th>
                            ) : null}
                            {showField('issue_date') ? (
                                <th className={employeeRecordsTableThClass()}>Issue date</th>
                            ) : null}
                            {showField('expiry_date') ? (
                                <th className={employeeRecordsTableThClass()}>Expiry date</th>
                            ) : null}
                            {showField('institute_center') ? (
                                <th className={employeeRecordsTableThClass()}>Institute/Center</th>
                            ) : null}
                            {showField(CERTIFICATE_TEMPLATE_FIELD) ? (
                                <th className={employeeRecordsTableThClass()}>Certificate</th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>Created on</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {trainings.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                {showField('course_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[280px] truncate font-medium text-foreground',
                                        )}
                                        title={row.course_name ?? undefined}
                                    >
                                        {row.course_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('issue_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-xs text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.issue_date)}
                                    </td>
                                ) : null}
                                {showField('expiry_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-xs text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.expiry_date)}
                                    </td>
                                ) : null}
                                {showField('institute_center') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[200px] truncate text-muted-foreground',
                                        )}
                                        title={row.institute_center}
                                    >
                                        {row.institute_center}
                                    </td>
                                ) : null}
                                {showField(CERTIFICATE_TEMPLATE_FIELD) ? (
                                    <td className={employeeRecordsTableTdClass()}>
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
                                                    View
                                                </a>
                                            </Button>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        )}
                                    </td>
                                ) : null}
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-xs text-muted-foreground',
                                    )}
                                >
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <div className="flex items-center justify-end gap-2">
                                            <EmployeeRecordRowActions
                                                onEdit={() => openEditDialog(row)}
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
                        setRemoveCertificate(false);
                        clearMissingRequired();

                        if (certificateInputRef.current) {
                            certificateInputRef.current.value = '';
                        }
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{editingTraining ? 'Edit training' : 'Add training'}</DialogTitle>
                        <DialogDescription className="text-xs text-muted-foreground">
                            Record a course completion, dates, and optional certificate.
                        </DialogDescription>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showCourseSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Course details
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('course_id') ? (
                                    <RecordFormField
                                        field="course_id"
                                        highlightMissing={isMissingRequired('course_id')}
                                        className="sm:col-span-2"
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('course_id'),
                                            )}
                                        >
                                            Course
                                            <RequiredIndicator show={isFieldRequired('course_id')} />
                                        </Label>
                                        <CreatableSelect
                                            value={trainingForm.data.course_id}
                                            onValueChange={(v) =>
                                                trainingForm.setData('course_id', v)
                                            }
                                            variant="dark"
                                            placeholder="— Select a course —"
                                            options={courseSelectOptions}
                                            onOptionsChange={(next) => {
                                                const added = next.find(
                                                    (option) =>
                                                        !courseSelectOptions.some(
                                                            (existing) =>
                                                                existing.value === option.value,
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
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.course_id}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                From master data courses
                                                {isFieldRequired('course_id') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('issue_date') ? (
                                    <RecordFormField
                                        field="issue_date"
                                        highlightMissing={isMissingRequired('issue_date')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('issue_date'),
                                            )}
                                        >
                                            Issue date
                                            <RequiredIndicator show={isFieldRequired('issue_date')} />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={recordFieldInputClass(
                                                isMissingRequired('issue_date'),
                                            )}
                                            value={trainingForm.data.issue_date}
                                            onChange={(e) =>
                                                trainingForm.setData('issue_date', e.target.value)
                                            }
                                        />
                                        {trainingForm.errors.issue_date ? (
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.issue_date}
                                            </p>
                                        ) : null}
                                    </RecordFormField>
                                ) : null}
                                {showField('expiry_date') ? (
                                    <RecordFormField
                                        field="expiry_date"
                                        highlightMissing={isMissingRequired('expiry_date')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('expiry_date'),
                                            )}
                                        >
                                            Expiry date
                                            <RequiredIndicator show={isFieldRequired('expiry_date')} />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={recordFieldInputClass(
                                                isMissingRequired('expiry_date'),
                                            )}
                                            value={trainingForm.data.expiry_date}
                                            onChange={(e) =>
                                                trainingForm.setData('expiry_date', e.target.value)
                                            }
                                        />
                                        {trainingForm.errors.expiry_date ? (
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.expiry_date}
                                            </p>
                                        ) : null}
                                    </RecordFormField>
                                ) : null}
                                {showField('institute_center') ? (
                                    <RecordFormField
                                        field="institute_center"
                                        highlightMissing={isMissingRequired('institute_center')}
                                        className="sm:col-span-2"
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('institute_center'),
                                            )}
                                        >
                                            Institute/Center
                                            <RequiredIndicator
                                                show={isFieldRequired('institute_center')}
                                            />
                                        </Label>
                                        <Input
                                            className={recordFieldInputClass(
                                                isMissingRequired('institute_center'),
                                            )}
                                            placeholder="e.g. BINA SENA MTC"
                                            value={trainingForm.data.institute_center}
                                            onChange={(e) =>
                                                trainingForm.setData(
                                                    'institute_center',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {trainingForm.errors.institute_center ? (
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.institute_center}
                                            </p>
                                        ) : null}
                                    </RecordFormField>
                                ) : null}
                                {showField('country_id') ? (
                                    <RecordFormField
                                        field="country_id"
                                        highlightMissing={isMissingRequired('country_id')}
                                        className="sm:col-span-2"
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('country_id'),
                                            )}
                                        >
                                            Country
                                            <RequiredIndicator show={isFieldRequired('country_id')} />
                                        </Label>
                                        <AppSelect
                                            value={trainingForm.data.country_id}
                                            onValueChange={(v) =>
                                                trainingForm.setData('country_id', v)
                                            }
                                            variant="dark"
                                            placeholder="— Select a country —"
                                        >
                                            <AppSelectItem value="">
                                                — Select a country —
                                            </AppSelectItem>
                                            {countries.map((c) => (
                                                <AppSelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                        {trainingForm.errors.country_id ? (
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.country_id}
                                            </p>
                                        ) : null}
                                    </RecordFormField>
                                ) : null}
                                {showField(CERTIFICATE_TEMPLATE_FIELD) ? (
                                    <RecordFormField
                                        field={CERTIFICATE_TEMPLATE_FIELD}
                                        highlightMissing={isMissingRequired(
                                            CERTIFICATE_TEMPLATE_FIELD,
                                        )}
                                        className="sm:col-span-2"
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired(CERTIFICATE_TEMPLATE_FIELD),
                                            )}
                                        >
                                            Certificate
                                            <RequiredIndicator
                                                show={isFieldRequired(CERTIFICATE_TEMPLATE_FIELD)}
                                            />
                                        </Label>
                                        <Input
                                            ref={certificateInputRef}
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                            disabled={removeCertificate}
                                            className={cn(
                                                recordFieldInputClass(
                                                    isMissingRequired(CERTIFICATE_TEMPLATE_FIELD),
                                                ),
                                                'file:mr-3 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-1 file:text-xs file:text-foreground',
                                                removeCertificate && 'pointer-events-none opacity-50',
                                            )}
                                            onChange={(e) => {
                                                trainingForm.setData(
                                                    'certificate',
                                                    e.target.files?.[0] ?? null,
                                                );
                                                trainingForm.setData('remove_certificate', false);
                                                setRemoveCertificate(false);
                                            }}
                                        />
                                        {trainingForm.data.certificate ? (
                                            <p className="text-[11px] text-muted-foreground">
                                                Selected: {trainingForm.data.certificate.name}
                                            </p>
                                        ) : null}
                                        {editingTraining?.certificate_url && !removeCertificate ? (
                                            <p className="text-[11px] text-muted-foreground">
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
                                        {editingTraining?.certificate_url ? (
                                            <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                                <Checkbox
                                                    checked={removeCertificate}
                                                    onCheckedChange={(checked) => {
                                                        const shouldRemove = checked === true;
                                                        setRemoveCertificate(shouldRemove);
                                                        trainingForm.setData(
                                                            'remove_certificate',
                                                            shouldRemove,
                                                        );

                                                        if (shouldRemove) {
                                                            trainingForm.setData('certificate', null);

                                                            if (certificateInputRef.current) {
                                                                certificateInputRef.current.value = '';
                                                            }
                                                        }
                                                    }}
                                                />
                                                Remove current certificate
                                            </label>
                                        ) : null}
                                        {trainingForm.errors.certificate ? (
                                            <p className="text-xs text-destructive">
                                                {trainingForm.errors.certificate}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                PDF or image, max 5 MB
                                                {isFieldRequired(CERTIFICATE_TEMPLATE_FIELD)
                                                    ? ''
                                                    : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setTrainingDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
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
                    deleteTrainingId && employeeId
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
                importUrl={trainingImportUrls.importUrl}
                templateUrl={trainingImportUrls.templateUrl}
            />
        </TabsContent>
    );
}
