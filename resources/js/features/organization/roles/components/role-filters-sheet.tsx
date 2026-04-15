import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';

export type RoleFilters = {
    has_permissions: '' | 'true' | 'false';
};

export function RoleFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: RoleFilters;
    onChange: (next: RoleFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label
                        htmlFor="filter-has-permissions"
                        className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                    >
                        Permissions
                    </Label>
                    <select
                        id="filter-has-permissions"
                        value={value.has_permissions}
                        onChange={(e) =>
                            onChange({
                                ...value,
                                has_permissions: e.target.value as RoleFilters['has_permissions'],
                            })
                        }
                        className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="true">Has permissions</option>
                        <option value="false">No permissions</option>
                    </select>
                </div>
            </div>
        </FiltersSheet>
    );
}

