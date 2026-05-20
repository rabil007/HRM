import { Briefcase, Building2, Camera, ClipboardList, Loader2, Mail, MapPin, Phone, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmployeeProfileNavigation } from '@/components/employee-profile-navigation';
import { Badge } from '@/components/ui/badge';
import type { CountryOption } from '@/features/organization/employees/types';
import { formatDisplayDate } from '@/lib/format-date';
import { formatPhoneForDisplay } from '@/lib/phone-with-dial-code';
import { cn } from '@/lib/utils';
import { EditableCommandSelectCell } from '@/features/organization/employees/profile/components/editable-command-select-cell';
import { EditableDetailTextField } from '@/features/organization/employees/profile/components/editable-detail-field';
import { EditableDetailSelectField } from '@/features/organization/employees/profile/components/editable-detail-select-field';
import {
    EditableHeaderNameField,
    EditableHeaderPillTextField,
} from '@/features/organization/employees/profile/components/editable-header-fields';
import { EmployeeInlinePhoneField } from '@/pages/organization/_components/employee-inline-phone-field';
import type { EmployeeNavigation } from '@/pages/organization/employee-page.types';

type Option = { id: number; name?: string | null; title?: string | null };

const MARITAL_STATUS_OPTIONS = [
    { id: 1, label: 'Single', value: 'single' },
    { id: 2, label: 'Married', value: 'married' },
    { id: 3, label: 'Divorced', value: 'divorced' },
    { id: 4, label: 'Widowed', value: 'widowed' },
] as const;

function optionLabel(
    options: Option[],
    id: string | number | null | undefined,
    fallback?: string | null,
): string {
    const found = options.find((option) => String(option.id) === String(id ?? ''));

    return found?.name ?? fallback ?? '—';
}

