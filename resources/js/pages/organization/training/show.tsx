import { Head, Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DocumentPreviewPanel } from '@/features/organization/documents/shared/document-preview-panel';
import { DocumentVersionHistory } from '@/features/organization/documents/shared/document-version-history';
import type { CountryOption } from '@/features/organization/employees/types';
import type {
    TrainingEmployeeSummary,
    TrainingShowItem,
} from '@/features/organization/training/shared/types';
import { TrainingShowHeaderActions } from '@/features/organization/training/training-list-row-actions';
import { TrainingManagementDialogs } from '@/features/organization/training/training-management-dialogs';
import { formatDisplayDate } from '@/lib/format-date';
import { formatBytes } from '@/lib/utils';
import type {
    CourseOption,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';
import { show as employeeShow } from '@/routes/organization/employees';

type Props = {
    training: TrainingShowItem;
    employee: TrainingEmployeeSummary;
    courses: CourseOption[];
    countries: CountryOption[];
    template_fields: Record<string, TemplateFieldConfig> | null;
    can: {
        view: boolean;
        create: boolean;
        update: boolean;
        delete: boolean;
        import: boolean;
    };
    back: {
        href: string;
        label: string;
    };
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

function MetadataField({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactElement {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                {label}
            </span>
            <span className="max-w-[60%] text-right text-sm font-medium">
                {value}
            </span>
        </div>
    );
}

export default function TrainingShow({
    training,
    employee,
    courses,
    countries,
    template_fields,
    can,
    back,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    const [editTraining, setEditTraining] = useState<TrainingShowItem | null>(
        null,
    );
    const [replaceTraining, setReplaceTraining] =
        useState<TrainingShowItem | null>(null);
    const [deleteTrainingId, setDeleteTrainingId] = useState<number | null>(
        null,
    );

    const pageTitle = training.course_name ?? 'Training record';

    return (
        <>
            <Head title={`${pageTitle} — ${employee.name}`} />

            <Main>
                <DetailsHeader
                    kicker="Training"
                    title={pageTitle}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <Link
                                href={employeeShow.url({
                                    employee: employee.id,
                                })}
                                className="font-medium text-foreground hover:underline"
                            >
                                {employee.name}
                            </Link>
                            <span className="text-muted-foreground">·</span>
                            <span>{employee.employee_no}</span>
                            {training.current_version > 1 ? (
                                <>
                                    <span className="text-muted-foreground">
                                        ·
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px] uppercase"
                                    >
                                        v{training.current_version}
                                    </Badge>
                                </>
                            ) : null}
                        </span>
                    }
                    backHref={back.href}
                    backLabel={back.label}
                    actions={
                        can.update || can.delete ? (
                            <TrainingShowHeaderActions
                                certificateUrl={training.certificate_url}
                                showReplace={
                                    can.update && !!training.certificate_url
                                }
                                onReplace={
                                    can.update
                                        ? () => setReplaceTraining(training)
                                        : undefined
                                }
                                onEdit={
                                    can.update
                                        ? () => setEditTraining(training)
                                        : undefined
                                }
                                onDelete={
                                    can.delete
                                        ? () => setDeleteTrainingId(training.id)
                                        : undefined
                                }
                            />
                        ) : training.certificate_url ? (
                            <TrainingShowHeaderActions
                                certificateUrl={training.certificate_url}
                            />
                        ) : null
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="min-w-0 space-y-6">
                        {training.certificate_url ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Certificate preview
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <DocumentPreviewPanel
                                        document={{
                                            title: pageTitle,
                                            file_url: training.certificate_url,
                                            mime_type:
                                                training.certificate_mime_type,
                                            can_preview: training.can_preview,
                                        }}
                                        className="h-[min(70vh,820px)] min-h-[420px]"
                                    />
                                </CardContent>
                            </Card>
                        ) : null}

                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <History className="h-4 w-4 text-muted-foreground" />
                                    <CardTitle className="text-base">
                                        Version history
                                    </CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <DocumentVersionHistory
                                    versions={training.versions}
                                    emptyMessage="No previous certificate versions."
                                />
                            </CardContent>
                        </Card>
                    </div>

                    <Card className="h-fit border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <MetadataField
                                label="Course"
                                value={training.course_name ?? '—'}
                            />
                            <MetadataField
                                label="Issue date"
                                value={
                                    training.issue_date
                                        ? formatDisplayDate(training.issue_date)
                                        : '—'
                                }
                            />
                            <MetadataField
                                label="Expiry date"
                                value={
                                    training.expiry_date
                                        ? formatDisplayDate(
                                              training.expiry_date,
                                          )
                                        : '—'
                                }
                            />
                            <MetadataField
                                label="Institute / center"
                                value={training.institute_center?.trim() || '—'}
                            />
                            <MetadataField
                                label="Country"
                                value={training.country_name ?? '—'}
                            />
                            <MetadataField
                                label="Certificate file"
                                value={
                                    training.certificate_original_filename ??
                                    (training.certificate_url ? 'Uploaded' : '—')
                                }
                            />
                            <MetadataField
                                label="File size"
                                value={formatBytes(
                                    training.certificate_size_bytes,
                                )}
                            />
                            <MetadataField
                                label="Created"
                                value={
                                    training.created_at
                                        ? formatDisplayDate(training.created_at)
                                        : '—'
                                }
                            />
                            {training.replaced_at ? (
                                <MetadataField
                                    label="Last replaced"
                                    value={formatDisplayDate(
                                        training.replaced_at,
                                    )}
                                />
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Latest changes for this training record."
                    />
                ) : null}
            </Main>

            <TrainingManagementDialogs
                employeeId={employee.id}
                courses={courses}
                countries={countries}
                editTraining={editTraining}
                onEditTrainingChange={(training) =>
                    setEditTraining(training as TrainingShowItem | null)
                }
                replaceTraining={replaceTraining}
                onReplaceTrainingChange={(training) =>
                    setReplaceTraining(training as TrainingShowItem | null)
                }
                deleteTrainingId={deleteTrainingId}
                onDeleteTrainingIdChange={setDeleteTrainingId}
                templateFields={template_fields}
                partialReloadKeys={['training']}
                deleteRedirectUrl={back.href}
            />
        </>
    );
}
