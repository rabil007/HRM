import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Country, Currency } from '../types';

export type CompanyFilters = {
    industry: string;
    country: string;
    currency: string;
    hasLogo: boolean;
    hasEmail: boolean;
    hasWebsite: boolean;
};

export function CompanyFiltersSheet({
    open,
    onOpenChange,
    countries,
    currencies,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    countries: Country[];
    currencies: Currency[];
    value: CompanyFilters;
    onChange: (next: CompanyFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label htmlFor="filter-industry" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Industry
                    </Label>
                    <Input
                        id="filter-industry"
                        placeholder="e.g. Retail"
                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                        value={value.industry}
                        onChange={(e) => onChange({ ...value, industry: e.target.value })}
                    />
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
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
                            <AppSelectItem key={country.id} value={country.code}>
                                {country.code} {country.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Currency
                </Label>
                <AppSelect
                    value={value.currency}
                    onValueChange={(v) => onChange({ ...value, currency: v })}
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {currencies.map((currency) => (
                        <AppSelectItem key={currency.id} value={currency.code}>
                            {currency.code} {currency.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-3">
                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Flags</div>

                <div className="flex items-center gap-3">
                    <Checkbox checked={value.hasLogo} onCheckedChange={(checked) => onChange({ ...value, hasLogo: checked === true })} />
                    <span className="text-sm text-muted-foreground">Has logo</span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox checked={value.hasEmail} onCheckedChange={(checked) => onChange({ ...value, hasEmail: checked === true })} />
                    <span className="text-sm text-muted-foreground">Has email</span>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        checked={value.hasWebsite}
                        onCheckedChange={(checked) => onChange({ ...value, hasWebsite: checked === true })}
                    />
                    <span className="text-sm text-muted-foreground">Has website</span>
                </div>
            </div>
        </FiltersSheet>
    );
}
