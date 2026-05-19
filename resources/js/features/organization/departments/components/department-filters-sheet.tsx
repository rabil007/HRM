import { AppSelect, AppSelectItem } from '@/components/app-select';
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
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Status
                </Label>
                <AppSelect
                    value={value.status}
                    onValueChange={(v) => onChange({ ...value, status: v as DepartmentFilters['status'] })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    <AppSelectItem value="active">Active</AppSelectItem>
                    <AppSelectItem value="inactive">Inactive</AppSelectItem>
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Branch
                </Label>
                <AppSelect
                    value={value.branch_id}
                    onValueChange={(v) => onChange({ ...value, branch_id: v })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {availableBranches.map((branch) => (
                        <AppSelectItem key={branch.id} value={String(branch.id)}>
                            {branch.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Parent
                </Label>
                <AppSelect
                    value={value.parent_id}
                    onValueChange={(v) => onChange({ ...value, parent_id: v })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {availableParents.map((dept) => (
                        <AppSelectItem key={dept.id} value={String(dept.id)}>
                            {dept.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Manager
                </Label>
                <AppSelect
                    value={value.manager_id}
                    onValueChange={(v) => onChange({ ...value, manager_id: v })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {managers.map((m) => (
                        <AppSelectItem key={m.id} value={String(m.id)}>
                            {m.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
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
