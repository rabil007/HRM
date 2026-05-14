import { Briefcase, Building2, Mail, MapPin, Phone, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';

type Option = { id: number; name?: string | null; title?: string | null };

export function EmployeeHeaderCard({
    canUpdate,
    employee,
    branches,
    departments,
    positions,
    managers,
    genders,
    religions,
    form,
    activeField,
    setActiveField,
    beginEdit,
    requiredDot,
}: {
    canUpdate: boolean;
    employee: any;
    branches: Option[];
    departments: Option[];
    positions: Option[];
    managers: any[];
    genders: Option[];
    religions: Option[];
    form: any;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactNode;
}) {
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
        <div className="relative overflow-hidden rounded-4xl border border-white/10 bg-card/80 p-6 shadow-2xl shadow-black/20 backdrop-blur-xl md:p-7">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(99,102,241,0.18),transparent_32%),radial-gradient(circle_at_bottom_right,rgba(16,185,129,0.12),transparent_28%)]" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-linear-to-r from-transparent via-white/30 to-transparent" />

            <div className="relative flex flex-col gap-6 md:flex-row md:items-start md:gap-8">
                <div className="mx-auto shrink-0 md:mx-0">
                    <div className="h-28 w-28 overflow-hidden rounded-[1.75rem] border border-white/10 bg-black/20 shadow-2xl shadow-black/30 ring-1 ring-white/5 md:h-32 md:w-32 lg:h-36 lg:w-36">
                        {imageSrc ? (
                            <img
                                src={imageSrc}
                                alt={displayName}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex h-full w-full select-none items-center justify-center bg-linear-to-br from-primary/25 via-white/10 to-emerald-500/15 text-3xl font-bold leading-none text-white md:text-4xl">
                                {initials}
                            </div>
                        )}
                    </div>
                </div>

                <div className="min-w-0 flex-1 text-center md:text-left">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between md:gap-6">
                        <div className="min-w-0 space-y-3">
                            <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.22em] text-zinc-400">
                                <UserRound className="h-3 w-3" />
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

                            <div className="flex flex-wrap justify-center gap-2 text-xs text-zinc-400 md:justify-start">
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/5 bg-black/10 px-3 py-1.5">
                                    <Mail className="h-3.5 w-3.5" />
                                    {form.data.work_email || employee.work_email || 'No work email'}
                                </div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/5 bg-black/10 px-3 py-1.5">
                                    <Phone className="h-3.5 w-3.5" />
                                    {form.data.phone || employee.phone || 'No phone'}
                                </div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/5 bg-black/10 px-3 py-1.5">
                                    <MapPin className="h-3.5 w-3.5" />
                                    {employee.branch?.name || 'No branch'}
                                </div>
                            </div>

                            <div className="mx-auto grid max-w-xl grid-cols-1 gap-2 text-xs md:mx-0 md:max-w-none md:grid-cols-2">
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
                                        current: employee.manager?.name ?? '—',
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
                                        className="flex min-w-0 items-center justify-between gap-3 rounded-2xl border border-white/10 bg-black/10 px-3 py-2.5 shadow-inner shadow-black/10"
                                    >
                                        <div className="text-zinc-500">{item.label}</div>
                                        <button
                                            type="button"
                                            className="min-w-0 truncate text-right font-semibold text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
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

                        <div className="flex items-start justify-center md:justify-end">
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
                                        className="flex items-center gap-2 rounded-full border border-white/10 bg-black/20 px-3 py-1.5 text-[10px] font-bold tracking-wide text-zinc-400 hover:text-zinc-200 disabled:cursor-default disabled:hover:text-zinc-400"
                                        onClick={() => beginEdit('employee_no')}
                                        disabled={!canUpdate}
                                    >
                                        {form.data.employee_no || employee.employee_no}
                                    </button>
                                )}

                                <div
                                    className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide ${statusBadge.container}`}
                                >
                                    <div className={`h-2 w-2 animate-pulse rounded-full ${statusBadge.dot}`} />
                                    {employee.status}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="relative mt-6 rounded-3xl border border-white/10 bg-black/10 p-4 shadow-inner shadow-black/10 md:p-5">
                <div className="mb-4 flex items-center justify-between">
                    <div className="text-xs font-semibold tracking-wide text-zinc-400">
                        Details
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 md:gap-6">
                    <div className="space-y-2">
                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Work email
                                    {requiredDot('work_email')}
                                </label>
                                {activeField === 'work_email' && canUpdate ? (
                                    <Input
                                        className="h-10 rounded-xl border-white/10 bg-white/5 text-zinc-200"
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
                        </div>

                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Phone (UAE)
                                </label>
                                {activeField === 'phone' && canUpdate ? (
                                    <Input
                                        className="h-10 rounded-xl border-white/10 bg-white/5 text-zinc-200"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                        onBlur={() => setActiveField(null)}
                                        autoFocus
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        className="min-w-0 truncate text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                        onClick={() => beginEdit('phone')}
                                        disabled={!canUpdate}
                                    >
                                        {form.data.phone || employee.phone || '—'}
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Marital status
                                </label>
                                {activeField === 'marital_status' && canUpdate ? (
                                    <select
                                        className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
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
                        </div>

                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Birthday
                                </label>
                                {activeField === 'date_of_birth' && canUpdate ? (
                                    <Input
                                        type="date"
                                        className="h-10 rounded-xl border-white/10 bg-white/5 text-zinc-200"
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
                        </div>
                    </div>

                    <div className="space-y-2">
                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Place of Birth
                                </label>
                                {activeField === 'place_of_birth' && canUpdate ? (
                                    <Input
                                        className="h-10 rounded-xl border-white/10 bg-white/5 text-zinc-200"
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
                        </div>

                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Gender
                                </label>
                                {activeField === 'gender_id' && canUpdate ? (
                                    <select
                                        className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
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
                        </div>

                        <div className="group rounded-xl px-2 py-2 transition-colors hover:bg-white/5">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Religion
                                </label>
                                {activeField === 'religion_id' && canUpdate ? (
                                    <select
                                        className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
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
            </div>
        </div>
    );
}

