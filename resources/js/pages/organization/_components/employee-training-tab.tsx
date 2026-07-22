import { router } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { bulkDestroy as bulkDestroyTrainings } from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TabsContent } from '@/components/ui/tabs';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { EmployeeRecordImportDialog } from '@/features/organization/employees/profile/components/employee-record-import-dialog';
import { trainingImportConfig } from '@/features/organization/employees/profile/record-import-configs';
import { resolveRecordImportUrls } from '@/features/organization/employees/profile/resolve-record-import-urls';
import type { CountryOption } from '@/features/organization/employees/types';
import { AddTrainingDialog } from '@/features/organization/training/add-training/add-training-dialog';
import { buildTrainingShowUrl } from '@/features/organization/training/shared/training-show-url';
import { TrainingListRowActions } from '@/features/organization/training/training-list-row-actions';
import { TrainingManagementDialogs } from '@/features/organization/training/training-management-dialogs';
import type { TrainingShowBackContext } from '@/features/organization/training/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsActionsTdClass,
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
};

export type EmployeeTrainingTabProps = {
    employeeId: number | null;
    employeeName: string;
    ensureEmployee?: () => Promise<number>;
    trainings: TrainingItem[];
    courses: CourseOption[];
    countries: CountryOption[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canImport: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
    standalone?: boolean;
    showBackFrom?: TrainingShowBackContext['from'];
};

function EmployeeTrainingTabShell({
    standalone,
    children,
}: {
    standalone?: boolean;
    children: ReactNode;
}): ReactElement {
    if (standalone) {
        return <div className="mt-6">{children}</div>;
    }

    return (
        <TabsContent value="training" className="mt-6">
            {children}
        </TabsContent>
    );
}

export function EmployeeTrainingTab({
    employeeId,
    employeeName,
    ensureEmployee,
    trainings,
    courses,
    countries,
    canCreate,
    canUpdate,
    canDelete,
    canImport,
    templateFields = null,
    standalone = false,
    showBackFrom = 'profile',
}: EmployeeTrainingTabProps): ReactElement {
    const showBack: TrainingShowBackContext =
        showBackFrom === 'employee-browse'
            ? { from: 'employee-browse' }
            : showBackFrom === 'index'
              ? { from: 'index' }
              : { from: 'profile' };
    const { showField } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields:
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
    });

    const [trainingDialogOpen, setTrainingDialogOpen] = useState(false);
    const [trainingImportOpen, setTrainingImportOpen] = useState(false);
    const [editTraining, setEditTraining] = useState<TrainingItem | null>(null);
    const [replaceTraining, setReplaceTraining] = useState<TrainingItem | null>(
        null,
    );
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(
        null,
    );
    const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);

    const trainingIds = useMemo(
        () => trainings.map((row) => row.id),
        [trainings],
    );

    const {
        selectedIds: selectedTrainingIds,
        selectedCount: selectedTrainingCount,
        isSelected: isTrainingSelected,
        isAllSelected: allTrainingsSelected,
        isPartiallySelected: trainingsPartiallySelected,
        toggle: toggleTraining,
        toggleAll: toggleAllTrainings,
        clear: clearTrainingSelection,
    } = useBulkSelection(trainingIds);

    const trainingImport = trainingImportConfig(employeeId);
    const trainingImportUrls = useMemo(
        () =>
            resolveRecordImportUrls(
                trainingImportConfig(employeeId),
                employeeId,
            ),
        [employeeId],
    );
    const canImportRecords = employeeId !== null && employeeId > 0;
    const hasEmployeeId = employeeId !== null && employeeId > 0;

    const openCreateDialog = () => {
        setTrainingDialogOpen(true);
    };

    const openEditDialog = (row: TrainingItem) => {
        setEditTraining(row);
    };

    const openReplaceDialog = (row: TrainingItem) => {
        setReplaceTraining(row);
    };

    return (
        <EmployeeTrainingTabShell standalone={standalone}>
            {canDelete && trainings.length > 0 ? (
                <DocumentsBulkToolbar
                    count={selectedTrainingCount}
                    itemLabel="records"
                    onClear={clearTrainingSelection}
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
                title="Training"
                count={trainings.length}
                isEmpty={trainings.length === 0}
                emptyMessage="No training records."
                actions={
                    canCreate || canImport ? (
                        <div className="flex flex-wrap items-center gap-2">
                            {canImport ? (
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
                            ) : null}
                            {canCreate ? (
                                <Button
                                    size="sm"
                                    className="h-8 gap-1.5 text-xs"
                                    type="button"
                                    onClick={openCreateDialog}
                                >
                                    + Add training
                                </Button>
                            ) : null}
                        </div>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[960px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {canDelete ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'w-10 px-3',
                                    )}
                                >
                                    <Checkbox
                                        checked={
                                            allTrainingsSelected
                                                ? true
                                                : trainingsPartiallySelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={toggleAllTrainings}
                                        aria-label="Select all training records"
                                    />
                                </th>
                            ) : null}
                            {showField('course_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Course
                                </th>
                            ) : null}
                            {showField('issue_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Issue date
                                </th>
                            ) : null}
                            {showField('expiry_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Expiry date
                                </th>
                            ) : null}
                            {showField('institute_center') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Institute/Center
                                </th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>
                                Created on
                            </th>
                            {hasEmployeeId ? (
                                <EmployeeRecordsActionsHeader className="min-w-[13.5rem]" />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {trainings.map((row) => (
                            <tr
                                key={row.id}
                                className={cn(
                                    employeeRecordsTableRowClass(),
                                    hasEmployeeId && 'cursor-pointer',
                                )}
                                onClick={(event) => {
                                    const target = event.target;

                                    if (
                                        !(target instanceof Element) ||
                                        target.closest(
                                            '[data-slot="checkbox"], [role="checkbox"], button, a, [data-row-ignore-click]',
                                        )
                                    ) {
                                        return;
                                    }

                                    if (!hasEmployeeId) {
                                        return;
                                    }

                                    router.visit(
                                        buildTrainingShowUrl(
                                            employeeId,
                                            row.id,
                                            showBack,
                                        ),
                                    );
                                }}
                            >
                                {canDelete ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'w-10 px-3',
                                        )}
                                        data-row-ignore-click
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        onPointerDown={(event) =>
                                            event.stopPropagation()
                                        }
                                    >
                                        <Checkbox
                                            checked={isTrainingSelected(row.id)}
                                            onCheckedChange={() =>
                                                toggleTraining(row.id)
                                            }
                                            aria-label={`Select training record ${row.course_name ?? row.id}`}
                                        />
                                    </td>
                                ) : null}
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
                                            'text-xs whitespace-nowrap text-muted-foreground',
                                        )}
                                    >
                                        {formatIsoDateDisplay(row.issue_date)}
                                    </td>
                                ) : null}
                                {showField('expiry_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-xs whitespace-nowrap text-muted-foreground',
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
                                        title={
                                            row.institute_center ?? undefined
                                        }
                                    >
                                        {row.institute_center}
                                    </td>
                                ) : null}
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-xs whitespace-nowrap text-muted-foreground',
                                    )}
                                >
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {hasEmployeeId ? (
                                    <td
                                        className={employeeRecordsActionsTdClass(
                                            'min-w-[13.5rem]',
                                        )}
                                    >
                                        <TrainingListRowActions
                                            viewHref={buildTrainingShowUrl(
                                                employeeId,
                                                row.id,
                                                showBack,
                                            )}
                                            certificateUrl={row.certificate_url}
                                            showEdit={canUpdate}
                                            onEdit={() => openEditDialog(row)}
                                            showReplace={
                                                canUpdate &&
                                                !!row.certificate_url
                                            }
                                            onReplace={() =>
                                                openReplaceDialog(row)
                                            }
                                            showDelete={canDelete}
                                            onDelete={() =>
                                                setDeleteTrainingId(row.id)
                                            }
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <AddTrainingDialog
                open={trainingDialogOpen}
                onOpenChange={setTrainingDialogOpen}
                employeeId={employeeId}
                employeeName={employeeName}
                ensureEmployee={ensureEmployee}
                courses={courses}
                countries={countries}
                templateFields={templateFields}
            />

            {employeeId !== null && employeeId > 0 ? (
                <TrainingManagementDialogs
                    employeeId={employeeId}
                    courses={courses}
                    countries={countries}
                    editTraining={editTraining}
                    onEditTrainingChange={setEditTraining}
                    replaceTraining={replaceTraining}
                    onReplaceTrainingChange={setReplaceTraining}
                    deleteTrainingId={deleteTrainingId}
                    onDeleteTrainingIdChange={setDeleteTrainingId}
                    templateFields={templateFields}
                    partialReloadKeys={TRAINING_RELOAD.only}
                />
            ) : null}

            <ConfirmDeleteDialog
                open={bulkDeleteOpen}
                onOpenChange={setBulkDeleteOpen}
                title="Remove selected training records?"
                description={`${selectedTrainingCount} selected ${selectedTrainingCount === 1 ? 'record' : 'records'} will be permanently removed.`}
                confirmText={isBulkDeleting ? 'Removing…' : 'Remove'}
                onConfirm={() => {
                    if (
                        selectedTrainingIds.length === 0 ||
                        employeeId === null
                    ) {
                        return;
                    }

                    setIsBulkDeleting(true);

                    router.delete(
                        bulkDestroyTrainings.url({ employee: employeeId }),
                        {
                            data: { training_ids: selectedTrainingIds },
                            ...TRAINING_RELOAD,
                            onSuccess: () => {
                                clearTrainingSelection();
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
        </EmployeeTrainingTabShell>
    );
}
