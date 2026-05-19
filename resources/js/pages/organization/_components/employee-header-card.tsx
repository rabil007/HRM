import { Briefcase, Building2, Camera, Loader2, Mail, MapPin, Phone, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmployeeProfileNavigation } from '@/components/employee-profile-navigation';
import { Badge } from '@/components/ui/badge';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import type { CountryOption } from '@/features/organization/employees/types';
import { formatPhoneForDisplay } from '@/lib/phone-with-dial-code';
import { cn } from '@/lib/utils';
import { EmployeeInlinePhoneField } from '@/pages/organization/_components/employee-inline-phone-field';
import type { EmployeeNavigation } from '@/pages/organization/employee-page.types';

type Option = { id: number; name?: string | null; title?: string | null };

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
}) {
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

        return formatted === '—' ? 'No phone' : formatted;
    }, [countries, employee.phone, form.data.phone]);

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
                                {activeField === 'name' && canUpdate ? (
                                    <Input
                                        className="h-10 rounded-xl border-white/10 bg-white/5 text-white"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                        placeholder="Name"
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left hover:text-white disabled:cursor-default disabled:opacity-100"
                                        onClick={() => beginEdit('name')}
                                        disabled={!canUpdate}
                                    >
                                        {displayName}
                                    </button>
                                )}
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
                                     <div
                                         key={item.field}
                                         className="group flex min-w-0 flex-col gap-1 rounded-xl border border-white/[0.07] bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-white/[0.12] hover:bg-white/[0.06]"
                                     >
                                         <div className="text-[10px] font-semibold uppercase tracking-wider text-zinc-600">{item.label}</div>
                                         <button
                                             type="button"
                                             className="min-w-0 truncate text-left text-xs font-semibold text-zinc-300 hover:text-white disabled:cursor-default disabled:hover:text-zinc-300"
                                             onClick={() => beginEdit(item.field)}
                                             disabled={!canUpdate}
                                         >
                                             {item.current || '—'}
                                         </button>

                                        <CommandDialog
                                            open={activeField === item.field && canUpdate}
                                            onOpenChange={(open) => {
                                                if (!open) {
                                                    setActiveField(null);
                                                }
                                            }}
                                            title={item.title}
                                            description={item.description}
                                        >
                                            <CommandInput placeholder={item.description} />
                                            <CommandList>
                                                <CommandEmpty>No results found.</CommandEmpty>
                                                <CommandItem
                                                    value="__none__"
                                                    onSelect={() => {
                                                        form.setData(item.field, '');
                                                        setActiveField(null);
                                                    }}
                                                >
                                                    —
                                                </CommandItem>
                                                {item.items.map((row: any) => (
                                                    <CommandItem
                                                        key={row.id}
                                                        value={row.search ?? row.label}
                                                        onSelect={() => {
                                                            form.setData(item.field, row.value);
                                                            setActiveField(null);
                                                        }}
                                                    >
                                                        {row.label}
                                                        {row.extra ? (
                                                            <span className="ml-auto text-xs text-muted-foreground">
                                                                {row.extra}
                                                            </span>
                                                        ) : null}
                                                    </CommandItem>
                                                ))}
                                            </CommandList>
                                        </CommandDialog>
                                    </div>
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
                                {activeField === 'employee_no' && canUpdate ? (
                                    <Input
                                        className="h-8 w-[120px] rounded-full border-white/10 bg-white/5 px-3 text-[10px] font-semibold tracking-wide text-zinc-200"
                                        value={form.data.employee_no}
                                        onChange={(e) => form.setData('employee_no', e.target.value)}
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 rounded-full border border-white/[0.08] bg-white/[0.04] px-3 py-1.5 text-[10px] font-bold tracking-widest text-zinc-400 transition-colors hover:border-white/[0.14] hover:text-zinc-200 disabled:cursor-default disabled:hover:text-zinc-400"
                                        onClick={() => beginEdit('employee_no')}
                                        disabled={!canUpdate}
                                    >
                                        {form.data.employee_no || employee.employee_no}
                                    </button>
                                )}

                                <div
                                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide ${statusBadge.container}`}
                                >
                                    <div className={`h-1.5 w-1.5 animate-pulse rounded-full ${statusBadge.dot}`} />
                                    {employee.status?.replace('_', ' ')}
                                </div>
                            </div>
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
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Work email {requiredDot('work_email')}
                        </div>
                        {activeField === 'work_email' && canUpdate ? (
                            <Input
                                className="h-8 rounded-lg border-white/10 bg-white/5 text-zinc-200"
                                value={form.data.work_email}
                                onChange={(e) => form.setData('work_email', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            />
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('work_email')}
                                disabled={!canUpdate}
                            >
                                {form.data.work_email || employee.work_email || '—'}
                            </button>
                        )}
                    </div>

                    {/* Phone (UAE) */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <EmployeeInlinePhoneField
                            fieldKey="phone"
                            label="Phone (UAE)"
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

                    {/* Marital status */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Marital status
                        </div>
                        {activeField === 'marital_status' && canUpdate ? (
                            <select
                                className="h-8 w-full rounded-lg border border-white/10 bg-white/5 px-2 text-sm text-zinc-200 outline-none"
                                value={form.data.marital_status}
                                onChange={(e) => form.setData('marital_status', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            >
                                <option value="">—</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('marital_status')}
                                disabled={!canUpdate}
                            >
                                {form.data.marital_status || employee.marital_status || '—'}
                            </button>
                        )}
                    </div>

                    {/* Birthday */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Birthday
                        </div>
                        {activeField === 'date_of_birth' && canUpdate ? (
                            <Input
                                type="date"
                                className="h-8 rounded-lg border-white/10 bg-white/5 text-zinc-200"
                                value={form.data.date_of_birth}
                                onChange={(e) => form.setData('date_of_birth', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            />
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('date_of_birth')}
                                disabled={!canUpdate}
                            >
                                {form.data.date_of_birth || employee.date_of_birth || '—'}
                            </button>
                        )}
                    </div>

                    {/* Rank */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Rank
                        </div>
                        {activeField === 'rank_id' && canUpdate ? (
                            <select
                                className="h-8 w-full rounded-lg border border-white/10 bg-white/5 px-2 text-sm text-zinc-200 outline-none"
                                value={form.data.rank_id}
                                onChange={(e) => form.setData('rank_id', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            >
                                <option value="">—</option>
                                {ranks.map((r) => (
                                    <option key={r.id} value={String(r.id)}>
                                        {r.name ?? `#${r.id}`}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('rank_id')}
                                disabled={!canUpdate}
                            >
                                {ranks.find((r) => String(r.id) === String(form.data.rank_id || employee.rank_id || ''))?.name ??
                                    employee.rank?.name ??
                                    '—'}
                            </button>
                        )}
                    </div>

                    {/* Place of Birth */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Place of Birth
                        </div>
                        {activeField === 'place_of_birth' && canUpdate ? (
                            <Input
                                className="h-8 rounded-lg border-white/10 bg-white/5 text-zinc-200"
                                value={form.data.place_of_birth}
                                onChange={(e) => form.setData('place_of_birth', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            />
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('place_of_birth')}
                                disabled={!canUpdate}
                            >
                                {form.data.place_of_birth || employee.place_of_birth || '—'}
                            </button>
                        )}
                    </div>

                    {/* Gender */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Gender
                        </div>
                        {activeField === 'gender_id' && canUpdate ? (
                            <select
                                className="h-8 w-full rounded-lg border border-white/10 bg-white/5 px-2 text-sm text-zinc-200 outline-none"
                                value={form.data.gender_id}
                                onChange={(e) => form.setData('gender_id', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            >
                                <option value="">—</option>
                                {genders.map((g) => (
                                    <option key={g.id} value={String(g.id)}>
                                        {g.name ?? `#${g.id}`}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('gender_id')}
                                disabled={!canUpdate}
                            >
                                {genders.find((g) => String(g.id) === String(form.data.gender_id || employee.gender_id || ''))?.name ??
                                    '—'}
                            </button>
                        )}
                    </div>

                    {/* Religion */}
                    <div className="group px-4 py-4 transition-colors hover:bg-white/[0.03]">
                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                            Religion
                        </div>
                        {activeField === 'religion_id' && canUpdate ? (
                            <select
                                className="h-8 w-full rounded-lg border border-white/10 bg-white/5 px-2 text-sm text-zinc-200 outline-none"
                                value={form.data.religion_id}
                                onChange={(e) => form.setData('religion_id', e.target.value)}
                                onBlur={() => setActiveField(null)}
                                autoFocus
                            >
                                <option value="">—</option>
                                {religions.map((r) => (
                                    <option key={r.id} value={String(r.id)}>
                                        {r.name ?? `#${r.id}`}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <button
                                type="button"
                                className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                onClick={() => beginEdit('religion_id')}
                                disabled={!canUpdate}
                            >
                                {religions.find((r) => String(r.id) === String(form.data.religion_id || employee.religion_id || ''))?.name ??
                                    '—'}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

