import { useState } from 'react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    CrewEmployeeOperationalStatus,
    getEmployeeStatusContainerClass,
} from '@/features/organization/crew/components/crew-employee-operational-status';
import type {
    ActiveOnVesselAssignment,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

type CrewFormBag = {
    data: CrewAssignmentFormData;
    setData: {
        <K extends keyof CrewAssignmentFormData>(
            key: K,
            value: CrewAssignmentFormData[K],
        ): void;
        (data: CrewAssignmentFormData): void;
    };
    errors: Partial<Record<keyof CrewAssignmentFormData, string>>;
};

export function CrewAssignmentFormFields({
    form,
    formOptions,
    lockEmployee = false,
    employeeLabel,
    employeeStatus,
    activeOnVessel,
}: {
    form: CrewFormBag;
    formOptions: CrewAssignmentFormOptions | CrewAssignmentCreateFormOptions;
    lockEmployee?: boolean;
    employeeLabel?: string | null;
    employeeStatus?: EmployeeOperationalStatus | null;
    activeOnVessel?: ActiveOnVesselAssignment | null;
}): ReactElement {
    const [rankDefaultedFromProfile, setRankDefaultedFromProfile] =
        useState(false);

    const resolvedEmployeeStatus =
        employeeStatus ??
        ('employee_status_by_employee' in formOptions &&
        form.data.employee_id != null
            ? (formOptions.employee_status_by_employee?.[
                  String(form.data.employee_id)
              ] ??
              formOptions.employee_status_by_employee?.[
                  form.data.employee_id
              ] ??
              null)
            : null);

    const resolvedActiveOnVessel =
        activeOnVessel ??
        ('active_on_vessel_by_employee' in formOptions &&
        form.data.employee_id != null
            ? (formOptions.active_on_vessel_by_employee?.[
                  String(form.data.employee_id)
              ] ??
              formOptions.active_on_vessel_by_employee?.[
                  form.data.employee_id
              ] ??
              null)
            : null);

    const employeeContainerClass = resolvedEmployeeStatus
        ? getEmployeeStatusContainerClass(resolvedEmployeeStatus.status)
        : 'border-border/60 bg-muted/10';

    const companyTimezone =
        'company_timezone' in formOptions &&
        typeof formOptions.company_timezone === 'string'
            ? formOptions.company_timezone
            : 'UTC';

    const selectedEmployee = formOptions.employees.find(
        (employee) => employee.id === form.data.employee_id,
    );

    const profileRankName =
        selectedEmployee?.rank_id != null
            ? (formOptions.ranks.find(
                  (rank) => rank.id === selectedEmployee.rank_id,
              )?.name ?? null)
            : null;

    const signOffBeforeJoin =
        form.data.planned_join_at !== '' &&
        form.data.planned_signoff_at !== '' &&
        form.data.planned_signoff_at < form.data.planned_join_at;

    const showPlanningSyncNotice =
        form.data.vessel_id !== null &&
        form.data.rank_id !== null &&
        form.data.planned_join_at !== '' &&
        form.data.planned_signoff_at !== '' &&
        !signOffBeforeJoin;

    const setOptionalId = (
        key: 'employee_id' | 'rank_id' | 'vessel_id' | 'client_id',
        value: string,
    ): void => {
        form.setData(key, value ? Number(value) : null);
    };

    const vesselsForClient = formOptions.vessels.filter((vessel) => {
        if (form.data.vessel_id !== null && vessel.id === form.data.vessel_id) {
            return true;
        }

        if (vessel.client_id == null) {
            return false;
        }

        if (form.data.client_id === null) {
            return true;
        }

        return vessel.client_id === form.data.client_id;
    });

    const selectedVesselIsLegacyUnassigned =
        form.data.vessel_id !== null &&
        formOptions.vessels.some(
            (vessel) =>
                vessel.id === form.data.vessel_id && vessel.client_id == null,
        );

    const setClientId = (value: string): void => {
        const nextClientId = value ? Number(value) : null;
        const selectedVessel = formOptions.vessels.find(
            (vessel) => vessel.id === form.data.vessel_id,
        );
        const vesselMatches =
            selectedVessel != null &&
            selectedVessel.is_active !== false &&
            selectedVessel.client_id != null &&
            nextClientId !== null &&
            selectedVessel.client_id === nextClientId;

        form.setData({
            ...form.data,
            client_id: nextClientId,
            vessel_id: vesselMatches ? form.data.vessel_id : null,
        });
    };

    const setVesselId = (value: string): void => {
        const nextVesselId = value ? Number(value) : null;
        const selectedVessel = formOptions.vessels.find(
            (vessel) => vessel.id === nextVesselId,
        );

        form.setData({
            ...form.data,
            vessel_id: nextVesselId,
            client_id:
                selectedVessel?.client_id != null
                    ? selectedVessel.client_id
                    : form.data.client_id,
        });
    };

    return (
        <div className="space-y-8">
            <section className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Crew member
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Assign who this mobilisation cycle belongs to.
                    </p>
                </div>

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
                            employeeContainerClass,
                        )}
                    >
                        <div className="space-y-2">
                            <Label htmlFor="crew-employee">Employee *</Label>
                            <AppSelect
                                value={form.data.employee_id?.toString() ?? ''}
                                onValueChange={(value) => {
                                    const employeeId = value
                                        ? Number(value)
                                        : null;
                                    const selected = formOptions.employees.find(
                                        (employee) =>
                                            employee.id === employeeId,
                                    );
                                    const defaultRankId =
                                        selected?.rank_id ?? null;
                                    const shouldUseProfileRank =
                                        defaultRankId !== null &&
                                        (rankDefaultedFromProfile ||
                                            form.data.rank_id === null);
                                    const nextRankId = shouldUseProfileRank
                                        ? defaultRankId
                                        : rankDefaultedFromProfile
                                          ? null
                                          : form.data.rank_id;

                                    form.setData({
                                        ...form.data,
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
                                {formOptions.employees.map((employee) => (
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
                            <InputError message={form.errors.employee_id} />
                        </div>

                        {resolvedEmployeeStatus ? (
                            <CrewEmployeeOperationalStatus
                                status={resolvedEmployeeStatus}
                                activeOnVessel={resolvedActiveOnVessel}
                                companyTimezone={companyTimezone}
                            />
                        ) : null}
                    </div>
                )}
            </section>

            <section className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Assignment details
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Vessel, client, and rank can be refined before join.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="space-y-2">
                        <Label htmlFor="crew-rank">
                            Rank{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional until vessel joining)
                            </span>
                        </Label>
                        <AppSelect
                            value={form.data.rank_id?.toString() ?? ''}
                            onValueChange={(value) => {
                                setOptionalId('rank_id', value);
                                setRankDefaultedFromProfile(false);
                            }}
                            variant="dark"
                            placeholder="Select rank..."
                            searchPlaceholder="Search rank..."
                        >
                            <AppSelectItem value="">No rank</AppSelectItem>
                            {formOptions.ranks.map((rank) => (
                                <AppSelectItem
                                    key={rank.id}
                                    value={String(rank.id)}
                                >
                                    {rank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {rankDefaultedFromProfile && !lockEmployee ? (
                            <p className="text-xs font-medium text-sky-700 dark:text-sky-300">
                                Defaulted from employee profile
                            </p>
                        ) : null}
                        <InputError message={form.errors.rank_id} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="crew-client">
                            Client{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <AppSelect
                            value={form.data.client_id?.toString() ?? ''}
                            onValueChange={setClientId}
                            variant="dark"
                            placeholder="Select client..."
                            searchPlaceholder="Search client..."
                        >
                            <AppSelectItem value="">No client</AppSelectItem>
                            {formOptions.clients.map((client) => (
                                <AppSelectItem
                                    key={client.id}
                                    value={String(client.id)}
                                >
                                    {client.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <InputError message={form.errors.client_id} />
                    </div>

                    <div className="space-y-2 sm:col-span-2 lg:col-span-1">
                        <Label htmlFor="crew-vessel">
                            Vessel{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional until vessel joining)
                            </span>
                        </Label>
                        <AppSelect
                            value={form.data.vessel_id?.toString() ?? ''}
                            onValueChange={setVesselId}
                            variant="dark"
                            placeholder="Select vessel..."
                            searchPlaceholder="Search vessel..."
                        >
                            <AppSelectItem value="">No vessel</AppSelectItem>
                            {vesselsForClient.map((vessel) => (
                                <AppSelectItem
                                    key={vessel.id}
                                    value={String(vessel.id)}
                                >
                                    {vessel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.data.client_id !== null &&
                        vesselsForClient.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                No vessels are assigned to this client.
                            </p>
                        ) : null}
                        {selectedVesselIsLegacyUnassigned ? (
                            <p className="text-xs text-muted-foreground">
                                This vessel has no current client assignment.
                                Map the vessel before changing the
                                assignment&apos;s Client or Vessel.
                            </p>
                        ) : null}
                        <InputError message={form.errors.vessel_id} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Planned dates{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Planned Sign-Off is a forecast only. Actual leaving is
                        recorded through Confirm Disembarkation.
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-2">
                        <Label htmlFor="planned_join_at">
                            Planned Join{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="planned_join_at"
                            type="date"
                            className="h-11"
                            value={form.data.planned_join_at}
                            onChange={(event) =>
                                form.setData(
                                    'planned_join_at',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Expected vessel joining date. Actual joining is
                            recorded using Join Vessel.
                        </p>
                        <InputError message={form.errors.planned_join_at} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="planned_signoff_at">
                            Planned Sign-Off{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="planned_signoff_at"
                            type="date"
                            className="h-11"
                            min={
                                form.data.planned_join_at !== ''
                                    ? form.data.planned_join_at
                                    : undefined
                            }
                            value={form.data.planned_signoff_at}
                            onChange={(event) =>
                                form.setData(
                                    'planned_signoff_at',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Expected vessel leave date. Actual leaving is
                            recorded using Confirm Disembarkation.
                        </p>
                        {signOffBeforeJoin ? (
                            <p className="text-xs font-medium text-destructive">
                                Planned Sign-Off cannot be before Planned Join.
                            </p>
                        ) : null}
                        <InputError message={form.errors.planned_signoff_at} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="planned_travel_at">
                            Planned Travel Home{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="planned_travel_at"
                            type="date"
                            className="h-11"
                            value={form.data.planned_travel_at}
                            onChange={(event) =>
                                form.setData(
                                    'planned_travel_at',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Expected return-travel date after vessel service.
                        </p>
                        <InputError message={form.errors.planned_travel_at} />
                    </div>
                </div>

                {showPlanningSyncNotice ? (
                    <div className="rounded-xl border border-sky-500/35 bg-sky-500/10 px-4 py-3 text-sm text-sky-900 dark:text-sky-100">
                        A linked Planning bar will be created or updated
                        automatically.
                    </div>
                ) : null}
            </section>

            <section className="space-y-2">
                <Label htmlFor="remarks">
                    Remarks{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Textarea
                    id="remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                    placeholder="Optional operational notes..."
                    className="max-h-36 min-h-20"
                />
                <InputError message={form.errors.remarks} />
            </section>
        </div>
    );
}
