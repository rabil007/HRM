import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Branch, DepartmentParentOption, Manager } from '../types';

export type DepartmentFilters = {
    branch_id: string;
    parent_id: string;
    manager_id: string;
    status: '' | 'active' | 'inactive';
    code: string;
};

export function DepartmentFiltersSheet({
    open,
    onOpenChange,
    branches,
    parents,
    managers,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
    value: DepartmentFilters;
    onChange: (next: DepartmentFilters) => void;
    onReset: () => void;
}) {
    const availableBranches = branches;
    const availableParents = parents;

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label htmlFor="filter-status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Status
                </Label>
                <select
                    id="filter-status"
                    value={value.status}
                    onChange={(e) => onChange({ ...value, status: e.target.value as DepartmentFilters['status'] })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-branch" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Branch
                </Label>
                <select
                    id="filter-branch"
                    value={value.branch_id}
                    onChange={(e) => onChange({ ...value, branch_id: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {availableBranches.map((branch) => (
                        <option key={branch.id} value={String(branch.id)}>
                            {branch.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-parent" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Parent
                </Label>
                <select
                    id="filter-parent"
                    value={value.parent_id}
                    onChange={(e) => onChange({ ...value, parent_id: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {availableParents.map((dept) => (
                        <option key={dept.id} value={String(dept.id)}>
                            {dept.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-manager" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Manager
                </Label>
                <select
                    id="filter-manager"
                    value={value.manager_id}
                    onChange={(e) => onChange({ ...value, manager_id: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {managers.map((m) => (
                        <option key={m.id} value={String(m.id)}>
                            {m.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="space-y-2">
                <Label htmlFor="filter-code" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Code
                </Label>
                <Input
                    id="filter-code"
                    placeholder="e.g. HR"
                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                    value={value.code}
                    onChange={(e) => onChange({ ...value, code: e.target.value })}
                />
            </div>
        </FiltersSheet>
    );
}

