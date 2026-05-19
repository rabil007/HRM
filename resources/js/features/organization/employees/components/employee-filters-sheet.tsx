import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type { BranchOption, PositionOption } from '../types';

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
    positions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: EmployeeFilters;
    onChange: (next: EmployeeFilters) => void;
    onReset: () => void;
    branches: BranchOption[];
    positions: PositionOption[];
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                        {branches.map((b) => (
                            <AppSelectItem key={b.id} value={String(b.id)}>
                                {b.name ?? `#${b.id}`}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Position
                    </Label>
                    <AppSelect
                        value={value.position_id}
                        onValueChange={(v) => onChange({ ...value, position_id: v })}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {positions.map((p) => (
                            <AppSelectItem key={p.id} value={String(p.id)}>
                                {p.title ?? `#${p.id}`}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) => onChange({ ...value, status: v })}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="active">Active</AppSelectItem>
                        <AppSelectItem value="inactive">Inactive</AppSelectItem>
                        <AppSelectItem value="on_leave">On leave</AppSelectItem>
                        <AppSelectItem value="terminated">Terminated</AppSelectItem>
                    </AppSelect>
                </div>
            </div>
        </FiltersSheet>
    );
}
