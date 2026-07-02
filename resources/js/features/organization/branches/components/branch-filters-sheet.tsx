import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Country } from '../types';

export type BranchFilters = {
    country: string;
    status: '' | 'active' | 'inactive';
    headquartersOnly: boolean;
    hasEmail: boolean;
    hasPhone: boolean;
    city: string;
};

export function BranchFiltersSheet({
    open,
    onOpenChange,
    countries,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    countries: Country[];
    value: BranchFilters;
    onChange: (next: BranchFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label
                    htmlFor="filter-country"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    Country
                </Label>
                <AppSelect
                    value={value.country}
                    onValueChange={(v) => onChange({ ...value, country: v })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {countries.map((country) => (
                        <AppSelectItem key={country.code} value={country.code}>
                            {country.code} {country.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label
                        htmlFor="filter-city"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        City
                    </Label>
                    <Input
                        id="filter-city"
                        placeholder="e.g. Dubai"
                        className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                        value={value.city}
                        onChange={(e) =>
                            onChange({ ...value, city: e.target.value })
                        }
                    />
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) =>
                            onChange({
                                ...value,
                                status: v as BranchFilters['status'],
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

            <div className="space-y-3">
                <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Flags
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        checked={value.headquartersOnly}
                        onCheckedChange={(checked) =>
                            onChange({
                                ...value,
                                headquartersOnly: checked === true,
                            })
                        }
                    />
                    <span className="text-sm text-muted-foreground">
                        Headquarters only
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        checked={value.hasPhone}
                        onCheckedChange={(checked) =>
                            onChange({ ...value, hasPhone: checked === true })
                        }
                    />
                    <span className="text-sm text-muted-foreground">
                        Has phone
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        checked={value.hasEmail}
                        onCheckedChange={(checked) =>
                            onChange({ ...value, hasEmail: checked === true })
                        }
                    />
                    <span className="text-sm text-muted-foreground">
                        Has email
                    </span>
                </div>
            </div>
        </FiltersSheet>
    );
}
