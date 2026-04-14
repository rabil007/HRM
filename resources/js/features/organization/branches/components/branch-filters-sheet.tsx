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
                <Label htmlFor="filter-country" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Country
                </Label>
                <select
                    id="filter-country"
                    value={value.country}
                    onChange={(e) => onChange({ ...value, country: e.target.value })}
                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                >
                    <option value="">All</option>
                    {countries.map((country) => (
                        <option key={country.code} value={country.code}>
                            {country.code} {country.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label htmlFor="filter-city" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        City
                    </Label>
                    <Input
                        id="filter-city"
                        placeholder="e.g. Dubai"
                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                        value={value.city}
                        onChange={(e) => onChange({ ...value, city: e.target.value })}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="filter-status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Status
                    </Label>
                    <select
                        id="filter-status"
                        value={value.status}
                        onChange={(e) => onChange({ ...value, status: e.target.value as BranchFilters['status'] })}
                        className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div className="space-y-3">
                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Flags</div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        checked={value.headquartersOnly}
                        onCheckedChange={(checked) => onChange({ ...value, headquartersOnly: checked === true })}
                    />
                    <span className="text-sm text-muted-foreground">Headquarters only</span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox checked={value.hasPhone} onCheckedChange={(checked) => onChange({ ...value, hasPhone: checked === true })} />
                    <span className="text-sm text-muted-foreground">Has phone</span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox checked={value.hasEmail} onCheckedChange={(checked) => onChange({ ...value, hasEmail: checked === true })} />
                    <span className="text-sm text-muted-foreground">Has email</span>
                </div>
            </div>
        </FiltersSheet>
    );
}

