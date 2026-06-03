import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import {
    destroy as destroyWorkExperience,
    store as storeWorkExperience,
    update as updateWorkExperience,
} from '@/actions/App/Http/Controllers/Organization/EmployeeWorkExperienceController';
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
import { workExperienceImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
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
import { omitHiddenTemplateRecordFields } from '@/pages/organization/_lib/template-field-visibility';
import type {
    TemplateFieldConfig,
    WorkExperienceItem,
} from '@/pages/organization/employee-page.types';

const WORK_EXPERIENCE_RELOAD = {
    preserveScroll: true,
    only: ['work_experiences'],
} as const;

export type EmployeeWorkExperienceTabProps = {
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    work_experiences: WorkExperienceItem[];
    canManage: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeWorkExperienceTab({
    employeeId,
    ensureEmployee,
    work_experiences,
    canManage,
    templateFields = null,
}: EmployeeWorkExperienceTabProps): ReactElement {
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
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_work_experiences,
    });

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

    useClearMissingOnFormChange(
        workExperienceForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    const workExperienceImport = workExperienceImportConfig(employeeId);
    const workExperienceImportUrls = useMemo(
        () =>
            resolveRecordImportUrls(
                workExperienceImportConfig(employeeId),
                employeeId,
            ),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;

    const openCreateDialog = () => {
        workExperienceForm.reset();
        workExperienceForm.clearErrors();
        clearMissingRequired();
        setEditingWorkExperience(null);
        setWorkExperienceDialogOpen(true);
    };

    const openEditDialog = (row: WorkExperienceItem) => {
        setEditingWorkExperience(row);
        workExperienceForm.setData({
            company_name: row.company_name,
            job_title: row.job_title,
            date_from: row.date_from ?? '',
            date_to: row.date_to ?? '',
            responsibility: row.responsibility ?? '',
        });
        workExperienceForm.clearErrors();
        clearMissingRequired();
        setWorkExperienceDialogOpen(true);
    };

    const submitWorkExperience = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        if (!validateRequired(workExperienceForm.data as Record<string, unknown>)) {
            return;
        }

        workExperienceForm.clearErrors();
        workExperienceForm.transform((data) =>
            omitHiddenTemplateRecordFields(
                {
                    company_name: data.company_name.trim(),
                    job_title: data.job_title.trim(),
                    date_from: data.date_from,
                    date_to: data.date_to === '' ? null : data.date_to,
                    responsibility:
                        data.responsibility.trim() === ''
                            ? null
                            : data.responsibility.trim(),
                },
                templateFields,
            ),
        );

        const url = editingWorkExperience
            ? updateWorkExperience.url({
                  employee: resolvedEmployeeId,
                  workExperience: editingWorkExperience.id,
              })
            : storeWorkExperience.url({
                  employee: resolvedEmployeeId,
              });

        const options = {
            ...WORK_EXPERIENCE_RELOAD,
            onSuccess: () => {
                setWorkExperienceDialogOpen(false);
                workExperienceForm.reset();
                setEditingWorkExperience(null);
                clearMissingRequired();
            },
        };

        if (editingWorkExperience) {
            workExperienceForm.put(url, options);
        } else {
            workExperienceForm.post(url, options);
        }
    };

    const showRoleSection =
        showField('company_name') ||
        showField('job_title') ||
        showField('date_from') ||
        showField('date_to');
    const showResponsibilitySection = showField('responsibility');

    return (
        <TabsContent value="work_experience" className="mt-6">
            <EmployeeRecordsPanel
                title="Work experience"
                count={work_experiences.length}
                isEmpty={work_experiences.length === 0}
                emptyMessage="No work history recorded."
                actions={
                    canManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                disabled={!canImportRecords}
                                onClick={() => setWorkExperienceImportOpen(true)}
                            >
                                Import CSV
                            </Button>
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                type="button"
                                onClick={openCreateDialog}
                            >
                                + Add line
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[800px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('company_name') ? (
                                <th className={employeeRecordsTableThClass()}>Company</th>
                            ) : null}
                            {showField('job_title') ? (
                                <th className={employeeRecordsTableThClass()}>Job title</th>
                            ) : null}
                            {showField('date_from') ? (
                                <th className={employeeRecordsTableThClass()}>From</th>
                            ) : null}
                            {showField('date_to') ? (
                                <th className={employeeRecordsTableThClass()}>To</th>
                            ) : null}
                            {showField('responsibility') ? (
                                <th className={employeeRecordsTableThClass()}>Responsibility</th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>Added</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {work_experiences.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                {showField('company_name') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[200px] truncate font-medium text-foreground',
                                        )}
                                        title={row.company_name}
                                    >
                                        {row.company_name}
                                    </td>
                                ) : null}
                                {showField('job_title') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[160px] truncate text-muted-foreground',
                                        )}
                                        title={row.job_title}
                                    >
                                        {row.job_title}
                                    </td>
                                ) : null}
                                {showField('date_from') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-xs text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.date_from)}
                                    </td>
                                ) : null}
                                {showField('date_to') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'whitespace-nowrap text-xs text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.date_to)}
                                    </td>
                                ) : null}
                                {showField('responsibility') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[220px] truncate text-xs text-muted-foreground',
                                        )}
                                        title={row.responsibility ?? ''}
                                    >
                                        {row.responsibility?.trim() ? row.responsibility : '—'}
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
                                        <EmployeeRecordRowActions
                                            onEdit={() => openEditDialog(row)}
                                            onDelete={() => setDeleteWorkExperienceId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
            <Dialog
                open={workExperienceDialogOpen}
                onOpenChange={(openDialog) => {
                    setWorkExperienceDialogOpen(openDialog);

                    if (!openDialog) {
                        workExperienceForm.reset();
                        workExperienceForm.clearErrors();
                        setEditingWorkExperience(null);
                        clearMissingRequired();
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingWorkExperience
                                ? 'Edit work experience'
                                : 'Add work experience'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Add details about the employee's previous employment.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showRoleSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Role details
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('company_name') ? (
                                    <RecordFormField
                                        field="company_name"
                                        highlightMissing={isMissingRequired('company_name')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('company_name'),
                                            )}
                                        >
                                            Company name
                                            <RequiredIndicator
                                                show={isFieldRequired('company_name')}
                                            />
                                        </Label>
                                        <Input
                                            className={recordFieldInputClass(
                                                isMissingRequired('company_name'),
                                            )}
                                            placeholder="e.g. Ocean Maritime LLC"
                                            value={workExperienceForm.data.company_name}
                                            onChange={(e) =>
                                                workExperienceForm.setData(
                                                    'company_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {workExperienceForm.errors.company_name ? (
                                            <p className="text-xs text-destructive">
                                                {workExperienceForm.errors.company_name}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                The employer's name
                                                {isFieldRequired('company_name') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('job_title') ? (
                                    <RecordFormField
                                        field="job_title"
                                        highlightMissing={isMissingRequired('job_title')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('job_title'),
                                            )}
                                        >
                                            Job title
                                            <RequiredIndicator show={isFieldRequired('job_title')} />
                                        </Label>
                                        <Input
                                            className={recordFieldInputClass(
                                                isMissingRequired('job_title'),
                                            )}
                                            placeholder="e.g. Chief Engineer"
                                            value={workExperienceForm.data.job_title}
                                            onChange={(e) =>
                                                workExperienceForm.setData('job_title', e.target.value)
                                            }
                                        />
                                        {workExperienceForm.errors.job_title ? (
                                            <p className="text-xs text-destructive">
                                                {workExperienceForm.errors.job_title}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                The held position or rank
                                                {isFieldRequired('job_title') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('date_from') ? (
                                    <RecordFormField
                                        field="date_from"
                                        highlightMissing={isMissingRequired('date_from')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('date_from'),
                                            )}
                                        >
                                            Start date
                                            <RequiredIndicator show={isFieldRequired('date_from')} />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={recordFieldInputClass(
                                                isMissingRequired('date_from'),
                                            )}
                                            value={workExperienceForm.data.date_from}
                                            onChange={(e) =>
                                                workExperienceForm.setData('date_from', e.target.value)
                                            }
                                        />
                                        {workExperienceForm.errors.date_from ? (
                                            <p className="text-xs text-destructive">
                                                {workExperienceForm.errors.date_from}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                When the employment started
                                                {isFieldRequired('date_from') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('date_to') ? (
                                    <RecordFormField
                                        field="date_to"
                                        highlightMissing={isMissingRequired('date_to')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('date_to'),
                                            )}
                                        >
                                            End date
                                            <RequiredIndicator show={isFieldRequired('date_to')} />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={recordFieldInputClass(
                                                isMissingRequired('date_to'),
                                            )}
                                            value={workExperienceForm.data.date_to}
                                            onChange={(e) =>
                                                workExperienceForm.setData('date_to', e.target.value)
                                            }
                                        />
                                        {workExperienceForm.errors.date_to ? (
                                            <p className="text-xs text-destructive">
                                                {workExperienceForm.errors.date_to}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                Leave empty if currently employed
                                                {isFieldRequired('date_to') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    {showResponsibilitySection ? (
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Responsibilities
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <RecordFormField
                                field="responsibility"
                                highlightMissing={isMissingRequired('responsibility')}
                            >
                                <Label
                                    className={recordFieldLabelClass(
                                        isMissingRequired('responsibility'),
                                    )}
                                >
                                    Description
                                    <RequiredIndicator show={isFieldRequired('responsibility')} />
                                </Label>
                                <textarea
                                    rows={4}
                                    placeholder="Describe the main tasks, responsibilities, and achievements..."
                                    className={cn(
                                        'min-h-[88px] w-full resize-y rounded-xl border border-border bg-muted/50 px-3 py-2 text-sm text-foreground outline-none focus:ring-1 focus:ring-primary',
                                        isMissingRequired('responsibility') && 'border-rose-500/50',
                                    )}
                                    value={workExperienceForm.data.responsibility}
                                    onChange={(e) =>
                                        workExperienceForm.setData(
                                            'responsibility',
                                            e.target.value,
                                        )
                                    }
                                />
                                {workExperienceForm.errors.responsibility ? (
                                    <p className="text-xs text-destructive">
                                        {workExperienceForm.errors.responsibility}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        Description of the role
                                        {isFieldRequired('responsibility') ? '' : ' (optional)'}
                                    </p>
                                )}
                            </RecordFormField>
                        </div>
                    ) : null}
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setWorkExperienceDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={workExperienceForm.processing}
                            onClick={submitWorkExperience}
                        >
                            {workExperienceForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteWorkExperienceId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteWorkExperienceId(null);
                    }
                }}
                title="Remove work experience?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteWorkExperienceId && employeeId
                        ? destroyWorkExperience.url({
                              employee: employeeId,
                              workExperience: deleteWorkExperienceId,
                          })
                        : null
                }
                reloadOptions={WORK_EXPERIENCE_RELOAD}
            />

            <EmployeeRecordImportDialog
                open={workExperienceImportOpen}
                onOpenChange={setWorkExperienceImportOpen}
                inputId={workExperienceImport.inputId}
                title={workExperienceImport.title}
                description={workExperienceImport.description}
                templateHint={workExperienceImport.templateHint}
                columnHelp={workExperienceImport.columnHelp}
                reloadOnly={workExperienceImport.reloadOnly}
                importUrl={workExperienceImportUrls.importUrl}
                templateUrl={workExperienceImportUrls.templateUrl}
            />
        </TabsContent>
    );
}
