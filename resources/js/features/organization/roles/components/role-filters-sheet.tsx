import { AppSelect, AppSelectItem } from '@/components/app-select';
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Permissions
                    </Label>
                    <AppSelect
                        value={value.has_permissions}
                        onValueChange={(v) => onChange({ ...value, has_permissions: v as RoleFilters['has_permissions'] })}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="true">Has permissions</AppSelectItem>
                        <AppSelectItem value="false">No permissions</AppSelectItem>
                    </AppSelect>
                </div>
            </div>
        </FiltersSheet>
    );
}
