import { Link } from '@inertiajs/react';
import { Briefcase, Building2, Camera, ClipboardList, Loader2, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { resolveEmployeeImageUrl } from '@/features/organization/employees/lib/employee-avatar';
import { EditableCommandSelectCell } from '@/features/organization/employees/profile/components/editable-command-select-cell';
import { EditableDetailTextField } from '@/features/organization/employees/profile/components/editable-detail-field';
import { EditableDetailSelectField } from '@/features/organization/employees/profile/components/editable-detail-select-field';
import {
    EditableHeaderNameField,
    EditableHeaderPillTextField,
} from '@/features/organization/employees/profile/components/editable-header-fields';
import type { CountryOption } from '@/features/organization/employees/types';
import { useInitials } from '@/hooks/use-initials';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { EmployeeInlinePhoneField } from '@/pages/organization/_components/employee-inline-phone-field';
type Option = { id: number; name?: string | null; title?: string | null };

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
    departments,
    positions,
    managers,
    countries,
    genders,
    religions,
    visa_types = [],
    ranks,
    form,
    activeField,
    setActiveField,
    beginEdit,
    requiredDot,
    onPhotoSelect,
    isUploadingPhoto = false,
    templateProfileFields = null,
}: {
    canUpdate: boolean;
    employee: any;
    departments: Option[];
    positions: Option[];
    managers: any[];
    countries: CountryOption[];
    genders: Option[];
    religions: Option[];
    visa_types?: Option[];
    ranks: Option[];
    form: any;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactNode;
    onPhotoSelect?: (file: File) => void;
    isUploadingPhoto?: boolean;
    /** null = no template, show all; string[] = only show these field keys */
    templateProfileFields?: string[] | null;
}) {
    const getInitials = useInitials();

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

    const imageSrc = resolveEmployeeImageUrl(employee.image);
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

    const { sourceItems: departmentItems } = useMutableSelectOptions(departments);
    const { sourceItems: positionItems } = useMutableSelectOptions(positions, 'title');
    const { selectOptions: rankOptions } = useMutableSelectOptions(ranks);
    const { selectOptions: genderOptions } = useMutableSelectOptions(genders);
    const { selectOptions: religionOptions } = useMutableSelectOptions(religions);
    const { selectOptions: visaTypeOptions } = useMutableSelectOptions(visa_types);

    const positionCreatableContext = useMemo(
        () => ({
            departmentId: form.data.department_id || employee.department?.id || null,
        }),
        [employee.department?.id, form.data.department_id],
    );

    const statusBadge = useMemo(() => {
        const status = employee.status;

        if (status === 'inactive') {
            return {
                container: 'border-border bg-muted/50 text-muted-foreground',
                dot: 'bg-muted-foreground',
            };
        }

        if (status === 'on_leave') {
            return {
                container: 'border-warning/30 bg-warning/10 text-warning',
                dot: 'bg-warning',
            };
        }

        if (status === 'terminated') {
            return {
                container: 'border-destructive/30 bg-destructive/10 text-destructive',
                dot: 'bg-destructive',
            };
        }

        return {
            container: 'border-success/30 bg-success/10 text-success',
            dot: 'bg-success',
        };
    }, [employee.status]);

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/80 bg-card p-6 shadow-lg md:p-8">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_75%_55%_at_-5%_-10%,color-mix(in_oklch,var(--primary)_20%,transparent),transparent_55%),radial-gradient(ellipse_55%_55%_at_110%_110%,color-mix(in_oklch,var(--success)_12%,transparent),transparent_55%)]" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/50 to-transparent" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-48 bg-gradient-to-b from-primary/5 to-transparent" />

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
                    <div className="absolute -inset-3 rounded-2xl bg-gradient-to-br from-primary/25 via-accent/10 to-success/15 opacity-60 blur-xl" />
                    <button
                        type="button"
                        className={cn(
                            'group relative overflow-hidden rounded-2xl border border-border/80 shadow-xl ring-1 ring-border/50',
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
                        <EmployeeAvatar
                            name={displayName}
                            gradientSeed={employee.id ? undefined : 'new-employee'}
                            image={employee.image}
                            src={displayImageSrc}
                            size="lg"
                            className="rounded-2xl"
                        />
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
                        'absolute -bottom-1.5 -right-1.5 h-5 w-5 rounded-full border-[3px] border-card shadow-lg',
                        statusBadge.dot,
                    )} />
                </div>

                <div className="min-w-0 flex-1 text-center md:text-left">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between md:gap-6">
                        <div className="min-w-0 space-y-3">
                            <div className="inline-flex items-center gap-1.5 rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.2em] text-primary">
                                <UserRound className="h-2.5 w-2.5" />
                                Employee profile
                            </div>

                            <h1 className="truncate text-3xl font-black tracking-tight text-foreground md:text-4xl">
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
                                    <Badge className="mx-auto flex w-fit items-center gap-2 rounded-full border-border bg-muted/50 px-3 py-1 text-xs font-semibold text-muted-foreground md:mx-0">
                                        <Building2 className="h-3.5 w-3.5" />
                                        {employee.department.name}
                                    </Badge>
                                ) : null}
                                {employee.user ? (
                                    <Link
                                        href={`/organization/users/${employee.user.id}`}
                                        className="mx-auto flex w-fit md:mx-0"
                                        prefetch="click"
                                    >
                                        <Badge className="flex h-auto items-center gap-2 rounded-full border-emerald-500/30 bg-emerald-500/10 py-1 pe-3 ps-1 text-xs font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/15 dark:text-emerald-400">
                                            <Avatar className="size-6 rounded-full">
                                                {employee.user.avatar ? (
                                                    <AvatarImage
                                                        src={employee.user.avatar}
                                                        alt={employee.user.name ?? 'User'}
                                                    />
                                                ) : null}
                                                <AvatarFallback className="rounded-full text-[10px]">
                                                    {getInitials(employee.user.name ?? 'User')}
                                                </AvatarFallback>
                                            </Avatar>
                                            <span className="max-w-[12rem] truncate">
                                                {employee.user.name ?? 'User account'}
                                            </span>
                                        </Badge>
                                    </Link>
                                ) : null}
                            </div>

                            <div className="mx-auto grid max-w-xl grid-cols-2 gap-1.5 text-xs md:mx-0 md:max-w-none md:grid-cols-3">
                                {[
                                    {
                                        field: 'department_id',
                                        label: 'Department',
                                        current:
                                            departmentItems.find((d) => String(d.id) === String(form.data.department_id || employee.department?.id || ''))?.name ??
                                            employee.department?.name ??
                                            '—',
                                        items: departmentItems.map((d) => ({
                                            id: d.id,
                                            label: d.name ?? `#${d.id}`,
                                            value: String(d.id),
                                        })),
                                        creatableKey: 'department' as const,
                                        title: 'Select department',
                                        description: 'Search departments...',
                                    },
                                    {
                                        field: 'position_id',
                                        label: 'Position',
                                        current:
                                            positionItems.find((p) => String(p.id) === String(form.data.position_id || employee.position?.id || ''))?.title ??
                                            employee.position?.title ??
                                            '—',
                                        items: positionItems.map((p) => ({
                                            id: p.id,
                                            label: p.title ?? `#${p.id}`,
                                            value: String(p.id),
                                        })),
                                        creatableKey: 'position' as const,
                                        creatableContext: positionCreatableContext,
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
                                        creatableKey={'creatableKey' in item ? item.creatableKey : undefined}
                                        creatableContext={
                                            'creatableContext' in item ? item.creatableContext : undefined
                                        }
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
                            {employee.employee_profile_template?.name ? (
                                <Badge
                                    title={`Profile template: ${employee.employee_profile_template.name}`}
                                    className="flex max-w-[11rem] items-center gap-1.5 rounded-full border-violet-500/25 bg-violet-500/10 px-2.5 py-1 text-[10px] font-medium text-violet-300"
                                >
                                    <ClipboardList className="h-3 w-3 shrink-0" />
                                    <span className="truncate">{employee.employee_profile_template.name}</span>
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <div className="relative mt-6 overflow-hidden rounded-2xl border border-border/80 bg-muted/20 shadow-sm">
                <div className="border-b border-border/60 px-5 py-3">
                    <h2 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                        Details
                    </h2>
                </div>

                <div className="grid grid-cols-2 divide-x divide-y divide-border/50 md:grid-cols-4">
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
                        <div className="group px-4 py-4 transition-colors hover:bg-muted/30">
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
                                labelClassName="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
                            />
                        </div>
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
                            creatableKey="rank"
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
                            creatableKey="gender"
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
                            creatableKey="religion"
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('religion_id', value)}
                        />
                    )}

                    {showField('visa_type_id') && (
                        <EditableDetailSelectField
                            label="Visa type"
                            field="visa_type_id"
                            value={form.data.visa_type_id}
                            displayValue={optionLabel(
                                visa_types,
                                form.data.visa_type_id || employee.visa_type_id,
                                employee.visa_type_ref?.name,
                            )}
                            options={visaTypeOptions}
                            creatableKey="visaType"
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            canEdit={canUpdate}
                            onChange={(value) => form.setData('visa_type_id', value)}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

