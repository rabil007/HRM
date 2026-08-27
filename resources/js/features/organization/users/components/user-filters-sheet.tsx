import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';

export type UserFilters = {
    status: '' | 'active' | 'inactive' | 'suspended';
    role_id: string;
    presence: '' | 'online' | 'recent' | 'offline' | 'never';
};

export function UserFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
    roles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: UserFilters;
    onChange: (next: UserFilters) => void;
    onReset: () => void;
    roles: { id: number; name: string }[];
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-4">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) =>
                            onChange({
                                ...value,
                                status: v as UserFilters['status'],
                            })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="active">Active</AppSelectItem>
                        <AppSelectItem value="inactive">Inactive</AppSelectItem>
                        <AppSelectItem value="suspended">
                            Suspended
                        </AppSelectItem>
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Role
                    </Label>
                    <AppSelect
                        value={value.role_id}
                        onValueChange={(v) =>
                            onChange({ ...value, role_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {roles.map((r) => (
                            <AppSelectItem key={r.id} value={String(r.id)}>
                                {r.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Presence
                    </Label>
                    <AppSelect
                        value={value.presence}
                        onValueChange={(v) =>
                            onChange({
                                ...value,
                                presence: v as UserFilters['presence'],
                            })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="online">Online Now (≤5m)</AppSelectItem>
                        <AppSelectItem value="recent">Recently Active (≤30m)</AppSelectItem>
                        <AppSelectItem value="offline">Offline (&gt;30m)</AppSelectItem>
                        <AppSelectItem value="never">Never Logged In</AppSelectItem>
                    </AppSelect>
                </div>
            </div>
        </FiltersSheet>
    );
}