export function EmployeeHeaderCard({
    canUpdate,
    employee,
    branches,
    departments,
    positions,
    managers,
    countries,
    genders,
    religions,
    ranks,
    form,
    activeField,
    setActiveField,
    beginEdit,
    requiredDot,
    onPhotoSelect,
    isUploadingPhoto = false,
    employeeNavigation = null,
    onNavigateEmployee,
    templateProfileFields = null,
}: {
    canUpdate: boolean;
    employee: any;
    branches: Option[];
    departments: Option[];
    positions: Option[];
    managers: any[];
    countries: CountryOption[];
    genders: Option[];
    religions: Option[];
    ranks: Option[];
    form: any;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactNode;
    onPhotoSelect?: (file: File) => void;
    isUploadingPhoto?: boolean;
    employeeNavigation?: EmployeeNavigation | null;
    onNavigateEmployee?: (employeeId: number) => void;
    /** null = no template, show all; string[] = only show these field keys */
    templateProfileFields?: string[] | null;
}) {
    const showField = (key: string) =>
        !templateProfileFields || templateProfileFields.includes(key);

    const photoInputRef = useRef<HTMLInputElement>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [photoPreviewForImage, setPhotoPreviewForImage] = useState<string | null>(
        null,
    );

    const serverImageKey = employee.image ?? null;

    const showOptimisticPreview =
        photoPreview !== null && photoPreviewForImage === serverImageKey;

    useEffect(() => {
        if (
            !photoPreview?.startsWith('blob:') ||
            photoPreviewForImage === null ||
            photoPreviewForImage === serverImageKey
        ) {
            return;
        }

        URL.revokeObjectURL(photoPreview);
    }, [photoPreview, photoPreviewForImage, serverImageKey]);

    useEffect(() => {
        return () => {
            if (photoPreview?.startsWith('blob:')) {
                URL.revokeObjectURL(photoPreview);
            }
        };
    }, [photoPreview]);

    const displayName = useMemo(() => {
        return String(form.data.name ?? '').trim() || 'Employee';
    }, [form.data.name]);

    const initials = useMemo(() => {
        return (
            String(form.data.name ?? '')
                .split(' ')
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part[0])
                .join('')
                .toUpperCase() ||
            'E'
        );
    }, [form.data.name]);

    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const displayImageSrc = showOptimisticPreview ? photoPreview : imageSrc;

    const handlePhotoChange = (file: File | undefined) => {
        if (!file || !onPhotoSelect) {
            return;
        }

        if (!file.type.startsWith('image/')) {
            return;
        }

        if (photoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(photoPreview);
        }

        setPhotoPreview(URL.createObjectURL(file));
        setPhotoPreviewForImage(serverImageKey);
        onPhotoSelect(file);
    };

    const displayPhone = useMemo(() => {
        const formatted = formatPhoneForDisplay(
            form.data.phone || employee.phone,
            {
                countries,
                fieldKey: 'phone',
                defaultDialCode: '+971',
            },
        );

        return formatted === '—' ? 'No mobile' : formatted;
    }, [countries, employee.phone, form.data.phone]);

    const rankOptions = useMemo(
        () =>
            ranks.map((rank) => ({
                id: rank.id,
                label: rank.name ?? `#${rank.id}`,
                value: String(rank.id),
            })),
        [ranks],
    );

    const genderOptions = useMemo(
        () =>
            genders.map((gender) => ({
                id: gender.id,
                label: gender.name ?? `#${gender.id}`,
                value: String(gender.id),
            })),
        [genders],
    );

    const religionOptions = useMemo(
        () =>
            religions.map((religion) => ({
                id: religion.id,
                label: religion.name ?? `#${religion.id}`,
                value: String(religion.id),
            })),
        [religions],
    );

    const statusBadge = useMemo(() => {
        const status = employee.status;

        if (status === 'inactive') {
            return {
                container: 'border-zinc-500/20 bg-zinc-500/10 text-zinc-300',
                dot: 'bg-zinc-400',
            };
        }

        if (status === 'on_leave') {
            return {
                container: 'border-amber-500/20 bg-amber-500/10 text-amber-300',
                dot: 'bg-amber-400',
            };
        }

        if (status === 'terminated') {
            return {
                container: 'border-rose-500/20 bg-rose-500/10 text-rose-400',
                dot: 'bg-rose-500',
            };
        }

        return {
            container: 'border-emerald-500/20 bg-emerald-500/10 text-emerald-400',
            dot: 'bg-emerald-400',
        };
    }, [employee.status]);

    return (
        <div className="relative overflow-hidden rounded-[2rem] border border-white/[0.08] bg-zinc-950 p-6 shadow-[0_24px_48px_-8px_rgba(0,0,0,0.6),0_0_0_1px_rgba(255,255,255,0.04)] md:p-8">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_75%_55%_at_-5%_-10%,rgba(99,102,241,0.22),transparent_55%),radial-gradient(ellipse_55%_55%_at_110%_110%,rgba(16,185,129,0.13),transparent_55%)]" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-400/60 to-transparent" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-48 bg-gradient-to-b from-indigo-500/[0.06] to-transparent" />

            <div className="relative flex flex-col gap-6 md:flex-row md:items-start md:gap-8">
                <div className="relative mx-auto shrink-0 md:mx-0">
                    <input
                        ref={photoInputRef}
                        type="file"
                        accept="image/*"
                        className="sr-only"
                        disabled={!canUpdate || isUploadingPhoto}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            handlePhotoChange(file);
                            event.target.value = '';
                        }}
                    />
                    {/* Glow halo behind avatar */}
                    <div className="absolute -inset-3 rounded-[2rem] bg-gradient-to-br from-indigo-500/30 via-violet-500/10 to-emerald-500/20 opacity-60 blur-xl" />
                    <button
                        type="button"
                        className={cn(
                            'group relative h-28 w-28 overflow-hidden rounded-[1.75rem] border border-white/[0.12] bg-zinc-900 shadow-2xl shadow-black/50 ring-1 ring-white/[0.06] md:h-32 md:w-32 lg:h-36 lg:w-36',
                            canUpdate ? 'cursor-pointer' : 'cursor-default',
                        )}
                        onClick={() => {
                            if (canUpdate && !isUploadingPhoto) {
                                photoInputRef.current?.click();
                            }
                        }}
                        disabled={!canUpdate || isUploadingPhoto}
                        aria-label={canUpdate ? 'Change employee photo' : 'Employee photo'}
                    >
                        {displayImageSrc ? (
                            <img
                                src={displayImageSrc}
                                alt={displayName}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex h-full w-full select-none items-center justify-center bg-gradient-to-br from-indigo-500/30 via-violet-500/20 to-emerald-500/20 text-3xl font-black leading-none text-white md:text-4xl lg:text-5xl">
                                {initials}
                            </div>
                        )}
                        {canUpdate ? (
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/60 opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100">
                                {isUploadingPhoto ? (
                                    <Loader2 className="h-6 w-6 animate-spin text-white" />
                                ) : (
                                    <>
                                        <Camera className="h-5 w-5 text-white" />
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-white/90">
                                            {displayImageSrc ? 'Change' : 'Upload'}
                                        </span>
                                    </>
                                )}
                            </div>
                        ) : null}
                    </button>
                    {/* Live status dot */}
                    <div className={cn(
                        'absolute -bottom-1.5 -right-1.5 h-5 w-5 rounded-full border-[3px] border-zinc-950 shadow-lg',
                        statusBadge.dot,
                    )} />
                </div>

                <div className="min-w-0 flex-1 text-center md:text-left">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between md:gap-6">
                        <div className="min-w-0 space-y-3">
                            <div className="inline-flex items-center gap-1.5 rounded-full border border-indigo-400/25 bg-indigo-400/[0.08] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.2em] text-indigo-300">
                                <UserRound className="h-2.5 w-2.5" />
                                Employee profile
                            </div>

                            <h1 className="truncate text-3xl font-black tracking-tight text-white md:text-4xl">
                                <EditableHeaderNameField
                                    field="name"
                                    value={form.data.name}
                                    displayValue={displayName}
                                    activeField={activeField}
                                    setActiveField={setActiveField}
                                    beginEdit={beginEdit}
                                    canEdit={canUpdate}
                                    onChange={(value) => form.setData('name', value)}
                                />
                            </h1>

                            <div className="flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                {employee.position?.title ? (
                                    <Badge className="mx-auto flex w-fit items-center gap-2 rounded-full border-primary/20 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary md:mx-0">
                                        <Briefcase className="h-3.5 w-3.5" />
                                        {employee.position.title}
                                    </Badge>
                                ) : null}
                                {employee.department?.name ? (
                                    <Badge className="mx-auto flex w-fit items-center gap-2 rounded-full border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-zinc-300 md:mx-0">
                                        <Building2 className="h-3.5 w-3.5" />
                                        {employee.department.name}
                                    </Badge>
                                ) : null}
                            </div>

                            <div className="flex flex-wrap justify-center gap-1.5 text-xs text-zinc-400 md:justify-start">
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/[0.07] bg-white/[0.04] px-3 py-1.5 transition-colors hover:border-white/[0.12] hover:text-zinc-300">
                                    <Mail className="h-3 w-3 text-indigo-400" />
                                    {form.data.work_email || employee.work_email || 'No work email'}
                                </div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/[0.07] bg-white/[0.04] px-3 py-1.5 transition-colors hover:border-white/[0.12] hover:text-zinc-300">
                                    <Phone className="h-3 w-3 text-indigo-400" />
                                    {displayPhone}
                                </div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/[0.07] bg-white/[0.04] px-3 py-1.5 transition-colors hover:border-white/[0.12] hover:text-zinc-300">
                                    <MapPin className="h-3 w-3 text-indigo-400" />
                                    {employee.branch?.name || 'No branch'}
                                </div>
                            </div>

                            <div className="mx-auto grid max-w-xl grid-cols-2 gap-1.5 text-xs md:mx-0 md:max-w-none md:grid-cols-4">
                                {[
                                    {
                                        field: 'branch_id',
                                        label: 'Branch',
                                        current:
                                            branches.find((b) => String(b.id) === String(form.data.branch_id || employee.branch?.id || ''))?.name ??
                                            employee.branch?.name ??
                                            '—',
                                        items: branches.map((b) => ({ id: b.id, label: b.name ?? `#${b.id}`, value: String(b.id) })),
                                        title: 'Select branch',
                                        description: 'Search branches...',
                                    },
                                    {
                                        field: 'department_id',
                                        label: 'Department',
                                        current:
                                            departments.find((d) => String(d.id) === String(form.data.department_id || employee.department?.id || ''))?.name ??
                                            employee.department?.name ??
                                            '—',
                                        items: departments.map((d) => ({ id: d.id, label: d.name ?? `#${d.id}`, value: String(d.id) })),
                                        title: 'Select department',
                                        description: 'Search departments...',
                                    },
                                    {
                                        field: 'position_id',
                                        label: 'Position',
                                        current:
                                            positions.find((p) => String(p.id) === String(form.data.position_id || employee.position?.id || ''))?.title ??
                                            employee.position?.title ??
                                            '—',
                                        items: positions.map((p) => ({ id: p.id, label: p.title ?? `#${p.id}`, value: String(p.id) })),
                                        title: 'Select position',
                                        description: 'Search positions...',
                                    },
                                    {
                                        field: 'manager_id',
                                        label: 'Manager',
                                        current:
                                            managers.find((m) => String(m.id) === String(form.data.manager_id || employee.manager?.id || ''))?.name ??
                                            employee.manager?.name ??
                                            '—',
                                        items: managers.map((m) => ({
                                            id: m.id,
                                            label: m.name || `#${m.id}`,
                                            value: String(m.id),
                                            extra: m.employee_no,
                                            search: `${m.name} ${m.employee_no}`,
                                        })),
                                        title: 'Select manager',
                                        description: 'Search employees...',
                                    },
                                ].map((item) => (
                                    <EditableCommandSelectCell
                                        key={item.field}
                                        field={item.field}
                                        label={item.label}
                                        currentLabel={item.current || '—'}
                                        title={item.title}
                                        description={item.description}
                                        items={item.items}
                                        activeField={activeField}
                                        setActiveField={setActiveField}
                                        beginEdit={beginEdit}
                                        canEdit={canUpdate}
                                        onSelect={(value) => form.setData(item.field, value)}
                                    />
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-col items-center gap-2 md:items-end">
                            {employeeNavigation ? (
                                <EmployeeProfileNavigation
                                    navigation={employeeNavigation}
                                    onNavigate={onNavigateEmployee}
                                />
                            ) : null}
                            <div className="flex flex-wrap items-center justify-center gap-2 md:justify-end">
                                <EditableHeaderPillTextField
                                    field="employee_no"
                                    value={form.data.employee_no}
                                    displayValue={
                                        form.data.employee_no || employee.employee_no
                                    }
                                    activeField={activeField}
                                    setActiveField={setActiveField}
                                    beginEdit={beginEdit}
                                    canEdit={canUpdate}
                                    onChange={(value) => form.setData('employee_no', value)}
                                />

                                <div
                                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide ${statusBadge.container}`}
                                >
                                    <div className={`h-1.5 w-1.5 animate-pulse rounded-full ${statusBadge.dot}`} />
                                    {employee.status?.replace('_', ' ')}
                                </div>
                            </div>
                            {employee.onboarding_template?.name ? (
                                <Badge
                                    title={`Onboarding template: ${employee.onboarding_template.name}`}
                                    className="flex max-w-[11rem] items-center gap-1.5 rounded-full border-violet-500/25 bg-violet-500/10 px-2.5 py-1 text-[10px] font-medium text-violet-300"
                                >
                                    <ClipboardList className="h-3 w-3 shrink-0" />
                                    <span className="truncate">{employee.onboarding_template.name}</span>
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <div className="relative mt-6 overflow-hidden rounded-3xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-transparent shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]">
                <div className="border-b border-white/[0.06] px-5 py-3">
                    <h2 className="text-xs font-semibold uppercase tracking-widest text-zinc-500">
                        Details
                    </h2>
                </div>

                <div className="grid grid-cols-2 divide-x divide-y divide-white/[0.05] md:grid-cols-4">
                    {/* Work email */}
                    {showField('work_email') && (
                        <EditableDetailTextField
                            label={
                                <>
                                    Work email {requiredDot('work_email')}
                                </>
                            }
                            field="work_email"
                            value={form.data.work_email}
                            displayValue={form.data.work_email || employee.work_email || '—'}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('work_email', value)}
                            inputType="email"
                        />
                    )}

                    {/* Mobile (UAE) */}
                    {showField('phone') && (
                        <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                            <EmployeeInlinePhoneField
                                fieldKey="phone"
                                label="Mobile (UAE)"
                                value={form.data.phone ?? ''}
                                fallbackValue={employee.phone}
                                countries={countries}
                                activeField={activeField}
                                setActiveField={setActiveField}
                                beginEdit={beginEdit}
                                onChange={(next) => form.setData('phone', next)}
                                error={form.errors.phone}
                                defaultDialCode="+971"
                                canEdit={canUpdate}
                                rowClassName="flex flex-col gap-1.5"
                                labelClassName="text-[10px] font-semibold uppercase tracking-wider text-zinc-500"
                            />
                        </div>
                    )}

                    {/* Marital status */}
                    {showField('marital_status') && (
                        <EditableDetailSelectField
                            label="Marital status"
                            field="marital_status"
                            value={form.data.marital_status}
                            displayValue={
                                MARITAL_STATUS_OPTIONS.find(
                                    (option) =>
                                        option.value ===
                                        (form.data.marital_status || employee.marital_status),
                                )?.label ??
                                (form.data.marital_status ||
                                    employee.marital_status ||
                                    '—')
                            }
                            options={[...MARITAL_STATUS_OPTIONS]}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('marital_status', value)}
                        />
                    )}

                    {/* Birthday */}
                    {showField('date_of_birth') && (
                        <EditableDetailTextField
                            label="Birthday"
                            field="date_of_birth"
                            value={form.data.date_of_birth}
                            displayValue={formatDisplayDate(
                                form.data.date_of_birth || employee.date_of_birth,
                            )}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('date_of_birth', value)}
                            inputType="date"
                        />
                    )}

                    {/* Rank */}
                    {showField('rank_id') && (
                        <EditableDetailSelectField
                            label="Rank"
                            field="rank_id"
                            value={form.data.rank_id}
                            displayValue={optionLabel(
                                ranks,
                                form.data.rank_id || employee.rank_id,
                                employee.rank?.name,
                            )}
                            options={rankOptions}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('rank_id', value)}
                        />
                    )}

                    {showField('place_of_birth') && (
                        <EditableDetailTextField
                            label="Place of Birth"
                            field="place_of_birth"
                            value={form.data.place_of_birth}
                            displayValue={
                                form.data.place_of_birth || employee.place_of_birth || '—'
                            }
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('place_of_birth', value)}
                        />
                    )}

                    {showField('gender_id') && (
                        <EditableDetailSelectField
                            label="Gender"
                            field="gender_id"
                            value={form.data.gender_id}
                            displayValue={optionLabel(
                                genders,
                                form.data.gender_id || employee.gender_id,
                            )}
                            options={genderOptions}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('gender_id', value)}
                        />
                    )}

                    {showField('religion_id') && (
                        <EditableDetailSelectField
                            label="Religion"
                            field="religion_id"
                            value={form.data.religion_id}
                            displayValue={optionLabel(
                                religions,
                                form.data.religion_id || employee.religion_id,
                            )}
                            options={religionOptions}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('religion_id', value)}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

