import type { ReactElement } from 'react';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import type { CountryOption } from '@/features/organization/employees/types';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

export type EmployeePersonalFormSlice = {
    data: Record<string, unknown> & {
        personal_email?: string;
        phone_home_country?: string;
        cv_source?: string;
        emergency_contact?: string;
        emergency_phone?: string;
        emergency_contact_home_country?: string;
        emergency_phone_home_country?: string;
        spouse_name?: string;
        spouse_birthdate?: string;
        dependent_children_count?: string | number;
        nearest_airport?: string;
        address?: string;
        nationality_id?: string;
        passport_number?: string;
        emirates_id?: string;
        labor_card_number?: string;
    };
    errors: Record<string, string | undefined>;
    setData: (key: string, value: unknown) => void;
};

export type EmployeePersonalTabProps = {
    employee: EmployeeDetails;
    countries: CountryOption[];
    form: EmployeePersonalFormSlice;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
};

export function EmployeePersonalTab({
    employee,
    countries,
    form,
    activeField,
    setActiveField,
    beginEdit,
}: EmployeePersonalTabProps): ReactElement {
    return (
        <TabsContent value="personal" className="mt-6">
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 2xl:grid-cols-3">
                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold text-zinc-200">
                            Private contact
                        </h3>
                    </div>

                    <div className="space-y-4">
                        <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                            <label className="text-xs font-medium text-zinc-400">
                                Email
                            </label>
                            {activeField === 'personal_email' ? (
                                <div>
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.personal_email}
                                        onChange={(e) =>
                                            form.setData(
                                                'personal_email',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                    {form.errors.personal_email ? (
                                        <div className="mt-1 text-xs text-destructive">
                                            {form.errors.personal_email}
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                    onClick={() => beginEdit('personal_email')}
                                >
                                    {form.data.personal_email ||
                                        employee.personal_email ||
                                        '—'}
                                </button>
                            )}
                        </div>

                        <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                            <label className="text-xs font-medium text-zinc-400">
                                Phone (Home Country)
                            </label>
                            {activeField === 'phone_home_country' ? (
                                <div>
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.phone_home_country}
                                        onChange={(e) =>
                                            form.setData(
                                                'phone_home_country',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                    {form.errors.phone_home_country ? (
                                        <div className="mt-1 text-xs text-destructive">
                                            {form.errors.phone_home_country}
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                    onClick={() =>
                                        beginEdit('phone_home_country')
                                    }
                                >
                                    {form.data.phone_home_country ||
                                        employee.phone_home_country ||
                                        '—'}
                                </button>
                            )}
                        </div>

                        <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                            <label className="text-xs font-medium text-zinc-400">
                                Source Of CV
                            </label>
                            {activeField === 'cv_source' ? (
                                <div>
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.cv_source}
                                        onChange={(e) =>
                                            form.setData(
                                                'cv_source',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                    {form.errors.cv_source ? (
                                        <div className="mt-1 text-xs text-destructive">
                                            {form.errors.cv_source}
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                    onClick={() => beginEdit('cv_source')}
                                >
                                    {form.data.cv_source ||
                                        employee.cv_source ||
                                        '—'}
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-zinc-200">
                            Emergency contact
                        </h3>
                    </div>

                    <div className="space-y-3">
                        {[
                            {
                                label: 'Contact',
                                value:
                                    activeField === 'emergency_contact' ? (
                                        <Input
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.emergency_contact}
                                            onChange={(e) =>
                                                form.setData(
                                                    'emergency_contact',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() =>
                                                beginEdit('emergency_contact')
                                            }
                                        >
                                            {form.data.emergency_contact ||
                                                employee.emergency_contact ||
                                                '—'}
                                        </button>
                                    ),
                            },
                            {
                                label: 'Phone',
                                value:
                                    activeField === 'emergency_phone' ? (
                                        <Input
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.emergency_phone}
                                            onChange={(e) =>
                                                form.setData(
                                                    'emergency_phone',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() =>
                                                beginEdit('emergency_phone')
                                            }
                                        >
                                            {form.data.emergency_phone ||
                                                employee.emergency_phone ||
                                                '—'}
                                        </button>
                                    ),
                            },
                            {
                                label: 'Home country contact',
                                value:
                                    activeField ===
                                    'emergency_contact_home_country' ? (
                                        <Input
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={
                                                form.data
                                                    .emergency_contact_home_country
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'emergency_contact_home_country',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() =>
                                                beginEdit(
                                                    'emergency_contact_home_country',
                                                )
                                            }
                                        >
                                            {form.data
                                                .emergency_contact_home_country ||
                                                employee.emergency_contact_home_country ||
                                                '—'}
                                        </button>
                                    ),
                            },
                            {
                                label: 'Home country phone',
                                value:
                                    activeField ===
                                    'emergency_phone_home_country' ? (
                                        <Input
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={
                                                form.data
                                                    .emergency_phone_home_country
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'emergency_phone_home_country',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() =>
                                                beginEdit(
                                                    'emergency_phone_home_country',
                                                )
                                            }
                                        >
                                            {form.data
                                                .emergency_phone_home_country ||
                                                employee.emergency_phone_home_country ||
                                                '—'}
                                        </button>
                                    ),
                            },
                        ].map((item, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                            >
                                <label className="text-xs font-medium text-zinc-400">
                                    {item.label}
                                </label>
                                <div className="text-sm font-medium text-zinc-200">
                                    {item.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-zinc-200">
                            Family
                        </h3>
                    </div>

                    <div className="space-y-3">
                        {[
                            {
                                key: 'spouse_name',
                                label: 'Spouse name',
                                input: (
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.spouse_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'spouse_name',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ),
                                value:
                                    form.data.spouse_name ||
                                    employee.spouse_name ||
                                    '—',
                            },
                            {
                                key: 'spouse_birthdate',
                                label: 'Spouse birthdate',
                                input: (
                                    <Input
                                        type="date"
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.spouse_birthdate}
                                        onChange={(e) =>
                                            form.setData(
                                                'spouse_birthdate',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ),
                                value:
                                    form.data.spouse_birthdate ||
                                    employee.spouse_birthdate ||
                                    '—',
                            },
                            {
                                key: 'dependent_children_count',
                                label: 'Dependent children',
                                input: (
                                    <Input
                                        inputMode="numeric"
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={String(
                                            form.data
                                                .dependent_children_count ?? '',
                                        )}
                                        onChange={(e) =>
                                            form.setData(
                                                'dependent_children_count',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ),
                                value:
                                    String(
                                        form.data.dependent_children_count ??
                                            '',
                                    ) ||
                                    (employee.dependent_children_count ===
                                        null ||
                                    employee.dependent_children_count ===
                                        undefined
                                        ? '—'
                                        : String(
                                              employee.dependent_children_count,
                                          )),
                            },
                        ].map((row) => (
                            <div
                                key={row.key}
                                className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                            >
                                <label className="text-xs font-medium text-zinc-400">
                                    {row.label}
                                </label>
                                {activeField === row.key ? (
                                    <div>{row.input}</div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit(row.key)}
                                    >
                                        {row.value}
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-zinc-200">
                            Location
                        </h3>
                    </div>

                    <div className="space-y-3">
                        {[
                            {
                                key: 'nearest_airport',
                                label: 'Nearest airport',
                                input: (
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.nearest_airport}
                                        onChange={(e) =>
                                            form.setData(
                                                'nearest_airport',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ),
                                value:
                                    form.data.nearest_airport ||
                                    employee.nearest_airport ||
                                    '—',
                            },
                            {
                                key: 'address',
                                label: 'Address',
                                input: (
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={form.data.address}
                                        onChange={(e) =>
                                            form.setData(
                                                'address',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ),
                                value:
                                    form.data.address ||
                                    employee.address ||
                                    '—',
                            },
                        ].map((row) => (
                            <div
                                key={row.key}
                                className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                            >
                                <label className="text-xs font-medium text-zinc-400">
                                    {row.label}
                                </label>
                                {activeField === row.key ? (
                                    <div>{row.input}</div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit(row.key)}
                                    >
                                        {row.value}
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl xl:col-span-2 2xl:col-span-3">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-zinc-200">
                            Citizenship
                        </h3>
                    </div>

                    <div className="space-y-4">
                        <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                            <label className="text-xs font-medium text-zinc-400">
                                Nationality (Country)
                            </label>
                            {activeField === 'nationality_id' ? (
                                <div>
                                    <select
                                        className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                        value={form.data.nationality_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'nationality_id',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    >
                                        <option value="">—</option>
                                        {countries.map((c) => (
                                            <option
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                    {form.errors.nationality_id ? (
                                        <div className="mt-1 text-xs text-destructive">
                                            {form.errors.nationality_id}
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                    onClick={() => beginEdit('nationality_id')}
                                >
                                    {countries.find(
                                        (c) =>
                                            String(c.id) ===
                                            String(
                                                form.data.nationality_id ||
                                                    employee.nationality_id ||
                                                    '',
                                            ),
                                    )?.name ??
                                        employee.nationality_ref?.name ??
                                        '—'}
                                </button>
                            )}
                        </div>

                        {[
                            {
                                key: 'passport_number',
                                label: 'Passport No',
                                value:
                                    form.data.passport_number ||
                                    employee.passport_number ||
                                    '—',
                            },
                            {
                                key: 'emirates_id',
                                label: 'Emirates ID',
                                value:
                                    form.data.emirates_id ||
                                    employee.emirates_id ||
                                    '—',
                            },
                            {
                                key: 'labor_card_number',
                                label: 'Labor card number',
                                value:
                                    form.data.labor_card_number ||
                                    employee.labor_card_number ||
                                    '—',
                            },
                        ].map((item) => (
                            <div
                                key={item.key}
                                className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                            >
                                <label className="text-xs font-medium text-zinc-400">
                                    {item.label}
                                </label>
                                {activeField === item.key ? (
                                    <Input
                                        className="h-10 rounded-xl border-white/5 bg-white/5"
                                        value={(form.data as any)[item.key]}
                                        onChange={(e) =>
                                            form.setData(
                                                item.key as any,
                                                e.target.value,
                                            )
                                        }
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit(item.key)}
                                    >
                                        {item.value}
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </TabsContent>
    );
}
