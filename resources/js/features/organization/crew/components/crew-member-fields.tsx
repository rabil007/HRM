import type { ReactElement } from 'react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    CrewEmployeeOperationalStatus,
    getEmployeeStatusContainerClass,
} from '@/features/organization/crew/components/crew-employee-operational-status';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { crewPhaseDescription } from '@/features/organization/crew/lib/crew-phase-descriptions';
import type {
    ActiveOnVesselAssignment,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentFormOptions,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

export type CrewMemberFieldsData = {
    employee_id: number | null;
    rank_id: number | null;
    planned_arrival_at?: string | null;
};

export const ARRIVAL_DATE_HELPER =
    'Expected date the crew member will arrive at the joining location. Actual arrival is recorded later through Record Arrival.';

type CrewMemberFieldsProps = {
    data: CrewMemberFieldsData;
    onChange: (data: CrewMemberFieldsData) => void;
    formOptions: CrewAssignmentFormOptions | CrewAssignmentCreateFormOptions;
    errors: Record<string, string | undefined>;
    lockEmployee?: boolean;
    employeeLabel?: string | null;
    employeeStatus?: EmployeeOperationalStatus | null;
    activeOnVessel?: ActiveOnVesselAssignment | null;
    showOperationalStatus?: boolean;
    currentPhase?: {
        code: string;
        label: string;
        status?: string;
        started_at?: string | null;
    } | null;
    selectedEmployeeIds?: Set<number>;
    employeeErrorKey?: string;
    rankErrorKey?: string;
    arrivalErrorKey?: string;
};

function lookupByEmployeeId<T>(
    map: Record<string, T> | undefined,
    employeeId: number | null,
): T | null {
    if (employeeId == null || map == null) {
        return null;
    }

    return map[String(employeeId)] ?? null;
}

export function CrewMemberFields({
    data,
    onChange,
    formOptions,
    errors,
    lockEmployee = false,
    employeeLabel,
    employeeStatus,
    activeOnVessel,
    showOperationalStatus = false,
    currentPhase = null,
    selectedEmployeeIds,
    employeeErrorKey = 'employee_id',
    rankErrorKey = 'rank_id',
    arrivalErrorKey = 'planned_arrival_at',
}: CrewMemberFieldsProps): ReactElement {
    const [rankDefaultedFromProfile, setRankDefaultedFromProfile] =
        useState(false);

    const resolvedEmployeeStatus =
        employeeStatus ??
        (showOperationalStatus &&
        'employee_status_by_employee' in formOptions &&
        data.employee_id != null
            ? lookupByEmployeeId(
                  formOptions.employee_status_by_employee,
                  data.employee_id,
              )
            : null);

    const resolvedActiveOnVessel =
        activeOnVessel ??
        (showOperationalStatus &&
        'active_on_vessel_by_employee' in formOptions &&
        data.employee_id != null
            ? lookupByEmployeeId(
                  formOptions.active_on_vessel_by_employee,
                  data.employee_id,
              )
            : null);

    const companyTimezone =
        'company_timezone' in formOptions &&
        typeof formOptions.company_timezone === 'string'
            ? formOptions.company_timezone
            : 'UTC';

    const selectedEmployee = formOptions.employees.find(
        (employee) => employee.id === data.employee_id,
    );

    const profileRankName =
        selectedEmployee?.rank_id != null
            ? (formOptions.ranks.find(
                  (rank) => rank.id === selectedEmployee.rank_id,
              )?.name ?? null)
            : null;

    const employeeContainerClass = resolvedEmployeeStatus
        ? getEmployeeStatusContainerClass(resolvedEmployeeStatus.status)
        : 'border-border/60 bg-muted/10';

    const employeeOptions = formOptions.employees.filter(
        (employee) =>
            employee.id === data.employee_id ||
            !selectedEmployeeIds?.has(employee.id),
    );

    return (
        <div className="space-y-4">
            {lockEmployee ? (
                <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                    <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        Employee
                    </p>
                    <p className="mt-1 text-sm font-medium">
                        {employeeLabel ?? '—'}
                    </p>
                </div>
            ) : (
                <div
                    className={cn(
                        'space-y-4 rounded-xl border p-4 transition-colors',
                        showOperationalStatus ? employeeContainerClass : '',
                    )}
                >
                    <div className="space-y-2">
                        <Label htmlFor="crew-employee">Employee *</Label>
                        <AppSelect
                            value={data.employee_id?.toString() ?? ''}
                            onValueChange={(value) => {
                                const employeeId = value ? Number(value) : null;
                                const employee = formOptions.employees.find(
                                    (item) => item.id === employeeId,
                                );
                                const defaultRankId = employee?.rank_id ?? null;
                                const shouldUseProfileRank =
                                    defaultRankId !== null &&
                                    (rankDefaultedFromProfile ||
                                        data.rank_id === null);
                                const nextRankId = shouldUseProfileRank
                                    ? defaultRankId
                                    : rankDefaultedFromProfile
                                      ? null
                                      : data.rank_id;

                                onChange({
                                    ...data,
                                    employee_id: employeeId,
                                    rank_id: nextRankId,
                                });
                                setRankDefaultedFromProfile(
                                    shouldUseProfileRank,
                                );
                            }}
                            variant="dark"
                            placeholder="Select employee..."
                            searchPlaceholder="Search employee..."
                        >
                            <AppSelectItem value="">
                                Select employee...
                            </AppSelectItem>
                            {employeeOptions.map((employee) => (
                                <AppSelectItem
                                    key={employee.id}
                                    value={String(employee.id)}
                                >
                                    {employee.name}
                                    {employee.employee_no
                                        ? ` (${employee.employee_no})`
                                        : ''}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {selectedEmployee ? (
                            <div className="space-y-1 text-xs text-muted-foreground">
                                {selectedEmployee.employee_no ? (
                                    <p>
                                        Employee number:{' '}
                                        <span className="font-medium text-foreground">
                                            {selectedEmployee.employee_no}
                                        </span>
                                    </p>
                                ) : null}
                                {profileRankName ? (
                                    <p>
                                        Default rank:{' '}
                                        <span className="font-medium text-foreground">
                                            {profileRankName}
                                        </span>
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        <InputError message={errors[employeeErrorKey]} />
                    </div>

                    {showOperationalStatus && resolvedEmployeeStatus ? (
                        <CrewEmployeeOperationalStatus
                            status={resolvedEmployeeStatus}
                            activeOnVessel={resolvedActiveOnVessel}
                            companyTimezone={companyTimezone}
                        />
                    ) : null}
                </div>
            )}

            <div className="space-y-2">
                <Label htmlFor="crew-rank">
                    Rank{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional until vessel joining)
                    </span>
                </Label>
                <AppSelect
                    value={data.rank_id?.toString() ?? ''}
                    onValueChange={(value) => {
                        onChange({
                            ...data,
                            rank_id: value ? Number(value) : null,
                        });
                        setRankDefaultedFromProfile(false);
                    }}
                    variant="dark"
                    placeholder="Select rank..."
                    searchPlaceholder="Search rank..."
                >
                    <AppSelectItem value="">No rank</AppSelectItem>
                    {formOptions.ranks.map((rank) => (
                        <AppSelectItem key={rank.id} value={String(rank.id)}>
                            {rank.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                {rankDefaultedFromProfile && !lockEmployee ? (
                    <p className="text-xs font-medium text-sky-700 dark:text-sky-300">
                        Defaulted from employee profile
                    </p>
                ) : null}
                <InputError message={errors[rankErrorKey]} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="crew-planned-arrival-at">
                    Arrival Date{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Input
                    id="crew-planned-arrival-at"
                    type="date"
                    className="h-11"
                    value={data.planned_arrival_at ?? ''}
                    onChange={(event) =>
                        onChange({
                            ...data,
                            planned_arrival_at: event.target.value || null,
                        })
                    }
                />
                <p className="text-xs text-muted-foreground">
                    {ARRIVAL_DATE_HELPER}
                </p>
                <InputError message={errors[arrivalErrorKey]} />
            </div>

            {currentPhase ? (
                <div className="space-y-2">
                    <Label>Current Assignment Stage</Label>
                    <div className="flex min-h-11 flex-col justify-center gap-1 rounded-md border border-border/70 bg-muted/20 px-3 py-2">
                        <CrewPhaseBadge
                            code={currentPhase.code}
                            label={currentPhase.label}
                            status={currentPhase.status}
                        />
                        {crewPhaseDescription(currentPhase.code) ? (
                            <p className="text-xs text-muted-foreground">
                                {crewPhaseDescription(currentPhase.code)}
                            </p>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
