import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type { BranchOption, DepartmentOption, PositionOption } from '../types';

export type EmployeeFilters = {
    branch_id: string;
    department_id: string;
    position_id: string;
    status: string;
};

export function EmployeeFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
    branches,
    departments,
    positions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: EmployeeFilters;
    onChange: (next: EmployeeFilters) => void;
    onReset: () => void;
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
}) {
    const filteredPositions = value.department_id
        ? positions.filter((p) => String(p.department_id ?? '') === value.department_id)
        : positions;

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label
                        htmlFor="filter-branch"
                        className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                    >
                        Branch
                    </Label>
                    <select
                        id="filter-branch"
                        value={value.branch_id}
                        onChange={(e) => onChange({ ...value, branch_id: e.target.value })}
                        className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        {branches.map((b) => (
                            <option key={b.id} value={String(b.id)}>
                                {b.name ?? `#${b.id}`}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="filter-department"
                        className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                    >
                        Department
                    </Label>
                    <select
                        id="filter-department"
                        value={value.department_id}
                        onChange={(e) =>
                            onChange({
                                ...value,
                                department_id: e.target.value,
                                position_id: value.position_id && e.target.value ? value.position_id : '',
                            })
                        }
                        className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        {departments.map((d) => (
                            <option key={d.id} value={String(d.id)}>
                                {d.name ?? `#${d.id}`}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="filter-position"
                        className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                    >
                        Position
                    </Label>
                    <select
                        id="filter-position"
                        value={value.position_id}
                        onChange={(e) => onChange({ ...value, position_id: e.target.value })}
                        className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        {filteredPositions.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                {p.title ?? `#${p.id}`}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="filter-status"
                        className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                    >
                        Status
                    </Label>
                    <select
                        id="filter-status"
                        value={value.status}
                        onChange={(e) => onChange({ ...value, status: e.target.value })}
                        className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="on_leave">On leave</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>
            </div>
        </FiltersSheet>
    );
}

