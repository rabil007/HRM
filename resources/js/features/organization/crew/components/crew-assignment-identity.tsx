import { ArrowUpRight, User } from 'lucide-react';
import type { ReactElement } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Badge } from '@/components/ui/badge';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { formatDaysInPhase } from '@/features/organization/crew/format-days-in-phase';
import type {
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { index as crewAssignmentsIndex } from '@/routes/organization/crew-assignments';

export function CrewAssignmentIdentity({
    assignment,
    can,
}: {
    assignment: CrewAssignmentDetail;
    can: CrewAssignmentPagePermissions;
}): ReactElement {
    const employee = assignment.employee;
    const canViewEmployee = can.view_employee;

    const avatarNode = employee ? (
        canViewEmployee ? (
            <EmployeeProfileLink
                employeeId={employee.id}
                aria-label={`View ${employee.name}'s profile`}
                className="shrink-0 rounded-lg focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none md:rounded-xl"
            >
                <EmployeeAvatar
                    name={employee.name}
                    image={employee.image}
                    size="md"
                    className="size-11 rounded-lg text-base font-bold shadow-sm ring-1 ring-border/50 transition-transform hover:scale-[1.02] md:size-14 md:rounded-xl md:text-lg"
                />
            </EmployeeProfileLink>
        ) : (
            <div className="shrink-0">
                <EmployeeAvatar
                    name={employee.name}
                    image={employee.image}
                    size="md"
                    className="size-11 rounded-lg text-base font-bold shadow-sm ring-1 ring-border/50 md:size-14 md:rounded-xl md:text-lg"
                />
            </div>
        )
    ) : (
        <div
            className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground ring-1 ring-border/50 md:size-14 md:rounded-xl"
            aria-hidden="true"
        >
            <User className="size-5 md:size-7" />
        </div>
    );

    const titleNode = employee ? (
        canViewEmployee ? (
            <EmployeeProfileLink
                employeeId={employee.id}
                aria-label={`View ${employee.name}'s profile`}
                className="group inline-flex items-center gap-1.5 rounded-sm font-bold text-foreground transition-colors hover:text-foreground/85 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
            >
                <span className="tracking-tight underline-offset-4 group-hover:underline">
                    {employee.name}
                </span>
                <ArrowUpRight
                    className="size-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-foreground md:size-5"
                    aria-hidden="true"
                />
            </EmployeeProfileLink>
        ) : (
            <span className="font-bold tracking-tight text-foreground">
                {employee.name}
            </span>
        )
    ) : (
        <span className="text-foreground">{assignment.assignment_no}</span>
    );

    const descriptionNode = (
        <div className="space-y-0.5">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[11px] text-muted-foreground md:text-xs">
                {employee?.employee_no ? (
                    <span>#{employee.employee_no}</span>
                ) : null}
                {employee?.employee_no ? <span>·</span> : null}
                <span className="font-semibold text-foreground/80">
                    {assignment.assignment_no}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs font-medium text-muted-foreground/90">
                <span>
                    {assignment.position?.name ?? 'Position Unassigned'}
                </span>
                <span>·</span>
                <span>{assignment.vessel?.name ?? 'Vessel Unassigned'}</span>
                {assignment.client?.name ? (
                    <>
                        <span>·</span>
                        <span className="text-muted-foreground/70">
                            {assignment.client.name}
                        </span>
                    </>
                ) : null}
            </div>
        </div>
    );

    const badgesNode = (
        <>
            <Badge
                variant={
                    assignment.status === 'active'
                        ? 'success'
                        : assignment.status === 'draft'
                          ? 'secondary'
                          : assignment.status === 'cancelled'
                            ? 'destructive'
                            : 'outline'
                }
            >
                {assignment.status_label}
            </Badge>
            {assignment.current_phase ? (
                <CrewPhaseBadge
                    code={assignment.current_phase.code}
                    label={assignment.current_phase.label}
                    status={assignment.current_phase.status}
                />
            ) : null}
            {assignment.days_in_phase !== null ? (
                <span className="text-xs font-medium text-muted-foreground">
                    {formatDaysInPhase(assignment.days_in_phase)}
                </span>
            ) : null}
        </>
    );

    return (
        <DetailsHeader
            kicker="Crew Assignments"
            className="md:items-start"
            avatar={avatarNode}
            title={titleNode}
            titleClassName="text-xl font-bold tracking-tight text-foreground bg-none md:text-2xl"
            description={descriptionNode}
            badges={badgesNode}
            backHref={crewAssignmentsIndex.url()}
            backLabel="Back to Crew Assignments"
        />
    );
}
