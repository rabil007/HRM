import { Globe, Mail, MapPin, Phone, Users } from 'lucide-react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import type { CountryOption } from '@/features/organization/employees/types';
import { formatDisplayDate } from '@/lib/format-date';
import { formatPhoneForDisplay } from '@/lib/phone-with-dial-code';
import {
    PersonalEditableTextRow,
    PersonalFieldRow,
    personalFieldLabelClass,
    personalFieldRowClass,
} from '@/features/organization/employees/profile/components/personal-field-row';
import { EmployeeSectionCard } from '@/pages/organization/_components/employee-section-card';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

export type EmployeePersonalFormSlice = {
    data: Record<string, unknown> & {
        personal_email?: string;
        phone_home_country?: string;
        emergency_contact?: string;
        emergency_phone?: string;
        spouse_name?: string;
        spouse_birthdate?: string;
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
                    description="Personal email and home-country mobile"
                    icon={Mail}
                >
                    <div className="space-y-1">
                        <PersonalEditableTextRow
                            label="Email"
                            field="personal_email"
                            value={form.data.personal_email ?? ''}
                            displayValue={
                                form.data.personal_email ||
                                employee.personal_email ||
                                '—'
                            }
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('personal_email', value)}
                            error={form.errors.personal_email}
                        />

                        <div className={personalFieldRowClass}>
                            <label className={personalFieldLabelClass}>
                                Mobile (Home Country)
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
                    </div>
                </EmployeeSectionCard>

                <EmployeeSectionCard
                    title="Emergency contact"
                    description="Primary emergency contact"
                    icon={Phone}
                >
                    <div className="space-y-1">
                        {[
                            {
                                label: 'Contacted Name',
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
                                label: 'Contacted Mobile',
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
                        ].map((item, i) => (
                            <PersonalFieldRow key={i} label={item.label}>
                                {item.value}
                            </PersonalFieldRow>
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
                                value:
                                    form.data.spouse_name ||
                                    employee.spouse_name ||
                                    '—',
                            },
                            {
                                key: 'spouse_birthdate',
                                label: 'Spouse birthdate',
                                value: formatDisplayDate(
                                    form.data.spouse_birthdate || employee.spouse_birthdate,
                                ),
                            },
                        ].map((row) => (
                            <PersonalEditableTextRow
                                key={row.key}
                                label={row.label}
                                field={row.key}
                                value={String((form.data as Record<string, unknown>)[row.key] ?? '')}
                                displayValue={row.value}
                                activeField={activeField}
                                setActiveField={setActiveField}
                                beginEdit={beginEdit}
                                onChange={(value) => form.setData(row.key, value)}
                                inputType={row.key === 'spouse_birthdate' ? 'date' : 'text'}
                            />
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
                                label: 'Nearest Airport (Home Country)',
                                value:
                                    form.data.nearest_airport ||
                                    employee.nearest_airport ||
                                    '—',
                            },
                            {
                                key: 'address',
                                label: 'Address',
                                value:
                                    form.data.address || employee.address || '—',
                            },
                        ].map((row) => (
                            <PersonalEditableTextRow
                                key={row.key}
                                label={row.label}
                                field={row.key}
                                value={String((form.data as Record<string, unknown>)[row.key] ?? '')}
                                displayValue={row.value}
                                activeField={activeField}
                                setActiveField={setActiveField}
                                beginEdit={beginEdit}
                                onChange={(value) => form.setData(row.key, value)}
                            />
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
                                    <AppSelect
                                        value={form.data.nationality_id as string ?? ''}
                                        onValueChange={(v) => {
 form.setData('nationality_id', v); setActiveField(null); 
}}
                                        onClose={() => setActiveField(null)}
                                        variant="dark"
                                        placeholder="—"
                                    >
                                        <AppSelectItem value="">—</AppSelectItem>
                                        {countries.map((c) => (
                                            <AppSelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.name}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
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
                            <PersonalEditableTextRow
                                key={item.key}
                                label={item.label}
                                field={item.key}
                                value={String((form.data as Record<string, unknown>)[item.key] ?? '')}
                                displayValue={item.value}
                                activeField={activeField}
                                setActiveField={setActiveField}
                                beginEdit={beginEdit}
                                onChange={(value) => form.setData(item.key, value)}
                            />
                        ))}
            </EmployeeSectionCard>
            </div>
        </TabsContent>
    );
}
