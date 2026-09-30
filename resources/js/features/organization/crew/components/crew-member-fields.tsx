import type { ReactElement } from 'react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getEmployeeStatusContainerClass } from '@/features/organization/crew/components/crew-employee-operational-status';
import { CrewEmployeeStatusBadge } from '@/features/organization/crew/components/crew-employee-status-badge';
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
    position_id: number | null;
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
    positionErrorKey?: string;
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

function positionOptions(
    formOptions: CrewAssignmentFormOptions | CrewAssignmentCreateFormOptions,
): Array<{ id: number; name: string }> {
    return formOptions.positions ?? [];
}

export function CrewMemberFields({
    data,
    onChange,
    formOptions,
    errors,
    lockEmployee = false,
    employeeLabel,
    employeeStatus,
    showOperationalStatus = false,
    currentPhase = null,
    selectedEmployeeIds,
    employeeErrorKey = 'employee_id',
    positionErrorKey = 'position_id',
    arrivalErrorKey = 'planned_arrival_at',
}: CrewMemberFieldsProps): ReactElement {
    const [positionDefaultedFromProfile, setPositionDefaultedFromProfile] =
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

    const selectedEmployee = formOptions.employees.find(
        (employee) => employee.id === data.employee_id,
    );

    const positions = positionOptions(formOptions);

    const profilePositionName =
        selectedEmployee != null &&
        'position_id' in selectedEmployee &&
        selectedEmployee.position_id != null
            ? (positions.find(
                  (position) => position.id === selectedEmployee.position_id,
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
                        <div className="flex flex-wrap items-center gap-2">
                            <Label htmlFor="crew-employee">Employee *</Label>
                            {showOperationalStatus && resolvedEmployeeStatus ? (
                                <CrewEmployeeStatusBadge
                                    status={resolvedEmployeeStatus}
                                />
                            ) : null}
                        </div>
                        <AppSelect
                            value={data.employee_id?.toString() ?? ''}
                            onValueChange={(value) => {
                                const employeeId = value ? Number(value) : null;
                                const employee = formOptions.employees.find(
                                    (item) => item.id === employeeId,
                                );
                                const defaultPositionId =
                                    employee != null &&
                                    'position_id' in employee
                                        ? (employee.position_id ?? null)
                                        : null;
                                const shouldUseProfilePosition =
                                    defaultPositionId !== null &&
                                    (positionDefaultedFromProfile ||
                                        data.position_id === null);
                                const nextPositionId = shouldUseProfilePosition
                                    ? defaultPositionId
                                    : positionDefaultedFromProfile
                                      ? null
                                      : data.position_id;

                                onChange({
                                    ...data,
                                    employee_id: employeeId,
                                    position_id: nextPositionId,
                                });
                                setPositionDefaultedFromProfile(
                                    shouldUseProfilePosition,
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
                                {profilePositionName ? (
                                    <p>
                                        Default position:{' '}
                                        <span className="font-medium text-foreground">
                                            {profilePositionName}
                                        </span>
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        <InputError message={errors[employeeErrorKey]} />
                    </div>
                </div>
            )}

            <div className="space-y-2">
                <Label htmlFor="crew-position">
                    Position{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional until vessel joining)
                    </span>
                </Label>
                <AppSelect
                    value={data.position_id?.toString() ?? ''}
                    onValueChange={(value) => {
                        onChange({
                            ...data,
                            position_id: value ? Number(value) : null,
                        });
                        setPositionDefaultedFromProfile(false);
                    }}
                    variant="dark"
                    placeholder="Select position..."
                    searchPlaceholder="Search position..."
                >
                    <AppSelectItem value="">No position</AppSelectItem>
                    {positions.map((position) => (
                        <AppSelectItem
                            key={position.id}
                            value={String(position.id)}
                        >
                            {position.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                {positionDefaultedFromProfile && !lockEmployee ? (
                    <p className="text-xs font-medium text-sky-700 dark:text-sky-300">
                        Defaulted from employee profile
                    </p>
                ) : null}
                <InputError message={errors[positionErrorKey]} />
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
                    value={data.planned_arrival_at ?? ''}
                    onChange={(event) => {
                        onChange({
                            ...data,
                            planned_arrival_at: event.target.value || null,
                        });
                    }}
                />
                <p className="text-xs text-muted-foreground">
                    {ARRIVAL_DATE_HELPER}
                </p>
                <InputError message={errors[arrivalErrorKey]} />
            </div>

            {showOperationalStatus && currentPhase ? (
                <div className="rounded-xl border border-border/60 bg-muted/10 px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                            Current phase
                        </p>
                        <CrewPhaseBadge
                            code={currentPhase.code}
                            label={currentPhase.label}
                            status={currentPhase.status}
                        />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {crewPhaseDescription(currentPhase.code)}
                    </p>
                </div>
            ) : null}
        </div>
    );
}
