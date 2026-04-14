import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DepartmentOption } from '../types';

export type PositionFilters = {
    department_id: string;
    status: '' | 'active' | 'inactive';
    grade: string;
};

export function PositionFiltersSheet({
    open,
    onOpenChange,
    departments,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    departments: DepartmentOption[];
    value: PositionFilters;
    onChange: (next: PositionFilters) => void;
    onReset: () => void;
}) {
    const availableDepartments = departments ?? [];

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label htmlFor="filter-status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Status
                    </Label>
                    <select
                        id="filter-status"
                        value={value.status}
                        onChange={(e) => onChange({ ...value, status: e.target.value as PositionFilters['status'] })}
                        className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-dept" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Department
                </Label>
                <select
                    id="filter-dept"
                    value={value.department_id}
                    onChange={(e) => onChange({ ...value, department_id: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {availableDepartments.map((d) => (
                        <option key={d.id} value={String(d.id)}>
                            {d.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-grade" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Grade
                </Label>
                <Input
                    id="filter-grade"
                    placeholder="e.g. G5"
                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                    value={value.grade}
                    onChange={(e) => onChange({ ...value, grade: e.target.value })}
                />
            </div>
        </FiltersSheet>
    );
}

