import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type { Company, RoleOption } from '../types';

export type UserFilters = {
    company_id: string;
    role_id: string;
    status: '' | 'active' | 'inactive' | 'suspended';
};

export function UserFiltersSheet({
    open,
    onOpenChange,
    companies,
    roles,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companies: Company[];
    roles: RoleOption[];
    value: UserFilters;
    onChange: (next: UserFilters) => void;
    onReset: () => void;
}) {
    const availableRoles = (roles ?? []).filter((r) => (value.company_id ? String(r.company_id) === value.company_id : true));

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label htmlFor="filter-company" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Company
                    </Label>
                    <select
                        id="filter-company"
                        value={value.company_id}
                        onChange={(e) => onChange({ ...value, company_id: e.target.value, role_id: '' })}
                        className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        {companies.map((c) => (
                            <option key={c.id} value={String(c.id)}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="filter-status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Status
                    </Label>
                    <select
                        id="filter-status"
                        value={value.status}
                        onChange={(e) => onChange({ ...value, status: e.target.value as UserFilters['status'] })}
                        className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-role" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Role
                </Label>
                <select
                    id="filter-role"
                    value={value.role_id}
                    onChange={(e) => onChange({ ...value, role_id: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {availableRoles.map((r) => (
                        <option key={r.id} value={String(r.id)}>
                            {r.name}
                        </option>
                    ))}
                </select>
            </div>
        </FiltersSheet>
    );
}

