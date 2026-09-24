import { Head, Link } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RankOption } from '@/features/organization/employees/types';
import { SeaServiceManagementDialogs } from '@/features/organization/sea-services/sea-service-management-dialogs';
import type {
    SeaServiceBackNavigation,
    SeaServiceListItem,
    SeaServicePageCan,
} from '@/features/organization/sea-services/types';
import { formatDisplayDate } from '@/lib/format-date';
import type {
    ClientOption,
    TemplateFieldConfig,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';
import { show as employeeShow } from '@/routes/organization/employees';

type Props = {
    sea_service: SeaServiceListItem;
    employee: { id: number; name: string; employee_no: string };
    vessel_types: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    template_fields: Record<string, TemplateFieldConfig> | null;
    can: SeaServicePageCan;
    back: SeaServiceBackNavigation;
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

export default function SeaServiceShow({
    sea_service,
    employee,
    vessel_types,
    vessels,
    ranks,
    clients,
    can,
    back,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    const [editSeaService, setEditSeaService] =
        useState<SeaServiceListItem | null>(null);
    const [deleteSeaServiceId, setDeleteSeaServiceId] = useState<number | null>(
        null,
    );

    const pageTitle = sea_service.vessel_name ?? 'Sea service record';

    return (
        <>
            <Head title={`${pageTitle} — ${employee.name}`} />

            <Main>
                <DetailsHeader
                    kicker="Sea Service"
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
                            {sea_service.has_assignment_phase ? (
                                <>
                                    <span className="text-muted-foreground">
                                        ·
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="border-violet-500/30 bg-violet-500/10 text-[10px] text-violet-400 uppercase"
                                    >
                                        Managed by Crew Operations
                                    </Badge>
                                </>
                            ) : null}
                        </span>
                    }
                    backHref={back.href}
                    backLabel={back.label}
                    actions={
                        sea_service.has_assignment_phase ? (
                            <span
                                className="inline-flex items-center rounded-lg border border-border/60 bg-muted/60 px-3 py-1.5 text-xs text-muted-foreground"
                                title="This Sea Service record is synchronized from Crew Operations. Use Crew Movement Correction to change vessel, rank, or service dates."
                            >
                                Managed by Crew Operations
                            </span>
                        ) : can.update || can.delete ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {can.update ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="rounded-xl"
                                        onClick={() =>
                                            setEditSeaService(sea_service)
                                        }
                                    >
                                        <Pencil className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                ) : null}
                                {can.delete ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="rounded-xl text-red-400/80 hover:bg-red-500/10 hover:text-red-400"
                                        onClick={() =>
                                            setDeleteSeaServiceId(
                                                sea_service.id,
                                            )
                                        }
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        Delete
                                    </Button>
                                ) : null}
                            </div>
                        ) : null
                    }
                />

                {sea_service.has_assignment_phase ? (
                    <div className="mt-4 rounded-xl border border-border/80 bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                        This Sea Service record is synchronized from Crew
                        Operations. Use Crew Movement Correction to change
                        vessel, rank, or service dates.
                    </div>
                ) : null}

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <Card className="border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">
                                Service details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <MetadataField
                                label="Vessel"
                                value={sea_service.vessel_name ?? '—'}
                            />
                            <MetadataField
                                label="Vessel type"
                                value={sea_service.vessel_type_name ?? '—'}
                            />
                            <MetadataField
                                label="Rank"
                                value={sea_service.rank_name ?? '—'}
                            />
                            <MetadataField
                                label="Client"
                                value={sea_service.client_name ?? '—'}
                            />
                            <MetadataField
                                label="Start date"
                                value={formatDisplayDate(
                                    sea_service.start_date,
                                )}
                            />
                            <MetadataField
                                label="End date"
                                value={
                                    sea_service.end_date
                                        ? formatDisplayDate(
                                              sea_service.end_date,
                                          )
                                        : 'Ongoing'
                                }
                            />
                            <MetadataField
                                label="Duration"
                                value={
                                    sea_service.end_date
                                        ? `${sea_service.total_months} months · ${sea_service.total_days} days`
                                        : 'Ongoing'
                                }
                            />
                            <MetadataField
                                label="Linked deployment"
                                value={
                                    sea_service.has_assignment_phase
                                        ? 'Yes'
                                        : 'No'
                                }
                            />
                        </CardContent>
                    </Card>

                    <Card className="h-fit border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">
                                Assignment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <MetadataField
                                label="Department"
                                value={sea_service.department_name ?? '—'}
                            />
                            <MetadataField
                                label="Position"
                                value={sea_service.position_title ?? '—'}
                            />
                            <MetadataField
                                label="Employee no"
                                value={employee.employee_no || '—'}
                            />
                        </CardContent>
                    </Card>
                </div>

                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Latest changes for this sea service record."
                    />
                ) : null}
            </Main>

            <SeaServiceManagementDialogs
                employeeId={employee.id}
                vesselTypes={vessel_types}
                vessels={vessels}
                ranks={ranks}
                clients={clients}
                editSeaService={editSeaService}
                onEditSeaServiceChange={(row) =>
                    setEditSeaService(row as SeaServiceListItem | null)
                }
                deleteSeaServiceId={deleteSeaServiceId}
                onDeleteSeaServiceIdChange={setDeleteSeaServiceId}
                partialReloadKeys={['sea_service']}
                deleteRedirectUrl={back.href}
            />
        </>
    );
}
