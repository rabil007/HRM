import { Globe, Mail, MapPin, Phone, Users } from 'lucide-react';
import type { ReactElement } from 'react';
import { TabsContent } from '@/components/ui/tabs';
import {
    PersonalEditablePhoneRow,
    PersonalEditableSelectRow,
    PersonalEditableTextRow,
} from '@/features/organization/employees/profile/components/personal-field-row';
import {
    MARITAL_STATUS_OPTIONS,
    maritalStatusLabel,
} from '@/features/organization/employees/profile/marital-status-options';
import type { CountryOption } from '@/features/organization/employees/types';
import { EmployeeSectionCard } from '@/pages/organization/_components/employee-section-card';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

export type EmployeePersonalFormSlice = {
    data: Record<string, unknown> & {
        personal_email?: string;
        phone_home_country?: string;
        emergency_contact?: string;
        emergency_phone?: string;
        marital_status?: string;
        spouse_name?: string;
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
    const nationalityOptions = countries.map((country) => ({
        id: country.id,
        label: country.name,
        value: String(country.id),
    }));

    const nationalityDisplay =
        countries.find(
            (country) =>
                String(country.id) ===
                String(form.data.nationality_id || employee.nationality_id || ''),
        )?.name ??
        employee.nationality_ref?.name ??
        '—';

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

                        <PersonalEditablePhoneRow
                            label="Mobile (Home Country)"
                            field="phone_home_country"
                            value={form.data.phone_home_country ?? ''}
                            fallbackValue={employee.phone_home_country}
                                        countries={countries}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('phone_home_country', value)}
                            error={form.errors.phone_home_country}
                        />
                    </div>
                </EmployeeSectionCard>

                <EmployeeSectionCard
                    title="Emergency contact"
                    description="Primary emergency contact"
                    icon={Phone}
                >
                    <div className="space-y-1">
                        <PersonalEditableTextRow
                            label="Contacted Name"
                            field="emergency_contact"
                            value={form.data.emergency_contact ?? ''}
                            displayValue={
                                form.data.emergency_contact ||
                                employee.emergency_contact ||
                                '—'
                            }
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('emergency_contact', value)}
                        />
                        <PersonalEditablePhoneRow
                            label="Contacted Mobile"
                            field="emergency_phone"
                            value={form.data.emergency_phone ?? ''}
                            fallbackValue={employee.emergency_phone}
                                            countries={countries}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('emergency_phone', value)}
                                            defaultDialCode="+971"
                        />
                    </div>
                </EmployeeSectionCard>

                <EmployeeSectionCard
                    title="Family"
                    description="Marital status and spouse"
                    icon={Users}
                >
                    <div className="space-y-1">
                        <PersonalEditableSelectRow
                            label="Marital status"
                            field="marital_status"
                            value={String(form.data.marital_status ?? '')}
                            displayValue={maritalStatusLabel(
                                form.data.marital_status || employee.marital_status,
                            )}
                            options={[...MARITAL_STATUS_OPTIONS]}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('marital_status', value)}
                        />
                        <PersonalEditableTextRow
                            label="Spouse name"
                            field="spouse_name"
                            value={form.data.spouse_name ?? ''}
                            displayValue={
                                form.data.spouse_name || employee.spouse_name || '—'
                            }
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('spouse_name', value)}
                        />
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
                <PersonalEditableSelectRow
                            label="Nationality (Country)"
                            field="nationality_id"
                            value={String(form.data.nationality_id ?? '')}
                            displayValue={nationalityDisplay}
                            options={nationalityOptions}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            onChange={(value) => form.setData('nationality_id', value)}
                            error={form.errors.nationality_id}
                        />

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
