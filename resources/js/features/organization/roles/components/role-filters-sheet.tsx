import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type { Company } from '../types';

export type RoleFilters = {
    company_id: string;
    is_system: '' | 'true' | 'false';
};

export function RoleFiltersSheet({
    open,
    onOpenChange,
    companies,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companies: Company[];
    value: RoleFilters;
    onChange: (next: RoleFilters) => void;
    onReset: () => void;
}) {
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
                        onChange={(e) => onChange({ ...value, company_id: e.target.value })}
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
                    <Label htmlFor="filter-system" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        System
                    </Label>
                    <select
                        id="filter-system"
                        value={value.is_system}
                        onChange={(e) => onChange({ ...value, is_system: e.target.value as RoleFilters['is_system'] })}
                        className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="true">System only</option>
                        <option value="false">Non-system only</option>
                    </select>
                </div>
            </div>
        </FiltersSheet>
    );
}

