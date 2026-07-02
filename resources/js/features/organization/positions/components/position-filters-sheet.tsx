import { AppSelect, AppSelectItem } from '@/components/app-select';
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
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) =>
                            onChange({
                                ...value,
                                status: v as PositionFilters['status'],
                            })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="active">Active</AppSelectItem>
                        <AppSelectItem value="inactive">Inactive</AppSelectItem>
                    </AppSelect>
                </div>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Department
                </Label>
                <AppSelect
                    value={value.department_id}
                    onValueChange={(v) =>
                        onChange({ ...value, department_id: v })
                    }
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {availableDepartments.map((d) => (
                        <AppSelectItem key={d.id} value={String(d.id)}>
                            {d.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="filter-grade"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    Grade
                </Label>
                <Input
                    id="filter-grade"
                    placeholder="e.g. G5"
                    className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                    value={value.grade}
                    onChange={(e) =>
                        onChange({ ...value, grade: e.target.value })
                    }
                />
            </div>
        </FiltersSheet>
    );
}
