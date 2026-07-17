import { useState } from 'react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
} from '@/features/organization/crew/types';

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
}: {
    form: CrewFormBag;
    formOptions: CrewAssignmentFormOptions;
    lockEmployee?: boolean;
    employeeLabel?: string | null;
}): ReactElement {
    const [rankDefaultedFromProfile, setRankDefaultedFromProfile] =
        useState(false);

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
        key:
            | 'employee_id'
            | 'rank_id'
            | 'vessel_id'
            | 'client_id'
            | 'company_visa_type_id',
        value: string,
    ): void => {
        form.setData(key, value ? Number(value) : null);
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
                    <div className="space-y-2">
                        <Label htmlFor="crew-employee">Employee *</Label>
                        <AppSelect
                            value={form.data.employee_id?.toString() ?? ''}
                            onValueChange={(value) => {
                                const employeeId = value ? Number(value) : null;
                                const selected = formOptions.employees.find(
                                    (employee) => employee.id === employeeId,
                                );
                                const defaultRankId = selected?.rank_id ?? null;

                                form.setData({
                                    ...form.data,
                                    employee_id: employeeId,
                                    rank_id: defaultRankId ?? form.data.rank_id,
                                });
                                setRankDefaultedFromProfile(
                                    defaultRankId !== null,
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
                )}
            </section>

            <section className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Assignment details
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Vessel, rank, client, and visa can be refined before
                        join.
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
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
                        <Label htmlFor="crew-vessel">
                            Vessel{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional until vessel joining)
                            </span>
                        </Label>
                        <AppSelect
                            value={form.data.vessel_id?.toString() ?? ''}
                            onValueChange={(value) =>
                                setOptionalId('vessel_id', value)
                            }
                            variant="dark"
                            placeholder="Select vessel..."
                            searchPlaceholder="Search vessel..."
                        >
                            <AppSelectItem value="">No vessel</AppSelectItem>
                            {formOptions.vessels.map((vessel) => (
                                <AppSelectItem
                                    key={vessel.id}
                                    value={String(vessel.id)}
                                >
                                    {vessel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <InputError message={form.errors.vessel_id} />
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
                            onValueChange={(value) =>
                                setOptionalId('client_id', value)
                            }
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

                    <div className="space-y-2">
                        <Label htmlFor="crew-visa">
                            Visa type{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <AppSelect
                            value={
                                form.data.company_visa_type_id?.toString() ?? ''
                            }
                            onValueChange={(value) =>
                                setOptionalId('company_visa_type_id', value)
                            }
                            variant="dark"
                            placeholder="Select visa type..."
                            searchPlaceholder="Search visa type..."
                        >
                            <AppSelectItem value="">No visa type</AppSelectItem>
                            {formOptions.visa_types.map((visaType) => (
                                <AppSelectItem
                                    key={visaType.id}
                                    value={String(visaType.id)}
                                >
                                    {visaType.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <InputError
                            message={form.errors.company_visa_type_id}
                        />
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
                        Planned Sign-Off is a plan only — it does not disembark
                        the crew member.
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
                    rows={4}
                    placeholder="Optional operational notes..."
                    className="min-h-24"
                />
                <InputError message={form.errors.remarks} />
            </section>
        </div>
    );
}
