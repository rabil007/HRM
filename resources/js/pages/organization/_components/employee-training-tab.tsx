import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import { destroy as destroyTraining } from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { EmployeeRecordImportDialog } from '@/features/organization/employees/profile/components/employee-record-import-dialog';
import { trainingImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { CountryOption } from '@/features/organization/employees/types';
import { AddTrainingDialog } from '@/features/organization/training/add-training/add-training-dialog';
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
import { useTemplateRecordFields } from '@/pages/organization/_hooks/use-template-record-fields';
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
    employeeName: string;
    ensureEmployee?: () => Promise<number>;
    trainings: TrainingItem[];
    courses: CourseOption[];
    countries: CountryOption[];
    canManage: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeTrainingTab({
    employeeId,
    employeeName,
    ensureEmployee,
    trainings,
    courses,
    countries,
    canManage,
    templateFields = null,
}: EmployeeTrainingTabProps): ReactElement {
    const { showField } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
    });

    const [trainingDialogOpen, setTrainingDialogOpen] = useState(false);
    const [trainingImportOpen, setTrainingImportOpen] = useState(false);
    const [editingTraining, setEditingTraining] = useState<TrainingItem | null>(null);
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(null);

    const trainingImport = trainingImportConfig(employeeId);
    const trainingImportUrls = useMemo(
        () => resolveRecordImportUrls(trainingImportConfig(employeeId), employeeId),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;

    const openCreateDialog = () => {
        setEditingTraining(null);
        setTrainingDialogOpen(true);
    };

    const openEditDialog = (row: TrainingItem) => {
        setEditingTraining(row);
        setTrainingDialogOpen(true);
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

            <AddTrainingDialog
                open={trainingDialogOpen}
                onOpenChange={(openDialog) => {
                    setTrainingDialogOpen(openDialog);

                    if (!openDialog) {
                        setEditingTraining(null);
                    }
                }}
                employeeId={employeeId}
                employeeName={employeeName}
                ensureEmployee={ensureEmployee}
                courses={courses}
                countries={countries}
                templateFields={templateFields}
                editingTraining={editingTraining}
            />

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
