import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';

export type UserFilters = {
    status: '' | 'active' | 'inactive' | 'suspended';
};

export function UserFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: UserFilters;
    onChange: (next: UserFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Status
                </Label>
                <AppSelect
                    value={value.status}
                    onValueChange={(v) => onChange({ ...value, status: v as UserFilters['status'] })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    <AppSelectItem value="active">Active</AppSelectItem>
                    <AppSelectItem value="inactive">Inactive</AppSelectItem>
                    <AppSelectItem value="suspended">Suspended</AppSelectItem>
                </AppSelect>
            </div>
        </FiltersSheet>
    );
}
