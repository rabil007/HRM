import { Briefcase } from 'lucide-react';
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
    requiredDot: (field: string) => JSX.Element | null;
}) {
    const displayName = useMemo(() => {
        return (
            `${form.data.first_name ?? ''} ${form.data.last_name ?? ''}`.trim() ||
            'Employee'
        );
    }, [form.data.first_name, form.data.last_name]);

    const initials = useMemo(() => {
        return (
            `${form.data.first_name?.[0] ?? ''}${form.data.last_name?.[0] ?? ''}`.toUpperCase() ||
            'E'
        );
    }, [form.data.first_name, form.data.last_name]);

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
        <div className="rounded-2xl border border-white/5 bg-white/5 p-6 shadow-[0_18px_40px_-28px_rgba(0,0,0,0.7)] md:p-7">
            <div className="grid grid-cols-1 items-start gap-6 md:grid-cols-[120px_1fr] md:gap-10">
                <div className="mx-auto shrink-0 md:mx-0">
                    <div className="h-28 w-28 overflow-hidden rounded-2xl border border-white/5 bg-zinc-900/70 shadow-[0_16px_32px_-12px_rgba(0,0,0,0.5)]">
                        {imageSrc ? (
                            <img
                                src={imageSrc}
                                alt={displayName}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex h-full w-full items-center justify-center bg-white/5 text-2xl font-semibold text-muted-foreground">
                                {initials}
                            </div>
                        )}
                    </div>
                </div>

                <div className="w-full space-y-5 text-center md:text-left">
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-[1fr_360px] md:items-start md:gap-6">
                        <div className="space-y-3">
                            <h1 className="text-2xl font-extrabold tracking-tight text-white md:text-3xl">
                                {activeField === 'name' && canUpdate ? (
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <Input
                                            className="h-10 rounded-xl border-white/10 bg-white/5 text-white"
                                            value={form.data.first_name}
                                            onChange={(e) => form.setData('first_name', e.target.value)}
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                            placeholder="First name"
                                        />
                                        <Input
                                            className="h-10 rounded-xl border-white/10 bg-white/5 text-white"
                                            value={form.data.last_name}
                                            onChange={(e) => form.setData('last_name', e.target.value)}
                                            onBlur={() => setActiveField(null)}
                                            placeholder="Last name"
                                        />
                                    </div>
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
                                <div
                                    className={`flex items-center gap-2 rounded-full border px-3 py-1 text-[10px] font-semibold tracking-wide ${statusBadge.container}`}
                                >
                                    <div className={`h-2 w-2 animate-pulse rounded-full ${statusBadge.dot}`} />
                                    {employee.status}
                                </div>

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
                                        className="flex items-center gap-2 rounded-full border border-white/5 bg-white/5 px-3 py-1 text-[10px] font-semibold tracking-wide text-zinc-400 hover:text-zinc-200 disabled:cursor-default disabled:hover:text-zinc-400"
                                        onClick={() => beginEdit('employee_no')}
                                        disabled={!canUpdate}
                                    >
                                        {form.data.employee_no || employee.employee_no}
                                    </button>
                                )}
                            </div>

                            <Badge className="mx-auto flex w-fit items-center gap-2 rounded-md border-primary/20 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary md:mx-0">
                                <Briefcase className="h-3.5 w-3.5" />
                                {employee.position?.title || '—'}
                            </Badge>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-white/5 bg-white/5 p-4 md:p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <div className="text-xs font-semibold tracking-wide text-zinc-400">
                                Details
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 md:gap-6">
                            <div className="space-y-3">
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
                                            label: `${m.first_name} ${m.last_name}`.trim() || `#${m.id}`,
                                            value: String(m.id),
                                            extra: m.employee_no,
                                            search: `${m.first_name} ${m.last_name} ${m.employee_no}`,
                                        })),
                                        title: 'Select manager',
                                        description: 'Search employees...',
                                    },
                                ].map((row) => (
                                    <div
                                        key={row.field}
                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[140px_1fr] sm:items-center sm:gap-4"
                                    >
                                        <label className="text-xs font-medium text-zinc-400">
                                            {row.label}
                                        </label>
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                            onClick={() => beginEdit(row.field)}
                                            disabled={!canUpdate}
                                        >
                                            {row.current}
                                        </button>

                                        <CommandDialog
                                            open={activeField === row.field && canUpdate}
                                            onOpenChange={(open) => {
                                                if (!open) {
                                                    setActiveField(null);
                                                }
                                            }}
                                            title={row.title}
                                            description={row.description}
                                        >
                                            <CommandInput placeholder={row.description} />
                                            <CommandList>
                                                <CommandEmpty>No results found.</CommandEmpty>
                                                <CommandItem
                                                    value="__none__"
                                                    onSelect={() => {
                                                        form.setData(row.field, '');
                                                        setActiveField(null);
                                                    }}
                                                >
                                                    —
                                                </CommandItem>
                                                {row.items.map((item: any) => (
                                                    <CommandItem
                                                        key={item.id}
                                                        value={item.search ?? item.label}
                                                        onSelect={() => {
                                                            form.setData(row.field, item.value);
                                                            setActiveField(null);
                                                        }}
                                                    >
                                                        {item.label}
                                                        {item.extra ? (
                                                            <span className="ml-auto text-xs text-muted-foreground">
                                                                {item.extra}
                                                            </span>
                                                        ) : null}
                                                    </CommandItem>
                                                ))}
                                            </CommandList>
                                        </CommandDialog>
                                    </div>
                                ))}

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
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                            onClick={() => beginEdit('work_email')}
                                            disabled={!canUpdate}
                                        >
                                            {form.data.work_email || employee.work_email || '—'}
                                        </button>
                                    )}
                                </div>

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
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                            onClick={() => beginEdit('phone')}
                                            disabled={!canUpdate}
                                        >
                                            {form.data.phone || employee.phone || '—'}
                                        </button>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-3">
                                <div className="text-[11px] font-semibold tracking-wide text-zinc-500">
                                    Personal
                                </div>
                                <div className="space-y-3">
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
                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                                onClick={() => beginEdit('marital_status')}
                                                disabled={!canUpdate}
                                            >
                                                {form.data.marital_status || employee.marital_status || '—'}
                                            </button>
                                        )}
                                    </div>

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
                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                                onClick={() => beginEdit('date_of_birth')}
                                                disabled={!canUpdate}
                                            >
                                                {form.data.date_of_birth || employee.date_of_birth || '—'}
                                            </button>
                                        )}
                                    </div>

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
                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                                onClick={() => beginEdit('place_of_birth')}
                                                disabled={!canUpdate}
                                            >
                                                {form.data.place_of_birth || employee.place_of_birth || '—'}
                                            </button>
                                        )}
                                    </div>

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
                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
                                                onClick={() => beginEdit('gender_id')}
                                                disabled={!canUpdate}
                                            >
                                                {genders.find((g) => String(g.id) === String(form.data.gender_id || employee.gender_id || ''))?.name ??
                                                    '—'}
                                            </button>
                                        )}
                                    </div>

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
                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white disabled:cursor-default disabled:hover:text-zinc-200"
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
            </div>
        </div>
    );
}

