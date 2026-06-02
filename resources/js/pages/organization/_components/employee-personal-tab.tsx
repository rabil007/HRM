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
import { EmployeeWorkAssignmentsSection } from '@/pages/organization/_components/employee-work-assignments-section';
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

type WorkAssignmentOption = {
    id: number;
    name: string;
};

export type EmployeePersonalTabProps = {
    employee: EmployeeDetails;
    countries: CountryOption[];
    approvalLocations: WorkAssignmentOption[];
    sssaOptions: WorkAssignmentOption[];
    canUpdate: boolean;
    form: EmployeePersonalFormSlice & {
        data: EmployeePersonalFormSlice['data'] & {
            approval_location_ids?: number[];
            sssa_option_ids?: number[];
        };
    };
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
    /** null = no template, show all; string[] = only these field keys */
    templateProfileFields?: string[] | null;
    isMissingRequired?: (field: string) => boolean;
};

export function EmployeePersonalTab({
    employee,
    countries,
    approvalLocations,
    sssaOptions,
    canUpdate,
    form,
    activeField,
    setActiveField,
    beginEdit,
    templateProfileFields = null,
    isMissingRequired = () => false,
}: EmployeePersonalTabProps): ReactElement {
    const showField = (key: string): boolean =>
        !templateProfileFields || templateProfileFields.includes(key);
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

    const showPrivateContact =
        showField('personal_email') || showField('phone_home_country');
    const showEmergencyContact =
        showField('emergency_contact') || showField('emergency_phone');
    const showFamily = showField('marital_status') || showField('spouse_name');
    const showLocation = showField('nearest_airport') || showField('address');
    const showCitizenship =
        showField('nationality_id') ||
        showField('passport_number') ||
        showField('emirates_id') ||
        showField('labor_card_number');
    const showWorkAssignments =
        showField('approval_location_ids') || showField('sssa_option_ids');

    return (
        <TabsContent value="personal" className="mt-6 space-y-6">
            {showWorkAssignments ? (
                <EmployeeWorkAssignmentsSection
                    approvalLocations={approvalLocations}
                    sssaOptions={sssaOptions}
                    approvalLocationIds={form.data.approval_location_ids ?? []}
                    sssaOptionIds={form.data.sssa_option_ids ?? []}
                    canUpdate={canUpdate}
                    showApprovalLocations={showField('approval_location_ids')}
                    showSssaOptions={showField('sssa_option_ids')}
                    highlightMissingApprovalLocations={isMissingRequired(
                        'approval_location_ids',
                    )}
                    highlightMissingSssaOptions={isMissingRequired('sssa_option_ids')}
                    onApprovalLocationIdsChange={(ids) =>
                        form.setData('approval_location_ids', ids)
                    }
                    onSssaOptionIdsChange={(ids) => form.setData('sssa_option_ids', ids)}
                />
            ) : null}

            <div className="grid items-stretch gap-4 lg:grid-cols-3">
                {showPrivateContact ? (
                <EmployeeSectionCard
                    title="Private contact"
                    description="Personal email and home-country mobile"
                    icon={Mail}
                >
                    <div className="space-y-1">
                        {showField('personal_email') ? (
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
                            highlightMissing={isMissingRequired('personal_email')}
                        />
                        ) : null}

                        {showField('phone_home_country') ? (
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
                            highlightMissing={isMissingRequired('phone_home_country')}
                        />
                        ) : null}
                    </div>
                </EmployeeSectionCard>
                ) : null}

                {showEmergencyContact ? (
                <EmployeeSectionCard
                    title="Emergency contact"
                    description="Primary emergency contact"
                    icon={Phone}
                >
                    <div className="space-y-1">
                        {showField('emergency_contact') ? (
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
                            highlightMissing={isMissingRequired('emergency_contact')}
                        />
                        ) : null}
                        {showField('emergency_phone') ? (
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
                            highlightMissing={isMissingRequired('emergency_phone')}
                        />
                        ) : null}
                    </div>
                </EmployeeSectionCard>
                ) : null}

                {showFamily ? (
                <EmployeeSectionCard
                    title="Family"
                    description="Marital status and spouse"
                    icon={Users}
                >
                    <div className="space-y-1">
                        {showField('marital_status') ? (
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
                            highlightMissing={isMissingRequired('marital_status')}
                        />
                        ) : null}
                        {showField('spouse_name') ? (
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
                            highlightMissing={isMissingRequired('spouse_name')}
                        />
                        ) : null}
                    </div>
                </EmployeeSectionCard>
                ) : null}
            </div>

            {showLocation || showCitizenship ? (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-stretch">
            {showLocation ? (
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
                        ]
                            .filter((row) => showField(row.key))
                            .map((row) => (
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
                                highlightMissing={isMissingRequired(row.key)}
                            />
                        ))}
                </div>
            </EmployeeSectionCard>
            ) : null}

            {showCitizenship ? (
            <EmployeeSectionCard
                title="Citizenship"
                description="Identity documents and work permits"
                icon={Globe}
                bodyClassName="grid gap-1 lg:grid-cols-2 lg:gap-x-10"
            >
                {showField('nationality_id') ? (
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
                            highlightMissing={isMissingRequired('nationality_id')}
                        />
                ) : null}

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
                        ]
                            .filter((item) => showField(item.key))
                            .map((item) => (
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
                                highlightMissing={isMissingRequired(item.key)}
                            />
                        ))}
            </EmployeeSectionCard>
            ) : null}
            </div>
            ) : null}
        </TabsContent>
    );
}
