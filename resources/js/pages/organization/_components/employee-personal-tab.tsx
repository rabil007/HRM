import { Globe, Mail, MapPin, Phone, Users } from 'lucide-react';
import type { ReactElement } from 'react';
import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import type { CountryOption } from '@/features/organization/employees/types';
import { formatPhoneForDisplay } from '@/lib/phone-with-dial-code';
import { EmployeeSectionCard } from '@/pages/organization/_components/employee-section-card';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

const personalFieldRowClass =
    'grid grid-cols-1 gap-2 rounded-xl border border-transparent px-3 py-2.5 transition-colors hover:border-white/[0.06] hover:bg-white/[0.03] sm:grid-cols-[minmax(0,9.5rem)_1fr] sm:items-center sm:gap-5';
const personalFieldLabelClass =
    'text-[11px] font-medium uppercase tracking-wider text-zinc-500';

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
        <TabsContent value="personal" className="mt-6 space-y-6">
            <div className="grid items-stretch gap-4 lg:grid-cols-3">
                <EmployeeSectionCard
                    title="Private contact"
                    description="Personal email and home-country phone"
                    icon={Mail}
                >
                    <div className="space-y-1">
                        <div className={personalFieldRowClass}>
                            <label className={personalFieldLabelClass}>
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

                        <div className={personalFieldRowClass}>
                            <label className={personalFieldLabelClass}>
                                Phone (Home Country)
                            </label>
                            {activeField === 'phone_home_country' ? (
                                <div>
                                    <PhoneInputWithCountry
                                        countries={countries}
                                        value={form.data.phone_home_country ?? ''}
                                        onChange={(next) =>
                                            form.setData('phone_home_country', next)
                                        }
                                        fieldKey="phone_home_country"
                                        autoFocus
                                        onBlur={() => setActiveField(null)}
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
                                    {formatPhoneForDisplay(
                                        form.data.phone_home_country ||
                                            employee.phone_home_country,
                                        {
                                            countries,
                                            fieldKey: 'phone_home_country',
                                        },
                                    )}
                                </button>
                            )}
                        </div>

                        <div className={personalFieldRowClass}>
                            <label className={personalFieldLabelClass}>
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
                </EmployeeSectionCard>

                <EmployeeSectionCard
                    title="Emergency contact"
                    description="Primary and home-country contacts"
                    icon={Phone}
                >
                    <div className="space-y-1">
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
                                        <PhoneInputWithCountry
                                            countries={countries}
                                            value={form.data.emergency_phone ?? ''}
                                            onChange={(next) =>
                                                form.setData('emergency_phone', next)
                                            }
                                            fieldKey="emergency_phone"
                                            defaultDialCode="+971"
                                            autoFocus
                                            onBlur={() => setActiveField(null)}
                                        />
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() =>
                                                beginEdit('emergency_phone')
                                            }
                                        >
                                            {formatPhoneForDisplay(
                                                form.data.emergency_phone ||
                                                    employee.emergency_phone,
                                                {
                                                    countries,
                                                    fieldKey: 'emergency_phone',
                                                    defaultDialCode: '+971',
                                                },
                                            )}
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
                                        <PhoneInputWithCountry
                                            countries={countries}
                                            value={
                                                form.data
                                                    .emergency_phone_home_country ??
                                                ''
                                            }
                                            onChange={(next) =>
                                                form.setData(
                                                    'emergency_phone_home_country',
                                                    next,
                                                )
                                            }
                                            fieldKey="emergency_phone_home_country"
                                            autoFocus
                                            onBlur={() => setActiveField(null)}
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
                                            {formatPhoneForDisplay(
                                                form.data
                                                    .emergency_phone_home_country ||
                                                    employee.emergency_phone_home_country,
                                                {
                                                    countries,
                                                    fieldKey:
                                                        'emergency_phone_home_country',
                                                },
                                            )}
                                        </button>
                                    ),
                            },
                        ].map((item, i) => (
                            <div key={i} className={personalFieldRowClass}>
                                <label className={personalFieldLabelClass}>
                                    {item.label}
                                </label>
                                <div className="min-w-0 text-sm font-medium text-zinc-100">
                                    {item.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </EmployeeSectionCard>

                <EmployeeSectionCard
                    title="Family"
                    description="Spouse and dependents"
                    icon={Users}
                >
                    <div className="space-y-1">
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
                            <div key={row.key} className={personalFieldRowClass}>
                                <label className={personalFieldLabelClass}>
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
                </EmployeeSectionCard>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-stretch">
            <EmployeeSectionCard
                title="Location"
                description="Travel and residence"
                icon={MapPin}
            >
                <div className="space-y-1">
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
                            <div key={row.key} className={personalFieldRowClass}>
                                <label className={personalFieldLabelClass}>
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
            </EmployeeSectionCard>

            <EmployeeSectionCard
                title="Citizenship"
                description="Identity documents and work permits"
                icon={Globe}
                bodyClassName="grid gap-1 lg:grid-cols-2 lg:gap-x-10"
            >
                <div className={personalFieldRowClass}>
                            <label className={personalFieldLabelClass}>
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
                            <div key={item.key} className={personalFieldRowClass}>
                                <label className={personalFieldLabelClass}>
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
            </EmployeeSectionCard>
            </div>
        </TabsContent>
    );
}
